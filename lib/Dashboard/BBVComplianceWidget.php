<?php

/**
 * BBV Compliance Widget Envelope Builder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 08 of the `bookkeeping-waterschappen-bbv-variant` chain
 * (ADR-032). Assembles the per-programme widget envelope the slice-05
 * dashboard widgets (`BBVKPICards`, `BBVComplianceChart`,
 * `BBVTrendChart`, `BBVProgrammeTable`) bind to.
 *
 * Why is this not just the controller? The slice-04 dashboard route
 * controller (`BBVDashboardController`) owns transport (auth attribute,
 * JSONResponse, HTTP status, anonymous-rejection guard) per ADR-016 +
 * hydra-gate-route-auth. This widget object owns the envelope SHAPE
 * (counts, status buckets, table rows) — splitting the two keeps each
 * piece testable in isolation and lets a future widget renderer or
 * scheduled-export pipeline reuse the same envelope.
 *
 * The shape follows the JSON the slice-05 dashboard consumes via
 * `GET /apps/openregister/api/objects/shillinq/BBVProgramme` today:
 * an array of programme rows carrying the materialised aggregation
 * fields. Switching the dashboard to `GET /apps/shillinq/bbv-dashboard`
 * therefore needs no widget refactor — slice 05 already binds against
 * `programmes[]` + a top-level `timeline[]` array.
 *
 * @category Dashboard
 * @package  OCA\Shillinq\Dashboard
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pure envelope assembler — does not own the HTTP boundary.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
final class BBVComplianceWidget {
	/**
	 * The status buckets every envelope starts with, in display order.
	 *
	 * @var array<string,int>
	 */
	private const EMPTY_COUNTS = [
		'unconfigured' => 0,
		'on-track' => 0,
		'at-risk' => 0,
		'non-compliant' => 0,
	];

	/**
	 * Construct the widget with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param ComplianceServiceInterface $compliance Compliance envelope source.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RegisterLocation $registerLocation,
		private readonly ComplianceServiceInterface $compliance,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the dashboard JSON envelope for the BBV compliance page.
	 *
	 * Queries the BBVProgramme + BudgetBBVMapping registers via OR
	 * ObjectService (real find/findAll API — see
	 * [[or-objectservice-api]]), reads the slice-02 materialised
	 * aggregation fields off each programme record, and assembles:
	 *
	 *   - `programmes[]`  — full programme rows (status buckets + table)
	 *   - `mappings[]`    — BudgetBBVMapping rows (used by the detail
	 *                       drill-down and the chart hover panel)
	 *   - `counts`        — status-bucket histogram (pie chart)
	 *   - `summary`       — totals across the active fiscal year
	 *   - `widgets[]`     — the slice-05 widget declarations
	 *   - `generatedAt`   — UTC ISO-8601 timestamp
	 *
	 * @param int|null $fiscalYear Optional fiscal-year filter.
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return array{
	 *   widgets: array<int,array<string,mixed>>,
	 *   programmes: array<int,array<string,mixed>>,
	 *   mappings: array<int,array<string,mixed>>,
	 *   counts: array<string,int>,
	 *   summary: array<string,int|float>,
	 *   generatedAt: string
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function buildEnvelope(?int $fiscalYear = null, ?string $administrationId = null): array {
		$programmes = $this->loadProgrammes(fiscalYear: $fiscalYear, administrationId: $administrationId);
		$mappings = $this->loadMappings(fiscalYear: $fiscalYear, administrationId: $administrationId);
		$tally = $this->programmeTally(programmes: $programmes, fiscalYear: $fiscalYear);

		return [
			'widgets' => $this->widgetDeclarations(),
			'programmes' => $tally['rows'],
			'mappings' => $mappings,
			'counts' => $tally['counts'],
			'summary' => self::summary(tally: $tally, mappingCount: count($mappings)),
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
		];
	}//end buildEnvelope()

	/**
	 * Each programme with its compliance figures, the status histogram and the totals.
	 *
	 * @param array<int,array<string,mixed>> $programmes The programme rows.
	 * @param int|null                       $fiscalYear Optional fiscal year passed to the compliance service.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, counts: array<string,int>, totalBudget: int|float, totalYtdSpend: int|float}
	 */
	private function programmeTally(array $programmes, ?int $fiscalYear): array {
		$tally = ['rows' => [], 'counts' => self::EMPTY_COUNTS, 'totalBudget' => 0, 'totalYtdSpend' => 0];
		foreach ($programmes as $programme) {
			$envelope = $this->compliance->computeComplianceStatus(programme: $programme, fiscalYear: $fiscalYear);
			$tally = self::counted(tally: $tally, envelope: $envelope);
			$tally['rows'][] = ($programme + [
				'totalBudget' => $envelope['budget'],
				'ytdSpend' => $envelope['ytdSpend'],
				'utilization' => $envelope['utilization'],
				'complianceStatus' => $envelope['status'],
			]);
		}

		return $tally;
	}//end programmeTally()

	/**
	 * The tally with one more programme's status and amounts added.
	 *
	 * @param array<string,mixed> $tally    The tally so far.
	 * @param array<string,mixed> $envelope The programme's compliance envelope.
	 *
	 * @return array<string,mixed>
	 */
	private static function counted(array $tally, array $envelope): array {
		$status = $envelope['status'];
		$tally['counts'][$status] = (($tally['counts'][$status] ?? 0) + 1);
		$tally['totalBudget'] += $envelope['budget'];
		$tally['totalYtdSpend'] += $envelope['ytdSpend'];

		return $tally;
	}//end counted()

	/**
	 * The totals across the programmes; utilization is spend over budget, 0 without a budget.
	 *
	 * @param array<string,mixed> $tally        The programme tally.
	 * @param int                 $mappingCount The number of mappings.
	 *
	 * @return array<string,int|float>
	 */
	private static function summary(array $tally, int $mappingCount): array {
		$summaryUtilization = 0.0;
		if ($tally['totalBudget'] > 0) {
			$summaryUtilization = ((float)$tally['totalYtdSpend'] / (float)$tally['totalBudget']);
		}

		return [
			'programmeCount' => count($tally['rows']),
			'mappingCount' => $mappingCount,
			'totalBudget' => $tally['totalBudget'],
			'totalYtdSpend' => $tally['totalYtdSpend'],
			'utilization' => $summaryUtilization,
		];
	}//end summary()

	/**
	 * Slice-05 widget declarations — kept identical to the Vue dashboard
	 * so a future server-rendered shell can reuse them without forking.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function widgetDeclarations(): array {
		return [
			['id' => 'bbv-kpis', 'title' => 'Key compliance metrics', 'type' => 'custom'],
			['id' => 'bbv-pie', 'title' => 'Compliance status distribution', 'type' => 'custom'],
			['id' => 'bbv-trend', 'title' => 'YTD cumulative spend per programme', 'type' => 'custom'],
			['id' => 'bbv-table', 'title' => 'Programme utilization', 'type' => 'custom'],
		];

	}//end widgetDeclarations()

	/**
	 * Load BBVProgramme rows from OpenRegister.
	 *
	 * Empty list when OR is not available — the controller still returns
	 * a well-formed envelope, just with zero programmes.
	 *
	 * @param int|null $fiscalYear Optional fiscal-year filter.
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loadProgrammes(?int $fiscalYear, ?string $administrationId): array {
		$filters = self::withFiscalYear(filters: ['status' => 'active'], fiscalYear: $fiscalYear);

		return $this->findAllRows(schema: 'BBVProgramme', filters: self::withAdministration(filters: $filters, administrationId: $administrationId));
	}//end loadProgrammes()

	/**
	 * Load BudgetBBVMapping rows from OpenRegister.
	 *
	 * The `$fiscalYear` parameter is intentionally accepted but not
	 * applied at this layer: BudgetBBVMapping has no fiscalYear field;
	 * the year scope is applied client-side by the dashboard against
	 * effectiveFrom / effectiveTo. Keeping the parameter in the
	 * signature lets slice 09 (fiscal-year scoping) tighten this
	 * without changing the public contract.
	 *
	 * @param int|null $fiscalYear Optional fiscal-year filter (matched against effectiveFrom year).
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	private function loadMappings(?int $fiscalYear, ?string $administrationId): array {
		unset($fiscalYear);
		return $this->findAllRows(schema: 'BudgetBBVMapping', filters: self::withAdministration(filters: [], administrationId: $administrationId));
	}//end loadMappings()

	/**
	 * The filters with the fiscal year added, when one is given.
	 *
	 * @param array<string,mixed> $filters    The filters so far.
	 * @param int|null            $fiscalYear Optional fiscal year.
	 *
	 * @return array<string,mixed>
	 */
	private static function withFiscalYear(array $filters, ?int $fiscalYear): array {
		if ($fiscalYear !== null) {
			$filters['fiscalYear'] = $fiscalYear;
		}

		return $filters;
	}//end withFiscalYear()

	/**
	 * The filters with the administration added, when a non-empty one is given.
	 *
	 * @param array<string,mixed> $filters          The filters so far.
	 * @param string|null         $administrationId Optional administration.
	 *
	 * @return array<string,mixed>
	 */
	private static function withAdministration(array $filters, ?string $administrationId): array {
		if ($administrationId !== null && $administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		return $filters;
	}//end withAdministration()

	/**
	 * FindAll wrapper that returns plain arrays.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters OR filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAllRows(string $schema, array $filters): array {
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			return [];
		}

		$records = $this->lookup(schema: $schema, filters: $filters);
		$rows = [];
		foreach (($records ?? []) as $record) {
			$rows[] = $this->toArray(object: $record);
		}

		return $rows;
	}//end findAllRows()

	/**
	 * Up to 1000 records of a schema; a failed lookup is logged.
	 *
	 * @param string              $schema  OR schema slug.
	 * @param array<string,mixed> $filters OR filter map.
	 *
	 * @return array<int|string,mixed>|null The records, or null when the lookup failed.
	 */
	private function lookup(string $schema, array $filters): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->registerLocation->getRegisterSlug();
			return $objectService->setRegister($registerSlug)->setSchema($schema)->findAll(['filters' => $filters, 'limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq BBV compliance widget: register lookup failed', ['schema' => $schema, 'exception' => $e->getMessage()]);
			return null;
		}
	}//end lookup()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object Either an array or an OR entity exposing getObject().
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === false) {
			return [];
		}

		return self::objectPayload(object: $object);
	}//end toArray()

	/**
	 * An entity's payload: getObject() when it answers an array, else jsonSerialize() when it does, else [].
	 *
	 * @param object $object An OR entity.
	 *
	 * @return array<string,mixed>
	 */
	private static function objectPayload(object $object): array {
		foreach (['getObject', 'jsonSerialize'] as $method) {
			if (method_exists($object, $method) === true) {
				$payload = $object->$method();
				if (is_array($payload) === true) {
					return $payload;
				}
			}
		}

		return [];
	}//end objectPayload()
}//end class
