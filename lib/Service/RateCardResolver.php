<?php

/**
 * Rate Card Resolver
 *
 * Resolves the applicable rate for a (rateCard, resource, date) tuple from
 * the existing RateCard / RateCardVersion / RateRecord registers (Task 24,
 * issue #111). Returns the resolved rate as a snapshot so callers can persist
 * it into BillableInvoiceLine.rateApplied per design decision D2.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Look up rates from the existing RateCard family of schemas.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 */
class RateCardResolver {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a rate snapshot for a (rateCardId, resourceType, date) tuple.
	 *
	 * The lookup prefers a RateRecord matching (rateCardId, resourceType,
	 * effectiveDate <= $date, expiresAt >= $date OR null); falls back to a
	 * RateCardVersion default rate; and finally to a sensible default
	 * encoded on the RateCard itself.
	 *
	 * Tenant scope (REQ-001, ADR-005 Rule 3): $rateCardId is client-supplied,
	 * so every lookup below compounds the caller's server-resolved
	 * $administrationId into the query itself (an equality filter pair on
	 * findAll(), mirroring InvoiceGenerationService::findScoped() /
	 * GoodsReceiptNoteService::findOne()) rather than fetching by id and then
	 * checking. A rate card belonging to another administration therefore can
	 * never resolve — it is indistinguishable from an unknown id and falls
	 * through to the generic fallback, this file's pre-existing convention for
	 * an unresolvable reference.
	 *
	 * @param string $rateCardId FK to RateCard.
	 * @param string $resourceType e.g. 'senior_consultant', 'junior_consultant'.
	 * @param string $date ISO date the rate should apply on.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function resolveRate(string $rateCardId, string $resourceType, string $date, string $administrationId): array {
		// Fail closed on an absent scope: with no administration to scope to
		// there is no query that can be proven tenant-safe, so no register is
		// read at all (mirrors AdministrationContextService::canAccess(),
		// which fails closed on '').
		if ($administrationId === '') {
			$this->logger->warning(
				sprintf(
					'RateCardResolver: refusing unscoped lookup for %s/%s on %s (no administrationId), falling back to €100/hr',
					$rateCardId,
					$resourceType,
					$date
				)
			);

			return $this->fallbackRate(date: $date);
		}

		$best = $this->pickBestRecord(
			records: $this->findAll(
				schema: 'RateRecord',
				filters: [
					'rateCardId' => $rateCardId,
					'resourceType' => $resourceType,
					'administrationId' => $administrationId,
				]
			),
			date: $date
		);

		if ($best !== null) {
			return [
				'rateCents' => $this->toCents(value: $best['rateCents'] ?? $best['hourlyRate'] ?? 0),
				'currency' => (string)($best['currency'] ?? 'EUR'),
				'rateCardVersion' => (string)($best['rateCardVersion'] ?? ($best['version'] ?? 'v1')),
				'effectiveDate' => (string)($best['effectiveDate'] ?? $date),
			];
		}

		$card = $this->findRateCard(rateCardId: $rateCardId, administrationId: $administrationId);
		if ($card !== null) {
			return [
				'rateCents' => $this->toCents(value: $card['defaultHourlyRate'] ?? $card['hourlyRate'] ?? 10000),
				'currency' => (string)($card['currency'] ?? 'EUR'),
				'rateCardVersion' => (string)($card['version'] ?? 'v1'),
				'effectiveDate' => $date,
			];
		}

		$this->logger->warning(
			sprintf(
				'RateCardResolver: no rate found for %s/%s on %s, falling back to €100/hr',
				$rateCardId,
				$resourceType,
				$date
			)
		);

		return $this->fallbackRate(date: $date);

	}//end resolveRate()

	/**
	 * Pick the latest-effective RateRecord that brackets $date.
	 *
	 * Records with a future effectiveDate, or an expiresAt already past, are
	 * skipped; among the remainder the highest effectiveDate wins.
	 *
	 * @param array<int,array<string,mixed>> $records Candidate RateRecords.
	 * @param string $date ISO date the rate should apply on.
	 *
	 * @return array<string,mixed>|null The winning record, or null when none apply.
	 */
	private function pickBestRecord(array $records, string $date): ?array {
		$best = null;
		foreach ($records as $record) {
			$effective = (string)($record['effectiveDate'] ?? '');
			$expires = (string)($record['expiresAt'] ?? '');

			if ($effective !== '' && $effective > $date) {
				continue;
			}

			if ($expires !== '' && $expires < $date) {
				continue;
			}

			if ($best === null || (string)($best['effectiveDate'] ?? '') < $effective) {
				$best = $record;
			}
		}//end foreach

		return $best;
	}//end pickBestRecord()

	/**
	 * Find the RateCard itself, for simple cards that carry a default rate
	 * instead of per-resource RateRecords. The default mirrors the
	 * RateCard.defaultHourlyRate field shipped by the rate-card-engine.
	 *
	 * Both id spaces are tried (the `scheduleId` property first, then `id`),
	 * and BOTH are compounded with $administrationId so neither can reach
	 * another tenant's card.
	 *
	 * @param string $rateCardId FK to RateCard.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array<string,mixed>|null The card, or null when none is in scope.
	 */
	private function findRateCard(string $rateCardId, string $administrationId): ?array {
		$rateCards = $this->findAll(
			schema: 'RateCard',
			filters: [
				'scheduleId' => $rateCardId,
				'administrationId' => $administrationId,
			]
		);

		if (count($rateCards) === 0) {
			$rateCards = $this->findAll(
				schema: 'RateCard',
				filters: [
					'id' => $rateCardId,
					'administrationId' => $administrationId,
				]
			);
		}

		if (count($rateCards) === 0) {
			return null;
		}

		return $rateCards[0];
	}//end findRateCard()

	/**
	 * The generic €100/hr snapshot returned when no rate resolves.
	 *
	 * Shared by the "nothing matched" path and the "no administration scope"
	 * fail-closed path so a cross-tenant / unscoped id is indistinguishable
	 * from an unknown one.
	 *
	 * @param string $date ISO date the rate applies on.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
	 */
	private function fallbackRate(string $date): array {
		return [
			'rateCents' => 10000,
			'currency' => 'EUR',
			'rateCardVersion' => 'fallback',
			'effectiveDate' => $date,
		];
	}//end fallbackRate()

	/**
	 * Convert a stored money value to integer cents.
	 *
	 * Accepts a stored number-of-euros (multipleOf 0.01) or already-integer cents.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return int Cents.
	 */
	private function toCents(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		return (int)round((float)$value * 100);
	}//end toCents()

	/**
	 * Find all matching records via the real OR ObjectService API (findAll).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rs = $svc
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);

			if (is_array($rs) === true) {
				return $rs;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->error('RateCardResolver findAll failed: ' . $e->getMessage());
			return [];
		}

	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
