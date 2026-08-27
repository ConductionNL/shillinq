<?php

/**
 * Landed Cost Allocation Service
 *
 * Capitalises a receipt's landed costs (freight + import duties + insurance)
 * into the unit cost of the goods received, so both COGS and the
 * balance-sheet inventory value reflect the true acquisition cost per
 * RJ 220.107 / IAS 2.11 ("costs of purchase … include … transport, handling
 * and other costs directly attributable to the acquisition"). Without this,
 * freight and duty land in a period expense, understating inventory value
 * and, on sale, understating COGS.
 *
 * Given a receipt reference and a total landed-cost amount, the service:
 *
 *   1. reads the receipt's inbound `StockMove` lines (movementType=receipt,
 *      same referenceDocumentUri) via OpenRegister's ObjectService (ADR-022);
 *   2. allocates the landed cost across the lines pro-rata by extended value
 *      (`quantity × unitCost`) — or by quantity when `basis=quantity` — using
 *      a largest-remainder distribution so the allocated cents sum EXACTLY to
 *      the input (no rounding leak);
 *   3. computes each line's new landed unit cost
 *      `(originalValueCents + allocatedCents) / quantity`;
 *   4. posts ONE balanced `GLTransaction` capitalising the landed cost:
 *      debit Inventory asset (default `1300`), credit Landed-cost clearing
 *      (default `1305`), via {@see InventoryGlAdjustmentPoster};
 *   5. bumps the driving `InventoryValuation` snapshot's `unitCost` /
 *      `totalValue` so the on-hand value carries the capitalised cost.
 *
 * Idempotency is the caller's responsibility (a receipt is costed once);
 * the service is a pure allocation + posting step.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Pro-rata landed-cost capitalisation into unit cost + balanced GL (ADR-031 exception).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class LandedCostAllocationService {

	/**
	 * App-config key for the inventory asset account (RGS 3.5 default '1300').
	 */
	public const CFG_INVENTORY_ACCOUNT = 'inventory_account';

	/**
	 * App-config key for the landed-cost clearing account (RGS default '1305').
	 */
	public const CFG_LANDED_COST_CLEARING_ACCOUNT = 'landed_cost_clearing_account';

	/**
	 * Documented default landed-cost clearing account.
	 */
	private const DEFAULT_LANDED_COST_CLEARING_ACCOUNT = '1305';

	/**
	 * Documented default inventory asset account.
	 */
	private const DEFAULT_INVENTORY_ACCOUNT = '1300';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for account numbers + register slug.
	 * @param InventoryGlAdjustmentPoster $poster Shared balanced-posting adapter.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly InventoryGlAdjustmentPoster $poster,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Allocate a receipt's landed cost across its lines and capitalise it.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $receiptReference Receipt document reference (StockMove.referenceDocumentUri).
	 * @param int $landedCostCents Total landed cost in integer cents.
	 * @param string $basis Allocation basis: 'value' (default) or 'quantity'.
	 * @param string $postingDate ISO yyyy-mm-dd; empty defaults to today.
	 *
	 * @return array<string,mixed> Result envelope: 'allocated' bool, 'lines', 'posting', 'totalAllocatedCents'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function allocate(
		string $administrationId,
		string $receiptReference,
		int $landedCostCents,
		string $basis = 'value',
		string $postingDate = '',
	): array {
		if ($administrationId === '' || $receiptReference === '') {
			return [
				'allocated' => false,
				'message' => 'administrationId and receiptReference are required',
			];
		}

		if ($landedCostCents <= 0) {
			return [
				'allocated' => false,
				'message' => 'landedCostCents non-positive — nothing to allocate',
			];
		}

		$moves = $this->receiptLines(
			administrationId: $administrationId,
			receiptReference: $receiptReference
		);
		if ($moves === []) {
			return [
				'allocated' => false,
				'message' => 'no receipt lines found for reference ' . $receiptReference,
			];
		}

		$weights = $this->weights(moves: $moves, basis: $basis);
		$shares = $this->largestRemainder(total: $landedCostCents, weights: $weights);

		$lines = [];
		foreach ($moves as $index => $move) {
			$qty = (float)($move['quantity'] ?? 0);
			$origUnit = (float)($move['unitCost'] ?? 0);
			$origValCents = (int)round(($qty * $origUnit) * 100);
			$shareCents = (int)($shares[$index] ?? 0);

			$landedUnit = $origUnit;
			if ($qty > 0) {
				$landedUnit = round((($origValCents + $shareCents) / 100) / $qty, 4);
			}

			$lines[] = [
				'moveId' => (string)($move['id'] ?? ($move['@self']['id'] ?? '')),
				'itemId' => (string)($move['itemId'] ?? ''),
				'quantity' => round($qty, 2),
				'originalUnitCost' => round($origUnit, 4),
				'allocatedCents' => $shareCents,
				'landedUnitCost' => $landedUnit,
			];
		}

		$posting = $this->poster->post(
			administrationId: $administrationId,
			debitAccount: $this->inventoryAccount(),
			creditAccount: $this->landedCostClearingAccount(),
			amountCents: $landedCostCents,
			journalCode: 'LAND',
			description: $this->describe(receiptReference: $receiptReference, landedCostCents: $landedCostCents),
			sourceReference: $receiptReference,
			postingDate: $this->postingDate(postingDate: $postingDate, moves: $moves),
			periodId: $this->periodId(postingDate: $this->postingDate(postingDate: $postingDate, moves: $moves))
		);

		$this->bumpValuations(administrationId: $administrationId, lines: $lines);

		$normalisedBasis = 'value';
		if ($basis === 'quantity') {
			$normalisedBasis = 'quantity';
		}

		return [
			'allocated' => true,
			'basis' => $normalisedBasis,
			'receiptReference' => $receiptReference,
			'lines' => $lines,
			'posting' => $posting,
			'totalAllocatedCents' => array_sum($shares),
			'message' => 'landed cost allocated',
		];

	}//end allocate()

	/**
	 * Build the allocation weights per line.
	 *
	 * @param array<int,array<string,mixed>> $moves The receipt lines.
	 * @param string $basis 'value' or 'quantity'.
	 *
	 * @return array<int,int> Integer weights (value-cents or quantity-cents).
	 */
	private function weights(array $moves, string $basis): array {
		$weights = [];
		foreach ($moves as $index => $move) {
			$qty = (float)($move['quantity'] ?? 0);
			if ($basis === 'quantity') {
				$weights[$index] = (int)round($qty * 100);
				continue;
			}

			$unit = (float)($move['unitCost'] ?? 0);
			$weights[$index] = (int)round(($qty * $unit) * 100);
		}

		return $weights;
	}//end weights()

	/**
	 * Distribute an integer total across integer weights so the parts sum
	 * EXACTLY to the total (largest-remainder / Hamilton method). Guarantees
	 * the balanced-posting invariant: Σ shares === total.
	 *
	 * @param int $total Total integer cents to distribute.
	 * @param array<int,int> $weights Per-line weights.
	 *
	 * @return array<int,int> Per-line integer shares summing to $total.
	 */
	private function largestRemainder(int $total, array $weights): array {
		$sumWeights = array_sum($weights);
		$count = count($weights);
		if ($count === 0) {
			return [];
		}

		// Degenerate: zero total weight — split as evenly as possible.
		if ($sumWeights <= 0) {
			$base = intdiv($total, $count);
			$remainder = ($total - ($base * $count));
			$shares = array_fill_keys(array_keys($weights), $base);
			$keys = array_keys($weights);
			for ($i = 0; $i < $remainder; $i++) {
				$shares[$keys[$i]]++;
			}

			return $shares;
		}

		$shares = [];
		$remainders = [];
		$allocated = 0;
		foreach ($weights as $index => $weight) {
			$exact = ($total * $weight);
			$floor = intdiv($exact, $sumWeights);
			$shares[$index] = $floor;
			$remainders[$index] = ($exact - ($floor * $sumWeights));
			$allocated += $floor;
		}

		$leftover = ($total - $allocated);
		// Hand the leftover cents to the lines with the largest remainders.
		arsort($remainders);
		foreach (array_keys($remainders) as $index) {
			if ($leftover <= 0) {
				break;
			}

			$shares[$index]++;
			$leftover--;
		}

		ksort($shares);
		return $shares;
	}//end largestRemainder()

	/**
	 * Bump each affected InventoryValuation snapshot's unitCost / totalValue by
	 * the capitalised landed cost, so the on-hand balance-sheet value carries
	 * the true acquisition cost.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<int,array<string,mixed>> $lines Allocation result lines.
	 *
	 * @return void
	 */
	private function bumpValuations(string $administrationId, array $lines): void {
		foreach ($lines as $line) {
			$itemId = (string)($line['itemId'] ?? '');
			$shareCents = (int)($line['allocatedCents'] ?? 0);
			if ($itemId === '' || $shareCents <= 0) {
				continue;
			}

			try {
				$valuation = $this->activeValuation(administrationId: $administrationId, itemId: $itemId);
				if ($valuation === null) {
					continue;
				}

				$qty = (float)($valuation['quantity'] ?? 0);
				$totalCents = (int)round(((float)($valuation['totalValue'] ?? 0)) * 100);
				$newTotalCents = ($totalCents + $shareCents);
				$valuation['totalValue'] = round(($newTotalCents / 100), 2);
				if ($qty > 0) {
					$valuation['unitCost'] = round(($newTotalCents / 100) / $qty, 4);
				}

				$this->saveValuation(data: $valuation);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'LandedCostAllocationService: failed to bump valuation snapshot',
					['itemId' => $itemId, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

	}//end bumpValuations()

	/**
	 * Load the inbound receipt lines for a receipt reference.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $receiptReference Receipt document reference.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function receiptLines(string $administrationId, string $receiptReference): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'referenceDocumentUri' => $receiptReference,
						'movementType' => 'receipt',
					],
				]
			);

		$lines = [];
		foreach ($rows as $row) {
			$lines[] = $this->asArray(row: $row);
		}

		return $lines;
	}//end receiptLines()

	/**
	 * Locate the active InventoryValuation snapshot for an item.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $itemId Product / item id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function activeValuation(string $administrationId, string $itemId): ?array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->findAll(
				[
					'filters' => [
						'productId' => $itemId,
						'status' => 'active',
						'administrationId' => $administrationId,
					],
					'limit' => 1,
				]
			);

		if ($rows === []) {
			return null;
		}

		return $this->asArray(row: $rows[0]);
	}//end activeValuation()

	/**
	 * Persist an InventoryValuation snapshot.
	 *
	 * @param array<string,mixed> $data Snapshot data.
	 *
	 * @return array<string,mixed>
	 */
	private function saveValuation(array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() test was constant — asArray() is the only path that runs.
		return $this->asArray(row: $saved);
	}//end saveValuation()

	/**
	 * Compose the human-readable GL description.
	 *
	 * @param string $receiptReference Receipt reference.
	 * @param int $landedCostCents Total landed cost in cents.
	 *
	 * @return string
	 */
	private function describe(string $receiptReference, int $landedCostCents): string {
		return sprintf(
			'Landed cost — %s — EUR %s',
			$receiptReference,
			number_format(($landedCostCents / 100), 2, ',', '')
		);

	}//end describe()

	/**
	 * Resolve the posting date, defaulting to the earliest line's postedAt / today.
	 *
	 * @param string $postingDate Explicit override (yyyy-mm-dd) or empty.
	 * @param array<int,array<string,mixed>> $moves Receipt lines.
	 *
	 * @return string yyyy-mm-dd.
	 */
	private function postingDate(string $postingDate, array $moves): string {
		if ($postingDate !== '') {
			return substr($postingDate, 0, 10);
		}

		foreach ($moves as $move) {
			$posted = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
			if ($posted !== '') {
				return substr($posted, 0, 10);
			}
		}

		return date('Y-m-d');
	}//end postingDate()

	/**
	 * Resolve a periodId (yyyy-Qn) from the posting date.
	 *
	 * @param string $postingDate yyyy-mm-dd.
	 *
	 * @return string
	 */
	private function periodId(string $postingDate): string {
		$year = (int)substr($postingDate, 0, 4);
		$month = (int)substr($postingDate, 5, 2);
		if ($year === 0) {
			$year = (int)date('Y');
		}

		$quarter = (int)ceil(max(1, $month) / 3);
		return sprintf('%04d-Q%d', $year, $quarter);
	}//end periodId()

	/**
	 * Resolve the inventory asset account.
	 *
	 * @return string
	 */
	private function inventoryAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CFG_INVENTORY_ACCOUNT,
				self::DEFAULT_INVENTORY_ACCOUNT
			)
		);
		if ($configured === '') {
			return self::DEFAULT_INVENTORY_ACCOUNT;
		}

		return $configured;
	}//end inventoryAccount()

	/**
	 * Resolve the landed-cost clearing account.
	 *
	 * @return string
	 */
	private function landedCostClearingAccount(): string {
		$configured = trim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CFG_LANDED_COST_CLEARING_ACCOUNT,
				self::DEFAULT_LANDED_COST_CLEARING_ACCOUNT
			)
		);
		if ($configured === '') {
			return self::DEFAULT_LANDED_COST_CLEARING_ACCOUNT;
		}

		return $configured;
	}//end landedCostClearingAccount()

	/**
	 * Normalise an OR Object / array to a plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		throw new RuntimeException('LandedCostAllocationService: unsupported row type from ObjectService');
	}//end asArray()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
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
