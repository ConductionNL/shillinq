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
		$query = new RateQuery();
		$query->rateCardId = $rateCardId;
		$query->resourceType = $resourceType;
		$query->date = $date;
		$query->administrationId = $administrationId;

		// Fail closed on an absent scope: with no administration to scope to
		// there is no query that can be proven tenant-safe, so no register is
		// read at all (mirrors AdministrationContextService::canAccess(),
		// which fails closed on '').
		if ($administrationId === '') {
			return $this->fallbackRate(query: $query, reason: 'refusing unscoped lookup for %s/%s on %s (no administrationId)');
		}

		return ($this->scopedRate(query: $query) ?? $this->fallbackRate(query: $query, reason: 'no rate found for %s/%s on %s'));

	}//end resolveRate()

	/**
	 * The rate from the newest applicable RateRecord, else from the RateCard
	 * default, both looked up within the caller's administration; null when
	 * neither resolves.
	 *
	 * @param RateQuery $query The scoped lookup.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}|null
	 */
	private function scopedRate(RateQuery $query): ?array {
		$filters = ['rateCardId' => $query->rateCardId, 'resourceType' => $query->resourceType, 'administrationId' => $query->administrationId];
		$records = $this->findAll(schema: 'RateRecord', filters: $filters);
		$best = (new EffectiveVersionPicker(endField: 'expiresAt'))->pick(records: $records, date: $query->date);
		if ($best !== null) {
			return $this->recordRate(record: $best, date: $query->date);
		}

		$card = $this->findRateCard(rateCardId: $query->rateCardId, administrationId: $query->administrationId);
		if ($card !== null) {
			return $this->cardRate(card: $card, date: $query->date);
		}

		return null;

	}//end scopedRate()

	/**
	 * The rate snapshot of a RateRecord.
	 *
	 * @param array<string,mixed> $record The winning RateRecord.
	 * @param string              $date   ISO date the rate applies on.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
	 */
	private function recordRate(array $record, string $date): array {
		return [
			'rateCents' => (new StoredMoney())->cents(value: $record['rateCents'] ?? $record['hourlyRate'] ?? 0),
			'currency' => (string)($record['currency'] ?? 'EUR'),
			'rateCardVersion' => (string)($record['rateCardVersion'] ?? ($record['version'] ?? 'v1')),
			'effectiveDate' => (string)($record['effectiveDate'] ?? $date),
		];

	}//end recordRate()

	/**
	 * The rate snapshot of a RateCard's default rate.
	 *
	 * @param array<string,mixed> $card The RateCard.
	 * @param string              $date ISO date the rate applies on.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
	 */
	private function cardRate(array $card, string $date): array {
		return [
			'rateCents' => (new StoredMoney())->cents(value: $card['defaultHourlyRate'] ?? $card['hourlyRate'] ?? 10000),
			'currency' => (string)($card['currency'] ?? 'EUR'),
			'rateCardVersion' => (string)($card['version'] ?? 'v1'),
			'effectiveDate' => $date,
		];

	}//end cardRate()

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
		$rateCards = $this->findAll(schema: 'RateCard', filters: ['scheduleId' => $rateCardId, 'administrationId' => $administrationId]);
		if (count($rateCards) === 0) {
			$rateCards = $this->findAll(schema: 'RateCard', filters: ['id' => $rateCardId, 'administrationId' => $administrationId]);
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
	 * Logs why, then answers the generic snapshot.
	 *
	 * @param RateQuery $query  The lookup that did not resolve.
	 * @param string    $reason Why, as a sprintf format over card, resource type and date.
	 *
	 * @return array{rateCents:int,currency:string,rateCardVersion:string,effectiveDate:string}
	 */
	private function fallbackRate(RateQuery $query, string $reason): array {
		$this->logger->warning(
			sprintf('RateCardResolver: ' . $reason . ', falling back to €100/hr', $query->rateCardId, $query->resourceType, $query->date)
		);

		return [
			'rateCents' => 10000,
			'currency' => 'EUR',
			'rateCardVersion' => 'fallback',
			'effectiveDate' => $query->date,
		];
	}//end fallbackRate()

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
			return (new RegisterRecordFinder(container: $this->container, appConfig: $this->appConfig))->find(schema: $schema, filters: $filters);
		} catch (\Throwable $e) {
			$this->logger->error('RateCardResolver findAll failed: ' . $e->getMessage());
			return [];
		}

	}//end findAll()
}//end class
