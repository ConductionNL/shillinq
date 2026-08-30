<?php

/**
 * Vendor Performance Aggregation Service
 *
 * Slice 10 of the bookkeeping-purchase-order-3way chain (REQ-PO3W-008 /
 * REQ-VP-001 / REQ-VP-005). Computes the monthly vendor scorecard per
 * (supplierId, period, administrationId) by aggregating the
 * GoodsReceiptNote + ThreeWayMatch + SupplierInvoice history that the
 * upstream slices (04 / 06 / 08 / 09) have laid down.
 *
 * Per-period rates (all stored as basis points, 1/10000, so 9800 = 98.00 %):
 *
 *  - onTimeDeliveryRate    = (GRNs received on or before the originating
 *    PO's expectedDeliveryDate) / (total GRNs scored within the period).
 *  - quantityAccuracyRate  = (GRN lines where qtyReceived == qtyOrdered
 *    on the linked PurchaseOrderLine) / (total GRN lines scored).
 *  - priceAccuracyRate     = (ThreeWayMatch records that landed
 *    auto_approved or within_tolerance) / (total ThreeWayMatch records).
 *  - invoiceAccuracyRate   = (SupplierInvoices that matched first try =
 *    auto_approved on the first ThreeWayMatch evaluated for the invoice
 *    in the period) / (total SupplierInvoices evaluated in the period).
 *
 * overallScore = weighted basis-point average:
 *     40 % onTime + 30 % quantity + 20 % price + 10 % invoice
 * Computed as integer basis points and clamped to 0..10000 — never
 * float-rounded so the period-over-period delta is exact.
 *
 * scoreTrend is computed against the immediately-preceding period's
 * VendorPerformance scorecard for the same supplier + administration —
 * the trend buckets are:
 *  - improving  when overallScore - prior >= +50 basis points (0.50 pp)
 *  - declining  when overallScore - prior <= -50 basis points
 *  - stable     otherwise (or when there is no prior period at all)
 *
 * Auto-review eligibility (REQ-VP-005): a supplier whose overallScore is
 * 9600 basis points (96.00 %) or higher and whose first scorecard for
 * the administration is at least 90 days old (the bootstrap window,
 * design D5 Risk-2 mitigation) is flagged automatedReviewEligible=TRUE.
 * The bootstrap window prevents brand-new suppliers with one near-perfect
 * receipt from being auto-elevated. When eligible the service MAY relax
 * the supplier's ToleranceProfile (via {@see autoRelaxToleranceProfile()})
 * — this is a no-op when no supplier-scoped profile exists, in which case
 * the controller workflow (slice 08) is responsible for surfacing the
 * relaxation proposal.
 *
 * disputeCount counts the ThreeWayMatch records with resolutionAction =
 * `credit_note_requested` or `supplier_contacted` raised by slice 08's
 * ExceptionResolutionService inside the period — these are the
 * supplier-facing disputes (REQ-VP-001) and are distinct from the broader
 * exception_* match counts that surface in the index. averageResolutionDays
 * is the average (createdAt → resolvedAt) days for resolved
 * ThreeWayMatch records in the period; 0 when no records resolved.
 *
 * All reads/writes go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject — `findObject` / `createFromArray` /
 * `deleteFromId` do NOT exist, see [[or-objectservice-api]]). Money fields
 * follow ADR-022 integer-cent arithmetic; quantities follow ADR-022's
 * integer-thousandths convention so a "100.000 == 100.000" equality check
 * stays bit-exact across the receipt + order boundary.
 *
 * Tenant isolation (ADR-005): every aggregation read is scoped to a
 * caller-supplied administrationId; the cron entry point loops over the
 * accessible administrations via the system context and never trusts a
 * client-supplied scope. The scorecard write is likewise scoped to the
 * source administration so cross-tenant VendorPerformance pollution is
 * impossible.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 10 of bookkeeping-purchase-order-3way: monthly vendor performance
 * aggregation + auto-review eligibility.
 *
 * Public methods:
 *  - calculateMonthlyScore(): aggregate one (supplierId, period,
 *    administrationId) tuple and persist a VendorPerformance scorecard.
 *  - setAutoReviewEligible(): server-authoritative eligibility flag —
 *    threshold + bootstrap window are enforced here, not by the caller.
 *  - autoRelaxToleranceProfile(): nudge the supplier's ToleranceProfile
 *    one step more permissive when eligible (no-op without a profile).
 *  - aggregateAdministrationForPeriod(): cron entry point — loops every
 *    supplier with activity in the period and writes one scorecard each.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Aggregation touches
 * five registers (PurchaseOrder, PurchaseOrderLine, GoodsReceiptNote,
 * GoodsReceiptLine, ThreeWayMatch, SupplierInvoice, ToleranceProfile,
 * VendorPerformance) by design.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Score derivation has
 * four distinct rate computations + trend + eligibility.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): deferred pending a dedicated refactor
 * and rename pass.
 */
class VendorPerformanceAggregation {

	/**
	 * On-time-delivery weight in basis points (40 %).
	 *
	 * @var int
	 */
	public const WEIGHT_ON_TIME = 4000;

	/**
	 * Quantity-accuracy weight in basis points (30 %).
	 *
	 * @var int
	 */
	public const WEIGHT_QUANTITY = 3000;

	/**
	 * Price-accuracy weight in basis points (20 %).
	 *
	 * @var int
	 */
	public const WEIGHT_PRICE = 2000;

	/**
	 * Invoice-accuracy weight in basis points (10 %).
	 *
	 * @var int
	 */
	public const WEIGHT_INVOICE = 1000;

	/**
	 * Auto-review eligibility threshold (96.00 % expressed as basis points).
	 *
	 * @var int
	 */
	public const ELIGIBILITY_THRESHOLD_BP = 9600;

	/**
	 * Bootstrap window in days — design D5 Risk-2 mitigation. New
	 * suppliers (first scorecard younger than this) do NOT earn
	 * automated_review_eligible even when the score crosses the
	 * threshold.
	 *
	 * @var int
	 */
	public const BOOTSTRAP_DAYS = 90;

	/**
	 * Trend bucket boundary in basis points (0.50 percentage points).
	 *
	 * @var int
	 */
	public const TREND_BUCKET_BP = 50;

	/**
	 * Maximum rate in basis points (100.00 %).
	 *
	 * @var int
	 */
	private const MAX_BP = 10000;

	/**
	 * Schema slugs (slice 01). Constants so a schema rename only changes
	 * one place and so the slug list stays grep-able.
	 */
	private const SCHEMA_PURCHASE_ORDER = 'PurchaseOrder';
	private const SCHEMA_PO_LINE = 'PurchaseOrderLine';
	private const SCHEMA_GRN = 'GoodsReceiptNote';
	private const SCHEMA_GRN_LINE = 'GoodsReceiptLine';
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';
	private const SCHEMA_TOLERANCE_PROFILE = 'ToleranceProfile';
	private const SCHEMA_VENDOR_PERFORMANCE = 'VendorPerformance';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Compute and persist the monthly VendorPerformance scorecard for
	 * one (supplierId, period, administrationId) tuple.
	 *
	 * Period format: `YYYY-MM` (e.g. `2026-05`). Quarter-format
	 * (`YYYY-Qn`) is accepted for forward-compatibility with the schema's
	 * example field but the monthly cron always passes the YYYY-MM form.
	 *
	 * Idempotent: re-running for the same tuple updates the existing
	 * scorecard in place when one exists; the scorecard's id is reused
	 * so the trend pointer in the next period's run still resolves.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId Supplier (vendor) id.
	 * @param string $period Period code (YYYY-MM or YYYY-Qn).
	 *
	 * @return array<string,mixed> The persisted scorecard.
	 *
	 * @throws \RuntimeException When the period is malformed.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function calculateMonthlyScore(string $administrationId, string $supplierId, string $period): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($supplierId === '') {
			throw new RuntimeException('supplierId is required');
		}

		$window = $this->resolvePeriodWindow(period: $period);

		// Pull supplier-scoped fact tables for the period window.
		$purchaseOrders = $this->findAll(
			schema: self::SCHEMA_PURCHASE_ORDER,
			filters: ['supplierId' => $supplierId, 'administrationId' => $administrationId]
		);
		$poIds = [];
		foreach ($purchaseOrders as $po) {
			$id = $this->idOf(row: $po);
			if ($id !== '') {
				$poIds[$id] = $po;
			}
		}

		$supplierInvoices = $this->findAll(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: ['supplierId' => $supplierId, 'administrationId' => $administrationId]
		);

		// Filter the GRN + Match + Invoice rows to the period window using
		// the receivedAt / createdAt / invoiceDate timestamps respectively.
		$periodGrns = $this->filterByDate(
			rows: $this->findAll(
				schema: self::SCHEMA_GRN,
				filters: ['administrationId' => $administrationId]
			),
			field: 'receivedAt',
			from:  $window['from'],
			to:    $window['to']
		);
		// Constrain GRNs to the supplier's POs.
		$periodGrns = array_values(
			array_filter(
				$periodGrns,
				static function (array $grn) use ($poIds): bool {
					foreach ((array)($grn['poIds'] ?? []) as $poId) {
						if (isset($poIds[(string)$poId]) === true) {
							return true;
						}
					}

					return false;
				}
			)
		);

		$periodInvoices = $this->filterByDate(
			rows:  $supplierInvoices,
			field: 'invoiceDate',
			from:  $window['from'],
			to:    $window['to']
		);

		$invoiceIds = [];
		foreach ($periodInvoices as $invoice) {
			$id = $this->idOf(row: $invoice);
			if ($id !== '') {
				$invoiceIds[$id] = true;
			}
		}

		$allMatches = $this->findAll(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			filters: ['administrationId' => $administrationId]
		);
		$periodMatches = $this->filterByDate(
			rows:  $allMatches,
			field: 'createdAt',
			from:  $window['from'],
			to:    $window['to']
		);
		// Constrain matches to the supplier's invoices.
		$periodMatches = array_values(
			array_filter(
				$periodMatches,
				static function (array $match) use ($invoiceIds): bool {
					$invoiceId = (string)($match['invoiceId'] ?? '');
					if ($invoiceId === '') {
						return false;
					}

					return isset($invoiceIds[$invoiceId]);
				}
			)
		);

		// Sub-aggregations.
		$onTimeRateBp = $this->computeOnTimeDeliveryRate(grns: $periodGrns, poIds: $poIds);
		$qtyRateBp = $this->computeQuantityAccuracyRate(grns: $periodGrns, administrationId: $administrationId);
		$priceRateBp = $this->computePriceAccuracyRate(matches: $periodMatches);
		$invoiceRateBp = $this->computeInvoiceAccuracyRate(matches: $periodMatches, invoiceIds: $invoiceIds);

		$overallBp = $this->weightedOverall(
			onTimeBp:  $onTimeRateBp,
			qtyBp:     $qtyRateBp,
			priceBp:   $priceRateBp,
			invoiceBp: $invoiceRateBp
		);

		// Disputes (REQ-VP-001) + average resolution days.
		$disputeCount = $this->countDisputes(matches: $periodMatches);
		$avgResolutionDays = $this->averageResolutionDays(matches: $periodMatches);

		// Trend vs the prior period.
		$priorPeriod = $this->priorPeriodOf(period: $period);
		$priorCard = $this->findOne(
			schema:  self::SCHEMA_VENDOR_PERFORMANCE,
			filters: [
				'supplierId' => $supplierId,
				'period' => $priorPeriod,
				'administrationId' => $administrationId,
			]
		);
		$scoreTrend = $this->computeScoreTrend(currentBp: $overallBp, priorCard: $priorCard);

		$scorecard = [
			'supplierId' => $supplierId,
			'period' => $period,
			'onTimeDeliveryRate' => $onTimeRateBp,
			'quantityAccuracyRate' => $qtyRateBp,
			'priceAccuracyRate' => $priceRateBp,
			'invoiceAccuracyRate' => $invoiceRateBp,
			'disputeCount' => $disputeCount,
			'averageResolutionDays' => $avgResolutionDays,
			'overallScore' => $overallBp,
			'scoreTrend' => $scoreTrend,
			'automatedReviewEligible' => false,
			'administrationId' => $administrationId,
		];

		// Reuse the prior period-specific scorecard's id when re-running.
		$existing = $this->findOne(
			schema:  self::SCHEMA_VENDOR_PERFORMANCE,
			filters: [
				'supplierId' => $supplierId,
				'period' => $period,
				'administrationId' => $administrationId,
			]
		);
		if ($existing !== null && isset($existing['id']) === true) {
			$scorecard['id'] = (string)$existing['id'];
		}

		// Eligibility — server-authoritative.
		$scorecard['automatedReviewEligible'] = $this->setAutoReviewEligible(
			overallScoreBp:   $overallBp,
			administrationId: $administrationId,
			supplierId:       $supplierId
		);

		$saved = $this->saveObject(schema: self::SCHEMA_VENDOR_PERFORMANCE, object: $scorecard);

		if ($scorecard['automatedReviewEligible'] === true) {
			try {
				$this->autoRelaxToleranceProfile(
					administrationId: $administrationId,
					supplierId:       $supplierId
				);
			} catch (Throwable $e) {
				$this->logger->info(
					'VendorPerformanceAggregation: tolerance relaxation skipped',
					['supplierId' => $supplierId, 'exception' => $e->getMessage()]
				);
			}
		}

		return $saved;
	}//end calculateMonthlyScore()

	/**
	 * Compute the on-time-delivery rate for the period in basis points.
	 *
	 * A GRN counts as on-time when its receivedAt date is on or before
	 * the originating PO's expectedDeliveryDate. Multi-PO GRNs use the
	 * earliest expectedDeliveryDate across the referenced POs — the
	 * strict reading of "delivered by the expected date".
	 *
	 * @param array<int,array<string,mixed>> $grns Period GRNs (already filtered).
	 * @param array<string,array<string,mixed>> $poIds Supplier POs keyed by id.
	 *
	 * @return int Basis points (0..10000). 0 when no GRN has a comparable date.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function computeOnTimeDeliveryRate(array $grns, array $poIds): int {
		$eligible = 0;
		$onTime = 0;
		foreach ($grns as $grn) {
			$receivedAt = (string)($grn['receivedAt'] ?? '');
			if ($receivedAt === '') {
				continue;
			}

			$expectedDate = $this->expectedDeliveryDateFor(grn: $grn, poIds: $poIds);
			if ($expectedDate === '') {
				continue;
			}

			$eligible++;
			if ($this->dateOnly(iso: $receivedAt) <= $expectedDate) {
				$onTime++;
			}
		}

		return $this->rateBp(numerator: $onTime, denominator: $eligible);
	}//end computeOnTimeDeliveryRate()

	/**
	 * Compute the quantity-accuracy rate for the period in basis points.
	 *
	 * A GRN line counts as accurate when its quantityReceived equals the
	 * quantityOrdered on the linked PurchaseOrderLine. Comparison is in
	 * integer thousandths to stay bit-exact across the receipt + order
	 * boundary (ADR-022).
	 *
	 * @param array<int,array<string,mixed>> $grns Period GRNs (already filtered).
	 * @param string $administrationId Administration scope.
	 *
	 * @return int Basis points (0..10000). 0 when no GRN line has a linked PO line.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function computeQuantityAccuracyRate(array $grns, string $administrationId): int {
		$eligible = 0;
		$accurate = 0;
		foreach ($grns as $grn) {
			$grnId = $this->idOf(row: $grn);
			if ($grnId === '') {
				continue;
			}

			$lines = $this->findAll(
				schema:  self::SCHEMA_GRN_LINE,
				filters: ['grnId' => $grnId, 'administrationId' => $administrationId]
			);
			foreach ($lines as $line) {
				$poLineId = (string)($line['poLineId'] ?? '');
				if ($poLineId === '') {
					continue;
				}

				$poLine = $this->findOne(
					schema:  self::SCHEMA_PO_LINE,
					filters: ['id' => $poLineId, 'administrationId' => $administrationId]
				);
				if ($poLine === null) {
					continue;
				}

				$eligible++;
				$received = $this->thousandths(value: $line['quantityReceived'] ?? 0);
				$ordered = $this->thousandths(value: $poLine['quantityOrdered'] ?? 0);
				if ($received === $ordered) {
					$accurate++;
				}
			}//end foreach
		}//end foreach

		return $this->rateBp(numerator: $accurate, denominator: $eligible);
	}//end computeQuantityAccuracyRate()

	/**
	 * Compute the price-accuracy rate for the period in basis points.
	 *
	 * A ThreeWayMatch counts as price-accurate when its matchStatus is
	 * `auto_approved` or `within_tolerance` (every divergence — including
	 * price — landed within the applicable ToleranceProfile).
	 *
	 * @param array<int,array<string,mixed>> $matches Period ThreeWayMatch records.
	 *
	 * @return int Basis points (0..10000). 0 when no match exists in the period.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function computePriceAccuracyRate(array $matches): int {
		$eligible = count($matches);
		$accurate = 0;
		foreach ($matches as $match) {
			$status = (string)($match['matchStatus'] ?? '');
			if ($status === 'auto_approved' || $status === 'within_tolerance') {
				$accurate++;
			}
		}

		return $this->rateBp(numerator: $accurate, denominator: $eligible);
	}//end computePriceAccuracyRate()

	/**
	 * Compute the invoice-accuracy rate for the period in basis points.
	 *
	 * An invoice counts as accurate when the FIRST ThreeWayMatch evaluated
	 * for it within the period landed `auto_approved` — i.e. it matched on
	 * first try without falling into a within_tolerance or exception
	 * routing path.
	 *
	 * @param array<int,array<string,mixed>> $matches Period ThreeWayMatch records.
	 * @param array<string,true> $invoiceIds Supplier invoices in the period.
	 *
	 * @return int Basis points (0..10000). 0 when no invoice was evaluated.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function computeInvoiceAccuracyRate(array $matches, array $invoiceIds): int {
		// Group matches by invoiceId, then pick the chronologically-first one.
		$byInvoice = [];
		foreach ($matches as $match) {
			$invoiceId = (string)($match['invoiceId'] ?? '');
			if ($invoiceId === '' || isset($invoiceIds[$invoiceId]) === false) {
				continue;
			}

			$createdAt = (string)($match['createdAt'] ?? '');
			if (isset($byInvoice[$invoiceId]) === false) {
				$byInvoice[$invoiceId] = $match;
				continue;
			}

			$current = (string)($byInvoice[$invoiceId]['createdAt'] ?? '');
			if ($createdAt !== '' && ($current === '' || $createdAt < $current)) {
				$byInvoice[$invoiceId] = $match;
			}
		}

		$eligible = count($byInvoice);
		$accurate = 0;
		foreach ($byInvoice as $match) {
			if ((string)($match['matchStatus'] ?? '') === 'auto_approved') {
				$accurate++;
			}
		}

		return $this->rateBp(numerator: $accurate, denominator: $eligible);
	}//end computeInvoiceAccuracyRate()

	/**
	 * Weighted overall score in basis points.
	 *
	 * Pure-integer arithmetic: (onTime * 4000 + qty * 3000 + price * 2000
	 * + invoice * 1000) / 10000. Clamped to 0..10000 defensively.
	 *
	 * @param int $onTimeBp On-time-delivery rate in basis points.
	 * @param int $qtyBp Quantity-accuracy rate in basis points.
	 * @param int $priceBp Price-accuracy rate in basis points.
	 * @param int $invoiceBp Invoice-accuracy rate in basis points.
	 *
	 * @return int Composite score in basis points (0..10000).
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function weightedOverall(int $onTimeBp, int $qtyBp, int $priceBp, int $invoiceBp): int {
		$onTimePart = ($onTimeBp * self::WEIGHT_ON_TIME);
		$qtyPart = ($qtyBp * self::WEIGHT_QUANTITY);
		$pricePart = ($priceBp * self::WEIGHT_PRICE);
		$invoicePart = ($invoiceBp * self::WEIGHT_INVOICE);
		$weightedSum = ($onTimePart + $qtyPart + $pricePart + $invoicePart);
		$score = intdiv($weightedSum, self::MAX_BP);
		if ($score < 0) {
			return 0;
		}

		if ($score > self::MAX_BP) {
			return self::MAX_BP;
		}

		return $score;
	}//end weightedOverall()

	/**
	 * Trend bucket vs prior period.
	 *
	 * @param int $currentBp Current overall score in basis points.
	 * @param array<string,mixed>|null $priorCard Prior-period scorecard (or null).
	 *
	 * @return string One of `improving`, `stable`, `declining`.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function computeScoreTrend(int $currentBp, ?array $priorCard): string {
		if ($priorCard === null) {
			return 'stable';
		}

		$priorBp = $this->intOrZero(value: $priorCard['overallScore'] ?? 0);
		$delta = ($currentBp - $priorBp);
		if ($delta >= self::TREND_BUCKET_BP) {
			return 'improving';
		}

		if ($delta <= -self::TREND_BUCKET_BP) {
			return 'declining';
		}

		return 'stable';
	}//end computeScoreTrend()

	/**
	 * Server-authoritative eligibility flag.
	 *
	 * TRUE when overallScore ≥ 9600 basis points (96 %) AND the supplier's
	 * first scorecard for this administration is at least
	 * {@see BOOTSTRAP_DAYS} days old. The bootstrap window prevents
	 * thin-history elevation (design D5 Risk-2 mitigation).
	 *
	 * @param int $overallScoreBp Current overall score in basis points.
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId Supplier id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function setAutoReviewEligible(int $overallScoreBp, string $administrationId, string $supplierId): bool {
		if ($overallScoreBp < self::ELIGIBILITY_THRESHOLD_BP) {
			return false;
		}

		// Find the supplier's earliest scorecard for this administration —
		// VendorPerformance carries no createdAt by schema design, so the
		// "first scorecard" is reconstructed by sorting on the period code.
		$cards = $this->findAll(
			schema:  self::SCHEMA_VENDOR_PERFORMANCE,
			filters: [
				'supplierId' => $supplierId,
				'administrationId' => $administrationId,
			]
		);
		if ($cards === []) {
			return false;
		}

		$firstPeriod = '';
		foreach ($cards as $card) {
			$period = (string)($card['period'] ?? '');
			if ($period === '') {
				continue;
			}

			if ($firstPeriod === '' || $period < $firstPeriod) {
				$firstPeriod = $period;
			}
		}

		if ($firstPeriod === '') {
			return false;
		}

		try {
			$firstDate = $this->periodToFirstDay(period: $firstPeriod);
			$today = new DateTimeImmutable('today');
			$ageDays = (int)$firstDate->diff($today)->format('%a');
			if ($firstDate > $today) {
				$ageDays = -$ageDays;
			}
		} catch (Throwable $e) {
			return false;
		}

		return ($ageDays >= self::BOOTSTRAP_DAYS);
	}//end setAutoReviewEligible()

	/**
	 * Nudge the supplier's ToleranceProfile one step more permissive.
	 *
	 * Concretely: raise priceTolerancePercentage by 25 basis points
	 * (0.25 percentage points), quantityTolerancePercentage by 25 basis
	 * points, and dateToleranceDays by 1 — bounded so the values never
	 * exceed safety caps (1000 bp price, 1000 bp qty, 14 days). When the
	 * supplier has no scope=supplier ToleranceProfile the method is a
	 * no-op and returns null — slice 08's controller workflow surfaces
	 * the relaxation to the operator instead.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId Supplier id.
	 *
	 * @return array<string,mixed>|null Updated profile or null when no-op.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function autoRelaxToleranceProfile(string $administrationId, string $supplierId): ?array {
		$profile = $this->findOne(
			schema:  self::SCHEMA_TOLERANCE_PROFILE,
			filters: [
				'scope' => 'supplier',
				'scopeReference' => $supplierId,
				'status' => 'active',
				'administrationId' => $administrationId,
			]
		);
		if ($profile === null) {
			return null;
		}

		$priceBp = $this->intOrZero(value: $profile['priceTolerancePercentage'] ?? 0) + 25;
		if ($priceBp > 1000) {
			$priceBp = 1000;
		}

		$qtyBp = $this->intOrZero(value: $profile['quantityTolerancePercentage'] ?? 0) + 25;
		if ($qtyBp > 1000) {
			$qtyBp = 1000;
		}

		$dateDays = $this->intOrZero(value: $profile['dateToleranceDays'] ?? 0) + 1;
		if ($dateDays > 14) {
			$dateDays = 14;
		}

		$profile['priceTolerancePercentage'] = $priceBp;
		$profile['quantityTolerancePercentage'] = $qtyBp;
		$profile['dateToleranceDays'] = $dateDays;

		return $this->saveObject(schema: self::SCHEMA_TOLERANCE_PROFILE, object: $profile);
	}//end autoRelaxToleranceProfile()

	/**
	 * Aggregate every supplier with activity in the administration for the
	 * given period. Used by the monthly cron entry point.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Period code (YYYY-MM).
	 *
	 * @return array<int,array<string,mixed>> The persisted scorecards.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function aggregateAdministrationForPeriod(string $administrationId, string $period): array {
		if ($administrationId === '' || $period === '') {
			return [];
		}

		$window = $this->resolvePeriodWindow(period: $period);
		$invoices = $this->filterByDate(
			rows:  $this->findAll(
				schema:  self::SCHEMA_SUPPLIER_INVOICE,
				filters: ['administrationId' => $administrationId]
			),
			field: 'invoiceDate',
			from:  $window['from'],
			to:    $window['to']
		);

		$supplierIds = [];
		foreach ($invoices as $invoice) {
			$supplierId = trim((string)($invoice['supplierId'] ?? ''));
			if ($supplierId !== '') {
				$supplierIds[$supplierId] = true;
			}
		}

		$scorecards = [];
		foreach (array_keys($supplierIds) as $supplierId) {
			try {
				$scorecards[] = $this->calculateMonthlyScore(
					administrationId: $administrationId,
					supplierId:       (string)$supplierId,
					period:           $period
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'VendorPerformanceAggregation: scorecard failed',
					[
						'administrationId' => $administrationId,
						'supplierId' => $supplierId,
						'period' => $period,
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		return $scorecards;
	}//end aggregateAdministrationForPeriod()

	/**
	 * Resolve a period code to ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD'].
	 *
	 * @param string $period Period code.
	 *
	 * @return array{from:string,to:string}
	 *
	 * @throws \RuntimeException When the period is malformed.
	 */
	private function resolvePeriodWindow(string $period): array {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$month = (int)$matches[2];
			$from = sprintf('%04d-%02d-01', $year, $month);
			$to = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
			return ['from' => $from, 'to' => $to];
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$quarter = (int)$matches[2];
			$startMo = (($quarter - 1) * 3) + 1;
			$endMo = $startMo + 2;
			$from = sprintf('%04d-%02d-01', $year, $startMo);
			$endStart = sprintf('%04d-%02d-01', $year, $endMo);
			$to = (new DateTimeImmutable($endStart))->modify('last day of this month')->format('Y-m-d');
			return ['from' => $from, 'to' => $to];
		}

		throw new RuntimeException('Invalid period: ' . $period);
	}//end resolvePeriodWindow()

	/**
	 * Compute the prior period code for a given period.
	 *
	 * @param string $period Period code.
	 *
	 * @return string Prior period code, or '' when undefined.
	 */
	private function priorPeriodOf(string $period): string {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$month = (int)$matches[2];
			$month--;
			if ($month === 0) {
				$month = 12;
				$year--;
			}

			return sprintf('%04d-%02d', $year, $month);
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $matches) === 1) {
			$year = (int)$matches[1];
			$quarter = (int)$matches[2];
			$quarter--;
			if ($quarter === 0) {
				$quarter = 4;
				$year--;
			}

			return sprintf('%04d-Q%d', $year, $quarter);
		}

		return '';
	}//end priorPeriodOf()

	/**
	 * Translate a period code into the DateTimeImmutable of its first day.
	 *
	 * @param string $period Period code.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws \RuntimeException When the period is malformed.
	 */
	private function periodToFirstDay(string $period): DateTimeImmutable {
		$window = $this->resolvePeriodWindow(period: $period);
		return new DateTimeImmutable($window['from']);
	}//end periodToFirstDay()

	/**
	 * Earliest expectedDeliveryDate across a GRN's referenced POs.
	 *
	 * @param array<string,mixed> $grn GRN record.
	 * @param array<string,array<string,mixed>> $poIds Supplier POs keyed by id.
	 *
	 * @return string Date in YYYY-MM-DD, or '' when no PO has an expected date.
	 */
	private function expectedDeliveryDateFor(array $grn, array $poIds): string {
		$earliest = '';
		foreach ((array)($grn['poIds'] ?? []) as $poId) {
			$po = ($poIds[(string)$poId] ?? null);
			if ($po === null) {
				continue;
			}

			$expected = trim((string)($po['expectedDeliveryDate'] ?? ''));
			if ($expected === '') {
				continue;
			}

			$expected = $this->dateOnly(iso: $expected);
			if ($earliest === '' || $expected < $earliest) {
				$earliest = $expected;
			}
		}

		return $earliest;
	}//end expectedDeliveryDateFor()

	/**
	 * Count supplier-facing disputes inside the matches.
	 *
	 * @param array<int,array<string,mixed>> $matches Period matches.
	 *
	 * @return int
	 */
	private function countDisputes(array $matches): int {
		$count = 0;
		foreach ($matches as $match) {
			$action = (string)($match['resolutionAction'] ?? '');
			if ($action === 'credit_note_requested' || $action === 'supplier_contacted') {
				$count++;
			}
		}

		return $count;
	}//end countDisputes()

	/**
	 * Average resolution days (createdAt → resolvedAt) for resolved matches.
	 *
	 * @param array<int,array<string,mixed>> $matches Period matches.
	 *
	 * @return int Rounded HALF-UP to a whole day; 0 when nothing resolved.
	 */
	private function averageResolutionDays(array $matches): int {
		$total = 0;
		$count = 0;
		foreach ($matches as $match) {
			$created = trim((string)($match['createdAt'] ?? ''));
			$resolved = trim((string)($match['resolvedAt'] ?? ''));
			if ($created === '' || $resolved === '') {
				continue;
			}

			try {
				$a = new DateTimeImmutable($created);
				$b = new DateTimeImmutable($resolved);
				$days = (int)$a->diff($b)->format('%a');
				if ($b < $a) {
					$days = -$days;
				}

				$total += $days;
				$count++;
			} catch (Throwable $e) {
				continue;
			}
		}//end foreach

		if ($count === 0) {
			return 0;
		}

		// Integer-rounding HALF-UP: (total + count/2) intdiv count.
		return intdiv(($total + intdiv($count, 2)), $count);
	}//end averageResolutionDays()

	/**
	 * Quantise a count + denominator to basis points (0..10000).
	 *
	 * @param int $numerator Numerator.
	 * @param int $denominator Denominator.
	 *
	 * @return int Basis points; 0 when the denominator is 0.
	 */
	private function rateBp(int $numerator, int $denominator): int {
		if ($denominator <= 0) {
			return 0;
		}

		$bp = intdiv(($numerator * self::MAX_BP), $denominator);
		if ($bp < 0) {
			return 0;
		}

		if ($bp > self::MAX_BP) {
			return self::MAX_BP;
		}

		return $bp;
	}//end rateBp()

	/**
	 * Reduce an ISO timestamp to its date component.
	 *
	 * @param string $iso ISO timestamp.
	 *
	 * @return string Date in YYYY-MM-DD or the trimmed input when shorter.
	 */
	private function dateOnly(string $iso): string {
		$trimmed = trim($iso);
		if (strlen($trimmed) >= 10) {
			return substr($trimmed, 0, 10);
		}

		return $trimmed;
	}//end dateOnly()

	/**
	 * Coerce a quantity value to integer thousandths.
	 *
	 * @param mixed $value Quantity value.
	 *
	 * @return int
	 */
	private function thousandths(mixed $value): int {
		if (is_int($value) === true) {
			return ($value * 1000);
		}

		if (is_float($value) === true) {
			return (int)round(($value * 1000));
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (int)round(((float)$value * 1000));
		}

		return 0;
	}//end thousandths()

	/**
	 * Coerce a value to a non-negative int.
	 *
	 * @param mixed $value Value.
	 *
	 * @return int
	 */
	private function intOrZero(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (int)$value;
		}

		if (is_float($value) === true) {
			return (int)$value;
		}

		return 0;
	}//end intOrZero()

	/**
	 * Filter rows by a date-field window (inclusive).
	 *
	 * @param array<int,array<string,mixed>> $rows Source rows.
	 * @param string $field Field to inspect.
	 * @param string $from Lower bound YYYY-MM-DD.
	 * @param string $to Upper bound YYYY-MM-DD.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function filterByDate(array $rows, string $field, string $from, string $to): array {
		$result = [];
		foreach ($rows as $row) {
			$value = $this->dateOnly(iso: (string)($row[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			if ($value >= $from && $value <= $to) {
				$result[] = $row;
			}
		}

		return $result;
	}//end filterByDate()

	/**
	 * Extract a stable id from an OR row.
	 *
	 * @param array<string,mixed> $row OR row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
		return $id;
	}//end idOf()

	/**
	 * Persist an object via OR's real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object to persist.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the persistence call fails.
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregation: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all matching records via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'VendorPerformanceAggregation: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
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
