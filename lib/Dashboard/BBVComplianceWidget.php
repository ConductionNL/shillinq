<?php

/**
 * BBV Compliance Widget Envelope Builder.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Dashboard;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Shillinq\Service\ComplianceService;
use OCA\Shillinq\Service\SettingsService;
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
	 * Construct the widget with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ComplianceService $compliance Compliance envelope source.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ComplianceService $compliance,
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

		$rows = [];
		$totalBudget = 0;
		$totalYtdSpend = 0;
		$counts = [
			'unconfigured' => 0,
			'on-track' => 0,
			'at-risk' => 0,
			'non-compliant' => 0,
		];

		foreach ($programmes as $programme) {
			$envelope = $this->compliance->computeComplianceStatus(
				programme: $programme,
				fiscalYear: $fiscalYear
			);
			$status = $envelope['status'];
			if (array_key_exists($status, $counts) === false) {
				$counts[$status] = 0;
			}

			$counts[$status]++;
			$totalBudget += $envelope['budget'];
			$totalYtdSpend += $envelope['ytdSpend'];

			$rows[] = ($programme + [
				'totalBudget' => $envelope['budget'],
				'ytdSpend' => $envelope['ytdSpend'],
				'utilization' => $envelope['utilization'],
				'complianceStatus' => $envelope['status'],
			]);
		}//end foreach

		$summaryUtilization = 0.0;
		if ($totalBudget > 0) {
			$summaryUtilization = ((float)$totalYtdSpend / (float)$totalBudget);
		}

		$summary = [
			'programmeCount' => count($rows),
			'mappingCount' => count($mappings),
			'totalBudget' => $totalBudget,
			'totalYtdSpend' => $totalYtdSpend,
			'utilization' => $summaryUtilization,
		];

		return [
			'widgets' => $this->widgetDeclarations(),
			'programmes' => $rows,
			'mappings' => $mappings,
			'counts' => $counts,
			'summary' => $summary,
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
		];

	}//end buildEnvelope()

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
		return $this->findAllRows(
			schema: 'BBVProgramme',
			filters: $this->scopedFilters(
				base: ['status' => 'active'],
				fiscalYear: $fiscalYear,
				administrationId: $administrationId
			)
		);

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
		return $this->findAllRows(
			schema: 'BudgetBBVMapping',
			filters: $this->scopedFilters(
				base: [],
				fiscalYear: null,
				administrationId: $administrationId
			)
		);

	}//end loadMappings()

	/**
	 * Build a filters map, dropping null scopes.
	 *
	 * @param array<string,mixed> $base Base filters.
	 * @param int|null $fiscalYear Optional fiscal year.
	 * @param string|null $administrationId Optional administration.
	 *
	 * @return array<string,mixed>
	 */
	private function scopedFilters(array $base, ?int $fiscalYear, ?string $administrationId): array {
		if ($fiscalYear !== null) {
			$base['fiscalYear'] = $fiscalYear;
		}

		if ($administrationId !== null && $administrationId !== '') {
			$base['administrationId'] = $administrationId;
		}

		return $base;
	}//end scopedFilters()

	/**
	 * FindAll wrapper that returns plain arrays.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters OR filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAllRows(string $schema, array $filters): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema($schema)
				->findAll(
					[
						'filters' => $filters,
						'limit' => 1000,
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq BBV compliance widget: register lookup failed',
				[
					'schema' => $schema,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

		$rows = [];
		foreach ($records as $record) {
			$rows[] = $this->toArray(object: $record);
		}

		return $rows;
	}//end findAllRows()

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

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$payload = $object->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$payload = $object->jsonSerialize();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()
}//end class
