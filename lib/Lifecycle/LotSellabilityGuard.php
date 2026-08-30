<?php

/**
 * Lot Sellability Guard — ADR-031 imperative exception seam.
 *
 * Change block-unsellable-stock-dispatch: the dispatch path
 * ({@see \OCA\Shillinq\Service\SalesDispatchStockIssueService::issueForDelivery()})
 * turns a confirmed Delivery line into a posted `issue` StockMove that feeds
 * the FIFO/AVG valuation + COGS-posting pipeline. Prior to this change that
 * path never consulted `InventoryLot.lotStatus` / `expiryDate`, so stock in a
 * quarantined, expired or exhausted lot could be dispatched and sold, posting
 * COGS as if it were good stock.
 *
 * This guard is the single decision point that decides whether a line may be
 * issued given the InventoryLot rows for its product. A lot is SELLABLE iff
 * `lotStatus == 'active'` AND (`expiryDate` is empty OR `expiryDate >= today`).
 * Expiry is first-class: an `active` lot whose `expiryDate` is in the past is
 * unsellable even though its status is otherwise fine (REQ-BLK-001).
 *
 * Why a PHP guard and not a declarative x-openregister-lifecycle edit
 * (ADR-031 §"PHP guards remain a legitimate seam", see design.md):
 *   - the dispatch path CREATES a StockMove; it does not TRANSITION the
 *     InventoryStock/InventoryLot object, so a declarative guard on
 *     `lotStatus`/`InventoryStock.status` would intercept a transition that
 *     never fires — zero runtime effect (the orphaned-capability defect
 *     class); and
 *   - StockMove carries no `lotId` field today, so no declarative validation
 *     on StockMove can reference lot sellability, and the rule itself
 *     (cross-schema aggregation: sum sellable lot quantity vs line quantity,
 *     FEFO ordering, today-vs-expiryDate) is not expressible in OR's
 *     single-object validation dialect.
 *
 * Pure decision logic, no persistence — mirrors the shape of
 * {@see \OCA\Shillinq\Sort\FefoSort} and
 * {@see \OCA\Shillinq\Lifecycle\LotTrackingReceiptGuard}.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Sort\FefoSort;

/**
 * Decides whether the InventoryLot rows for a Delivery line can satisfy the
 * line quantity from SELLABLE stock only.
 *
 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class LotSellabilityGuard {
	/**
	 * The only lotStatus value that is sellable per REQ-LOT-006.
	 */
	private const STATUS_ACTIVE = 'active';

	/**
	 * Small tolerance so integer-cent quantities compared as floats never
	 * fail a satisfiable line on a representation rounding error.
	 */
	private const QUANTITY_EPSILON = 0.00001;

	/**
	 * Construct the guard.
	 *
	 * @param FefoSort $fefoSort FEFO ordering helper — sellable lots are
	 *                           reported earliest-expiry-first so a caller
	 *                           that later stamps a lotId picks FEFO.
	 */
	public function __construct(
		private readonly FefoSort $fefoSort,
	) {

	}//end __construct()

	/**
	 * Evaluate the sellability of a set of InventoryLot rows against a
	 * required quantity.
	 *
	 * A lot is sellable iff `lotStatus === 'active'` AND (`expiryDate` is
	 * null/empty OR `expiryDate >= today`). The line is satisfiable iff the
	 * summed available quantity of the sellable lots is greater than or equal
	 * to the required quantity — FEFO preference over hard-failing: as long as
	 * sellable lots can cover the line, quarantined/expired siblings do not
	 * block it (REQ-BLK-002). It fails closed only when no combination of
	 * sellable lots can satisfy the line (REQ-BLK-001).
	 *
	 * @param array<int,array<string,mixed>> $lots Candidate InventoryLot rows for the line's product.
	 * @param float $requiredQuantity Quantity the line needs to ship (> 0).
	 * @param string $today Today's date as `Y-m-d` (injected for testability).
	 *
	 * @return array<string,mixed> Verdict:
	 *                             {sellable: bool, availableSellable: float, shortfall: float,
	 *                             sellableLotIds: list<string>,
	 *                             offendingLots: list<array{lotId:string, lotNumber:string,
	 *                             lotStatus:string, expiryDate:?string, reason:string, reasonNl:string}>}.
	 *
	 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
	 */
	public function evaluate(array $lots, float $requiredQuantity, string $today): array {
		$sellableLots = [];
		$offendingLots = [];

		foreach ($lots as $lot) {
			if (is_array($lot) === false) {
				continue;
			}

			if ($this->isSellable(lot: $lot, today: $today) === true) {
				$sellableLots[] = $lot;
				continue;
			}

			$offendingLots[] = $this->describeUnsellable(lot: $lot, today: $today);
		}

		// FEFO order the sellable lots so a downstream lot-stamping consumer
		// (when StockMove.lotId lands) draws earliest-expiry-first.
		$sellableLots = $this->fefoSort->sortLots(lots: $sellableLots);

		$availableSellable = 0.0;
		$sellableLotIds = [];
		foreach ($sellableLots as $lot) {
			$availableSellable += (float)($lot['quantity'] ?? 0);
			$sellableLotIds[] = (string)($lot['id'] ?? ($lot['@self']['id'] ?? ''));
		}

		$sellable = ($availableSellable + self::QUANTITY_EPSILON) >= $requiredQuantity;
		$shortfall = 0.0;
		if ($sellable === false) {
			$shortfall = ($requiredQuantity - $availableSellable);
		}

		return [
			'sellable' => $sellable,
			'availableSellable' => $availableSellable,
			'shortfall' => $shortfall,
			'sellableLotIds' => $sellableLotIds,
			'offendingLots' => $offendingLots,
		];

	}//end evaluate()

	/**
	 * True iff the lot is active and not past its expiry date.
	 *
	 * @param array<string,mixed> $lot An InventoryLot row.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return bool
	 */
	private function isSellable(array $lot, string $today): bool {
		$status = (string)($lot['lotStatus'] ?? self::STATUS_ACTIVE);
		if ($status !== self::STATUS_ACTIVE) {
			return false;
		}

		return $this->isExpired(lot: $lot, today: $today) === false;
	}//end isSellable()

	/**
	 * True iff the lot carries an expiryDate strictly before today.
	 *
	 * @param array<string,mixed> $lot An InventoryLot row.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return bool
	 */
	private function isExpired(array $lot, string $today): bool {
		$expiry = ($lot['expiryDate'] ?? null);
		if ($expiry === null || $expiry === '') {
			return false;
		}

		// Date strings in `Y-m-d` compare correctly lexicographically.
		return (strcmp((string)$expiry, $today) < 0);
	}//end isExpired()

	/**
	 * Build the operator/auditor-facing reason (EN + NL) describing why a lot
	 * is unsellable.
	 *
	 * @param array<string,mixed> $lot An unsellable InventoryLot row.
	 * @param string $today Today's date as `Y-m-d`.
	 *
	 * @return array{lotId:string, lotNumber:string, lotStatus:string, expiryDate:?string, reason:string, reasonNl:string}
	 */
	private function describeUnsellable(array $lot, string $today): array {
		$status = (string)($lot['lotStatus'] ?? self::STATUS_ACTIVE);
		$expiryRaw = ($lot['expiryDate'] ?? null);
		$expiry = null;
		if ($expiryRaw !== null && $expiryRaw !== '') {
			$expiry = (string)$expiryRaw;
		}

		if ($status === 'quarantined') {
			$reason = 'quarantined (held for quality inspection)';
			$reasonNl = 'in quarantaine (vastgehouden voor kwaliteitsinspectie)';
		} elseif ($status === 'expired') {
			$reason = 'expired (lot marked expired)';
			$reasonNl = 'verlopen (lot gemarkeerd als verlopen)';
		} elseif ($status === 'exhausted') {
			$reason = 'exhausted (lot depleted)';
			$reasonNl = 'uitgeput (lot leeg)';
		} elseif ($this->isExpired(lot: $lot, today: $today) === true) {
			$reason = 'expired (past expiry date ' . (string)$expiry . ')';
			$reasonNl = 'verlopen (vervaldatum ' . (string)$expiry . ' verstreken)';
		} else {
			$reason = 'not sellable (lotStatus ' . $status . ')';
			$reasonNl = 'niet verkoopbaar (lotstatus ' . $status . ')';
		}//end if

		return [
			'lotId' => (string)($lot['id'] ?? ($lot['@self']['id'] ?? '')),
			'lotNumber' => (string)($lot['lotNumber'] ?? ''),
			'lotStatus' => $status,
			'expiryDate' => $expiry,
			'reason' => $reason,
			'reasonNl' => $reasonNl,
		];

	}//end describeUnsellable()
}//end class
