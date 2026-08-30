<?php

/**
 * Cycle Count Service
 *
 * ADR-031 exception-path service implementing the two fan-out actions
 * declared in the InventoryCycleCount.x-openregister-lifecycle block
 * (the register engine cannot express a filtered fan-out + per-row
 * conditional materialisation declaratively yet). Two operations:
 *
 *   - snapshotScope()    — invoked on draft → submitted. Queries
 *     InventoryStock with the partial-count filter (locationFilter /
 *     categoryFilter) per REQ-ICC-008, then emits one
 *     InventoryCycleCountLine per matching (sku, locationId) pair with
 *     `expectedQuantity` populated from the snapshot and
 *     `countedQuantity` null. Idempotent on (administrationId, countId):
 *     pre-existing lines are not duplicated.
 *
 *   - emitAdjustments()  — invoked on posted → reconciled. Walks every
 *     non-zero-variance line, creates one StockMove per line via the
 *     inventory-stock-movement-ledger lifecycle and posts it
 *     (movementType=receipt for positive variance / issue for negative
 *     variance, movementReason='cycle-count-variance',
 *     referenceDocumentUri='shillinq://cycle-count/<countId>'). Stamps
 *     the resulting StockMove id on the line's `adjustmentStockMoveId`
 *     back-reference. Idempotent per line — a line that already carries
 *     `adjustmentStockMoveId` is skipped, so a partial-retry after a
 *     transient OR error never double-posts.
 *
 * No persistence beyond OR. All reads/writes via the real
 * ObjectService API (find / findAll / saveObject / updateObject).
 * Integer-cent arithmetic (multipleOf 0.01).
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
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\VarianceGate;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Per REQ-ICC-006 snapshot fan-out + REQ-ICC-007 variance posting.
 *
 * Referenced from inventory-cycle-count.json
 * InventoryCycleCount.x-openregister-lifecycle.transitions.submit.actions[1] and
 * .transitions.reconcile.actions[1].
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 */
class CycleCountService {

	/**
	 * Movement reason stamped on every variance adjustment per REQ-ICC-007.
	 * Mirrors the entry added to the StockMove.movementReason enum by the
	 * inventory-cycle-count register fragment.
	 *
	 * @var string
	 */
	public const VARIANCE_REASON = 'cycle-count-variance';

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config — used to read register
	 *                              slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs payloads.
	 * @param VarianceGate $varianceGate Pure helper for threshold + recalculation.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly VarianceGate $varianceGate,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Snapshot the in-scope InventoryStock rows into
	 * InventoryCycleCountLine rows per REQ-ICC-006 + REQ-ICC-008.
	 *
	 * Accepted by the lifecycle engine on transition draft → submitted.
	 * Idempotent: if any InventoryCycleCountLine already exists for the
	 * count, the method short-circuits and returns true (a retry after a
	 * transient OR error never duplicates the snapshot).
	 *
	 * Fail-closed: any unexpected exception returns false; the engine
	 * blocks the transition and the operator retries.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool True when the snapshot completes (or was already present); false on error.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function snapshotScope(array $count): bool {
		try {
			$countId = (string)($count['countId'] ?? '');
			$administrationId = (string)($count['administrationId'] ?? '');
			$countType = (string)($count['countType'] ?? '');
			if ($countId === '' || $administrationId === '' || $countType === '') {
				$this->logger->info(
					'CycleCountService: snapshot denied — required fields missing',
					['countId' => ($count['countId'] ?? null)]
				);
				return false;
			}

			if ($this->countHasLines(administrationId: $administrationId, countId: $countId) === true) {
				$this->logger->info(
					'CycleCountService: snapshot skipped — lines already exist',
					['countId' => $countId]
				);
				return true;
			}

			$stockRows = $this->findInScopeStock(
				administrationId: $administrationId,
				countType: $countType,
				locationFilter: (string)($count['locationFilter'] ?? ''),
				categoryFilter: (string)($count['categoryFilter'] ?? '')
			);

			if ($stockRows === []) {
				$this->logger->info(
					'CycleCountService: snapshot produced zero lines (empty scope)',
					['countId' => $countId]
				);
				return true;
			}

			$sequence = 0;
			foreach ($stockRows as $stock) {
				if (is_array($stock) === false) {
					continue;
				}

				$sku = (string)($stock['sku'] ?? '');
				$locationId = (string)($stock['locationId'] ?? '');
				if ($sku === '' || $locationId === '') {
					continue;
				}

				$productName = null;
				if (isset($stock['productName']) === true) {
					$productName = (string)$stock['productName'];
				}

				$sequence++;
				$lineId = sprintf('%s-%03d', $countId, $sequence);
				$unitCost = $this->numericOrZero(value: $stock['unitCost'] ?? null);
				$expectedQ = $this->numericOrZero(value: $stock['quantity'] ?? null);
				$expectedValCts = (int)round(($this->cents(value: $expectedQ) * $this->cents(value: $unitCost)) / 100);
				$line = [
					'lineId' => $lineId,
					'countId' => $countId,
					'sku' => $sku,
					'productName' => $productName,
					'locationId' => $locationId,
					'expectedQuantity' => $expectedQ,
					'countedQuantity' => null,
					'unitCost' => $unitCost,
					'expectedValue' => $this->fromCents(cents: $expectedValCts),
					'countedValue' => null,
					'quantityVariance' => null,
					'valueVariance' => null,
					'requiresReason' => false,
					'reasonCode' => null,
					'notes' => null,
					'adjustmentStockMoveId' => null,
					'administrationId' => $administrationId,
				];

				$this->objectService
					->setRegister($this->register())
					->setSchema('InventoryCycleCountLine')
					->saveObject($line);
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: snapshot failed — denying submit (fail-closed)',
				[
					'countId' => ($count['countId'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end snapshotScope()

	/**
	 * Emit variance adjustment StockMoves per REQ-ICC-007.
	 *
	 * Walks every InventoryCycleCountLine attached to the count and creates
	 * one StockMove per line with non-zero `quantityVariance`. The sign of
	 * the variance picks the movementType: positive variance (counted >
	 * expected) is a `receipt` to the location; negative variance is an
	 * `issue` from the location. All adjustments carry movementReason =
	 * 'cycle-count-variance' and referenceDocumentUri pointing back to the
	 * originating countId — the inventory-stock-movement-ledger lifecycle
	 * handles InventoryStock.quantity update + GL materialisation. The
	 * resulting StockMove id is stamped on the line's
	 * `adjustmentStockMoveId` back-reference for full traceability.
	 *
	 * Idempotent per line: lines that already carry `adjustmentStockMoveId`
	 * are skipped, so partial retries after transient OR errors never
	 * double-post. Zero-variance lines are skipped.
	 *
	 * Fail-closed: any unexpected exception returns false; the engine
	 * blocks the transition and the operator retries.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool True when every non-zero-variance line has been posted (or was already posted); false on error.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function emitAdjustments(array $count): bool {
		try {
			$countId = (string)($count['countId'] ?? '');
			$administrationId = (string)($count['administrationId'] ?? '');
			if ($countId === '' || $administrationId === '') {
				$this->logger->info(
					'CycleCountService: emit-adjustments denied — required fields missing',
					['countId' => ($count['countId'] ?? null)]
				);
				return false;
			}

			$lines = $this->findLinesForCount(administrationId: $administrationId, countId: $countId);
			if ($lines === []) {
				$this->logger->info(
					'CycleCountService: emit-adjustments skipped — no lines',
					['countId' => $countId]
				);
				return true;
			}

			$sequence = 0;
			foreach ($lines as $line) {
				if (is_array($line) === false) {
					continue;
				}

				if (isset($line['adjustmentStockMoveId']) === true
					&& trim((string)$line['adjustmentStockMoveId']) !== ''
				) {
					// Already posted — partial-retry idempotency.
					continue;
				}

				$countedQ = $this->numericOrNull(value: $line['countedQuantity'] ?? null);
				$expectedQ = $this->numericOrNull(value: $line['expectedQuantity'] ?? null);
				if ($countedQ === null || $expectedQ === null) {
					// Un-entered line: skip silently. The post-time VarianceGate already
					// refused any flagged line without reason; missing counted values are
					// treated as zero-variance for reconciliation purposes.
					continue;
				}

				$varianceCents = ($this->cents(value: $countedQ) - $this->cents(value: $expectedQ));
				if ($varianceCents === 0) {
					continue;
				}

				$sequence++;
				$movementNumber = sprintf('SM-CC-%s-%03d', $countId, $sequence);
				$unitCost = $this->numericOrZero(value: $line['unitCost'] ?? null);
				$locationId = (string)($line['locationId'] ?? '');
				$sku = (string)($line['sku'] ?? '');
				if ($locationId === '' || $sku === '') {
					$this->logger->error(
						'CycleCountService: emit-adjustments denied — line missing sku or locationId',
						['countId' => $countId, 'lineId' => ($line['lineId'] ?? null)]
					);
					return false;
				}

				if ($varianceCents > 0) {
					// Positive: found stock → receipt to the location.
					$sourceLocation = null;
					$destinationLocation = $locationId;
				} else {
					// Negative: shrinkage → issue from the location.
					$sourceLocation = $locationId;
					$destinationLocation = null;
				}

				$absQty = abs($this->fromCents(cents: $varianceCents));
				$movementType = 'issue';
				if ($varianceCents > 0) {
					$movementType = 'receipt';
				}

				$reasonNote = 'n/a';
				if (isset($line['reasonCode']) === true) {
					$reasonNote = (string)$line['reasonCode'];
				}

				$move = [
					'movementNumber' => $movementNumber,
					'itemId' => $sku,
					'quantity' => $absQty,
					'unitCost' => $unitCost,
					'movementType' => $movementType,
					'sourceLocationId' => $sourceLocation,
					'destinationLocationId' => $destinationLocation,
					'referenceDocumentUri' => sprintf('shillinq://cycle-count/%s', $countId),
					'movementReason' => self::VARIANCE_REASON,
					'notes' => sprintf(
						'Variance adjustment for line %s (reasonCode=%s)',
						(string)($line['lineId'] ?? ''),
						$reasonNote
					),
					'draftedAt' => $this->now(),
					'administrationId' => $administrationId,
					'lifecycleState' => 'draft',
					'locked' => false,
				];

				$saved = $this->objectService
					->setRegister($this->register())
					->setSchema('StockMove')
					->saveObject($move);

				$stockMoveId = $this->extractId(saved: $saved);
				if ($stockMoveId === '') {
					$this->logger->error(
						'CycleCountService: emit-adjustments — saveObject returned no id',
						['countId' => $countId, 'lineId' => ($line['lineId'] ?? null)]
					);
					return false;
				}

				// Stamp the back-reference on the line so a future retry skips it.
				$lineUpdate = $line;
				$lineUpdate['adjustmentStockMoveId'] = $stockMoveId;
				$this->objectService
					->setRegister($this->register())
					->setSchema('InventoryCycleCountLine')
					->saveObject($lineUpdate);
			}//end foreach

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: emit-adjustments failed — denying reconcile (fail-closed)',
				[
					'countId' => ($count['countId'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
			return false;
		}//end try

	}//end emitAdjustments()

	/**
	 * Recompute the derived fields on a single line and persist them. Useful
	 * for the manifest's "Update" action after an operator enters a
	 * countedQuantity per REQ-ICC-003 + REQ-ICC-004.
	 *
	 * @param array<string,mixed> $line The line to refresh.
	 *
	 * @return array<string,mixed> The refreshed line (already persisted on success).
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function recalculateLine(array $line): array {
		$refreshed = $this->varianceGate->recalculateLine(line: $line);
		try {
			$this->objectService
				->setRegister($this->register())
				->setSchema('InventoryCycleCountLine')
				->saveObject($refreshed);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: recalculateLine persist failed',
				[
					'lineId' => ($line['lineId'] ?? null),
					'exception' => $e->getMessage(),
				]
			);
		}

		return $refreshed;
	}//end recalculateLine()

	/**
	 * True iff any InventoryCycleCountLine already exists for the count.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $countId Count identifier.
	 *
	 * @return bool
	 */
	private function countHasLines(string $administrationId, string $countId): bool {
		$existing = $this->findLinesForCount(administrationId: $administrationId, countId: $countId);
		return count($existing) > 0;
	}//end countHasLines()

	/**
	 * Query InventoryStock for the in-scope rows per REQ-ICC-008.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $countType 'full' or 'partial'.
	 * @param string $locationFilter Optional location id for partial counts.
	 * @param string $categoryFilter Optional product category for partial counts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findInScopeStock(
		string $administrationId,
		string $countType,
		string $locationFilter,
		string $categoryFilter,
	): array {
		try {
			$filters = ['administrationId' => $administrationId];

			if ($countType === 'partial' && $locationFilter !== '') {
				$filters['locationId'] = $locationFilter;
			}

			// ADR-084: findAll() is declared `: array` — never null, always an array.
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryStock')
				->findAll(['filters' => $filters]);

			// Category filter requires a Product lookup. Fall back to client-side
			// filter (the volume of stock rows per administration is bounded by SKU
			// count + location count, which is small for the SMB target).
			if ($countType === 'partial' && $categoryFilter !== '' && $locationFilter === '') {
				$rows = $this->filterByCategory(
					stockRows: $rows,
					administrationId: $administrationId,
					category: $categoryFilter
				);
			}

			return $rows;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: findInScopeStock failed',
				[
					'administrationId' => $administrationId,
					'countType' => $countType,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end findInScopeStock()

	/**
	 * Client-side category filter for partial counts scoped by category.
	 * Reads the Product schema for each distinct sku in the stock rows and
	 * keeps only the stock rows whose product's category matches.
	 *
	 * @param array<int,array<string,mixed>> $stockRows Pre-filtered stock rows.
	 * @param string $administrationId Administration scope.
	 * @param string $category Product category to match.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function filterByCategory(array $stockRows, string $administrationId, string $category): array {
		try {
			$skuToCategory = [];
			$rawSkus = [];
			foreach ($stockRows as $row) {
				if (isset($row['sku']) === true) {
					$rawSkus[] = (string)$row['sku'];
				}
			}

			$skus = array_values(
				array_unique(
					array_filter(
						$rawSkus,
						static fn (string $s): bool => $s !== ''
					)
				)
			);
			foreach ($skus as $sku) {
				// ADR-084: this passed a filter ARRAY to find(int|string $id), which
				// looks up a single object by identifier — it never applied the
				// filters, and the is_array() test on its ObjectEntityInterface
				// result was false regardless, so every SKU was categorised ''.
				// findAll() is the filtered lookup and returns array rows.
				$products = $this->objectService
					->setRegister($this->register())
					->setSchema('Product')
					->findAll(
						[
							'filters' => [
								'administrationId' => $administrationId,
								'sku' => $sku,
							],
						]
					);
				$product = ($products[0] ?? null);
				$cat = '';
				if (is_array($product) === true && isset($product['category']) === true) {
					$cat = (string)$product['category'];
				}

				$skuToCategory[$sku] = $cat;
			}

			return array_values(
				array_filter(
					$stockRows,
					static fn (array $row): bool => isset($row['sku']) === true
						&& ($skuToCategory[(string)$row['sku']] ?? '') === $category
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: filterByCategory failed',
				[
					'administrationId' => $administrationId,
					'category' => $category,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end filterByCategory()

	/**
	 * Fetch all InventoryCycleCountLine rows for a count.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $countId Count identifier.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findLinesForCount(string $administrationId, string $countId): array {
		try {
			// ADR-084: findAll() is declared `: array` — never null, always an array.
			return $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryCycleCountLine')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'countId' => $countId,
						],
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: findLinesForCount failed',
				[
					'countId' => $countId,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end findLinesForCount()

	/**
	 * Extract the OR id from a saveObject return value (entity or array shape).
	 *
	 * Delegates to {@see ObjectIdentifier::resolve()}, which carries the full
	 * reasoning and the live measurements. The short version:
	 *
	 * 1. WHICH BRANCH RUNS. The object arm used `method_exists()`. OpenRegister's
	 *    `ObjectEntity` declares neither `getId()` nor `getUuid()` — both arrive
	 *    through `OCP\AppFramework\Db\Entity::__call()`, so `method_exists()` is
	 *    **false for both** and neither arm ever ran. `saveObject()` returns an
	 *    entity, so `is_array()` was false too: `extractId()` returned `''` for
	 *    every real call, and `emitAdjustments()` bailed at :361 — after it had
	 *    already persisted a draft StockMove, and before it could stamp the
	 *    `adjustmentStockMoveId` back-reference its own idempotency depends on.
	 *
	 *    ⚠️ `is_callable()` is NOT the fix: it returns **true for any name at all**
	 *    on a `__call()` class (negative control: `is_callable($e,'totalNonsenseXyz')`
	 *    === true), which turns a never-taken branch into an always-taken one.
	 *
	 * 2. WHICH IDENTIFIER SPACE. Probing correctly is still wrong if you then call
	 *    the wrong accessor. On the value production actually passes — a
	 *    `saveObject()` return — `getId()` yields the **numeric bigint row id**
	 *    (measured: `2`) while `getUuid()` and `jsonSerialize()['id']` yield the
	 *    **UUID**. This value is written to `InventoryCycleCountLine.
	 *    adjustmentStockMoveId`, declared in `inventory-cycle-count.json` as a
	 *    relation to `StockMove.id` — which renders as the UUID. So the obvious
	 *    `property_exists($saved,'id') && $saved->getId()` repair would silently
	 *    store `"2"` against readers expecting a UUID: a dangling FK that fails
	 *    later and quietly, which is strictly worse than the dead path it replaces.
	 *    `getId()` is therefore deliberately NOT probed here.
	 *
	 *    ⚠️ A fixture built from `findAll()` cannot catch this — on a *read* entity
	 *    `getId()` returns NULL, so the fallback runs and the wrong fix certifies
	 *    itself. Build the fixture from the same call production uses.
	 *
	 * The array arm already returned the UUID, so both arms now agree.
	 *
	 * @param mixed $saved Whatever ObjectService::saveObject returned.
	 *
	 * @return string The object's UUID, or empty string when not derivable.
	 */
	private function extractId(mixed $saved): string {
		return ObjectIdentifier::resolve(saved: $saved);
	}//end extractId()

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

	/**
	 * Current ISO-8601 timestamp (UTC) for set-field actions.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * Convert a money/quantity value to integer cents (multipleOf 0.01).
	 *
	 * @param mixed $value Schema number (float or int).
	 *
	 * @return int
	 */
	private function cents(mixed $value): int {
		if (is_int($value) === true) {
			return ($value * 100);
		}

		return (int)round(((float)$value) * 100);
	}//end cents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 */
	private function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Coerce a schema value to a float or return null when missing / non-numeric.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return float|null
	 */
	private function numericOrNull(mixed $value): ?float {
		if ($value === null) {
			return null;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (float)$value;
		}

		return null;
	}//end numericOrNull()

	/**
	 * Coerce a schema value to a float, returning 0.0 when missing.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return float
	 */
	private function numericOrZero(mixed $value): float {
		$coerced = $this->numericOrNull(value: $value);
		if ($coerced === null) {
			return 0.0;
		}

		return $coerced;
	}//end numericOrZero()
}//end class
