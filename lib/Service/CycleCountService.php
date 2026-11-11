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

use OCA\Shillinq\AppInfo\AppIdentity;
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
			return $this->snapshotCount(count: $count);
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
	 * Deny an incomplete count, skip one that already has lines, otherwise
	 * snapshot its in-scope stock.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool False only when required fields are missing.
	 */
	private function snapshotCount(array $count): bool {
		$scope = new CycleCountScope(count: $count);
		if ($scope->isComplete() === false) {
			$this->logSnapshot(message: 'CycleCountService: snapshot denied — required fields missing', countId: ($count['countId'] ?? null));
			return false;
		}

		if ($this->countHasLines(administrationId: $scope->administrationId, countId: $scope->countId) === true) {
			$this->logSnapshot(message: 'CycleCountService: snapshot skipped — lines already exist', countId: $scope->countId);
			return true;
		}

		$this->snapshotStock(scope: $scope);
		return true;
	}//end snapshotCount()

	/**
	 * Save one InventoryCycleCountLine per countable in-scope stock row,
	 * numbered <countId>-001, -002, ... in stock order.
	 *
	 * @param CycleCountScope $scope The count being snapshotted.
	 *
	 * @return void
	 */
	private function snapshotStock(CycleCountScope $scope): void {
		$stockRows = $this->findInScopeStock(scope: $scope);
		if ($stockRows === []) {
			$this->logSnapshot(message: 'CycleCountService: snapshot produced zero lines (empty scope)', countId: $scope->countId);
			return;
		}

		$sequence = 0;
		foreach ($this->lines()->countable(stockRows: $stockRows) as $stock) {
			$sequence++;
			// The line id leads the payload, ahead of the fields read from the stock row.
			$lineId = sprintf('%s-%03d', $scope->countId, $sequence);
			$this->saveRow(schema: 'InventoryCycleCountLine', payload: (['lineId' => $lineId] + $this->lines()->forStock(stock: $stock, scope: $scope)));
		}
	}//end snapshotStock()

	/**
	 * Log a snapshot outcome for a count at info level.
	 *
	 * @param string $message The log message.
	 * @param mixed  $countId The count identifier as given.
	 *
	 * @return void
	 */
	private function logSnapshot(string $message, mixed $countId): void {
		$this->logger->info($message, ['countId' => $countId]);
	}//end logSnapshot()

	/**
	 * Save one object in a schema of the register.
	 *
	 * @param string              $schema  The schema to save in.
	 * @param array<string,mixed> $payload The object payload.
	 *
	 * @return mixed Whatever ObjectService::saveObject returned.
	 */
	private function saveRow(string $schema, array $payload): mixed {
		return $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->saveObject($payload);
	}//end saveRow()

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
			return $this->postAdjustments(count: $count);
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
	 * Post the variance adjustments of a count: validate it, read its lines
	 * and post each line in turn, stopping at the first denied line.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload being transitioned.
	 *
	 * @return bool True when every pending line was posted; false on a denial.
	 */
	private function postAdjustments(array $count): bool {
		$run = $this->openAdjustmentRun(count: $count);
		if ($run === null) {
			return false;
		}

		$lines = $this->findLinesForCount(administrationId: $run->administrationId, countId: $run->countId);
		if ($lines === []) {
			$this->logger->info(
				'CycleCountService: emit-adjustments skipped — no lines',
				['countId' => $run->countId]
			);
			return true;
		}

		return $this->postLineAdjustments(run: $run, lines: $lines);
	}//end postAdjustments()

	/**
	 * Start an adjustment run for a count, or deny when its countId or
	 * administrationId is missing.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload.
	 *
	 * @return CycleCountAdjustmentRun|null The run, or null when denied.
	 */
	private function openAdjustmentRun(array $count): ?CycleCountAdjustmentRun {
		$run = new CycleCountAdjustmentRun(
			countId: (string)($count['countId'] ?? ''),
			administrationId: (string)($count['administrationId'] ?? '')
		);
		if ($run->countId === '' || $run->administrationId === '') {
			$this->logger->info(
				'CycleCountService: emit-adjustments denied — required fields missing',
				['countId' => ($count['countId'] ?? null)]
			);
			return null;
		}

		return $run;
	}//end openAdjustmentRun()

	/**
	 * Post every line of the run in order, stopping at the first denial.
	 *
	 * @param CycleCountAdjustmentRun $run   The adjustment run.
	 * @param array<int,mixed>        $lines The count's lines.
	 *
	 * @return bool True when no line was denied.
	 */
	private function postLineAdjustments(CycleCountAdjustmentRun $run, array $lines): bool {
		foreach ($lines as $line) {
			if (is_array($line) === true && $this->postLineAdjustment(run: $run, line: $line) === false) {
				return false;
			}
		}

		return true;
	}//end postLineAdjustments()

	/**
	 * Post the variance StockMove of one line. Lines already posted, not yet
	 * counted or without variance are skipped and take no sequence number.
	 *
	 * @param CycleCountAdjustmentRun $run  The adjustment run.
	 * @param array<string,mixed>     $line The count line.
	 *
	 * @return bool False when the line denies the run; true otherwise.
	 */
	private function postLineAdjustment(CycleCountAdjustmentRun $run, array $line): bool {
		$varianceCents = $this->pendingVarianceCents(line: $line);
		if ($varianceCents === null) {
			return true;
		}

		$run->sequence++;
		$run->varianceCents = $varianceCents;
		if ((string)($line['locationId'] ?? '') === '' || (string)($line['sku'] ?? '') === '') {
			$this->logger->error(
				'CycleCountService: emit-adjustments denied — line missing sku or locationId',
				['countId' => $run->countId, 'lineId' => ($line['lineId'] ?? null)]
			);
			return false;
		}

		return $this->saveVarianceMove(run: $run, line: $line);
	}//end postLineAdjustment()

	/**
	 * The variance in cents a line still has to post, or null when the line
	 * is already posted (partial-retry idempotency), un-entered or zero.
	 *
	 * @param array<string,mixed> $line The count line.
	 *
	 * @return int|null The non-zero variance in cents, or null to skip.
	 */
	private function pendingVarianceCents(array $line): ?int {
		if (trim((string)($line['adjustmentStockMoveId'] ?? '')) !== '') {
			return null;
		}

		$varianceCents = $this->lineVarianceCents(line: $line);
		if ($varianceCents === 0) {
			return null;
		}

		return $varianceCents;
	}//end pendingVarianceCents()

	/**
	 * The counted minus expected quantity of a line in cents, or null when
	 * the line is un-entered.
	 *
	 * Un-entered lines are skipped silently: the post-time VarianceGate
	 * already refused any flagged line without reason; missing counted
	 * values are treated as zero-variance for reconciliation purposes.
	 *
	 * @param array<string,mixed> $line The count line.
	 *
	 * @return int|null The variance in cents, or null when un-entered.
	 */
	private function lineVarianceCents(array $line): ?int {
		$countedQ = $this->lines()->numericOrNull(value: $line['countedQuantity'] ?? null);
		$expectedQ = $this->lines()->numericOrNull(value: $line['expectedQuantity'] ?? null);
		if ($countedQ === null || $expectedQ === null) {
			return null;
		}

		return ($this->lines()->cents(value: $countedQ) - $this->lines()->cents(value: $expectedQ));
	}//end lineVarianceCents()

	/**
	 * Save the line's variance StockMove and stamp its id back on the line.
	 *
	 * @param CycleCountAdjustmentRun $run  The adjustment run.
	 * @param array<string,mixed>     $line The count line.
	 *
	 * @return bool False when the save returned no id; true otherwise.
	 */
	private function saveVarianceMove(CycleCountAdjustmentRun $run, array $line): bool {
		$saved = $this->saveRow(schema: 'StockMove', payload: $this->varianceMove(run: $run, line: $line));

		$stockMoveId = $this->extractId(saved: $saved);
		if ($stockMoveId === '') {
			$this->logger->error(
				'CycleCountService: emit-adjustments — saveObject returned no id',
				['countId' => $run->countId, 'lineId' => ($line['lineId'] ?? null)]
			);
			return false;
		}

		$this->stampAdjustmentMove(line: $line, stockMoveId: $stockMoveId);
		return true;
	}//end saveVarianceMove()

	/**
	 * Stamp the back-reference on the line so a future retry skips it.
	 *
	 * @param array<string,mixed> $line        The count line.
	 * @param string              $stockMoveId The id of the posted StockMove.
	 *
	 * @return void
	 */
	private function stampAdjustmentMove(array $line, string $stockMoveId): void {
		$lineUpdate = $line;
		$lineUpdate['adjustmentStockMoveId'] = $stockMoveId;
		$this->objectService
			->setRegister($this->register())
			->setSchema('InventoryCycleCountLine')
			->saveObject($lineUpdate);
	}//end stampAdjustmentMove()

	/**
	 * The draft StockMove payload for the line being posted.
	 *
	 * @param CycleCountAdjustmentRun $run  The adjustment run.
	 * @param array<string,mixed>     $line The count line.
	 *
	 * @return array<string,mixed> The StockMove payload.
	 */
	private function varianceMove(CycleCountAdjustmentRun $run, array $line): array {
		$quantities = [
			'movementNumber' => sprintf('SM-CC-%s-%03d', $run->countId, $run->sequence),
			'itemId' => (string)($line['sku'] ?? ''),
			'quantity' => abs($this->lines()->fromCents(cents: $run->varianceCents)),
			'unitCost' => $this->lines()->numericOrZero(value: $line['unitCost'] ?? null),
		];

		return $quantities
			+ $this->varianceDirection(run: $run, locationId: (string)($line['locationId'] ?? ''))
			+ $this->varianceProvenance(run: $run, line: $line);
	}//end varianceMove()

	/**
	 * The movement type and locations of a variance: positive (found stock) is
	 * a receipt to the location, negative (shrinkage) an issue from it.
	 *
	 * @param CycleCountAdjustmentRun $run        The adjustment run.
	 * @param string                  $locationId The counted location.
	 *
	 * @return array<string,string|null> movementType, sourceLocationId, destinationLocationId.
	 */
	private function varianceDirection(CycleCountAdjustmentRun $run, string $locationId): array {
		if ($run->varianceCents > 0) {
			return [
				'movementType' => 'receipt',
				'sourceLocationId' => null,
				'destinationLocationId' => $locationId,
			];
		}

		return [
			'movementType' => 'issue',
			'sourceLocationId' => $locationId,
			'destinationLocationId' => null,
		];
	}//end varianceDirection()

	/**
	 * The provenance fields of a variance StockMove (reference, reason, notes,
	 * draft state).
	 *
	 * @param CycleCountAdjustmentRun $run  The adjustment run.
	 * @param array<string,mixed>     $line The count line.
	 *
	 * @return array<string,mixed> The provenance fields.
	 */
	private function varianceProvenance(CycleCountAdjustmentRun $run, array $line): array {
		return [
			'referenceDocumentUri' => sprintf('shillinq://cycle-count/%s', $run->countId),
			'movementReason' => self::VARIANCE_REASON,
			'notes' => $this->varianceNote(line: $line),
			'draftedAt' => $this->now(),
			'administrationId' => $run->administrationId,
			'lifecycleState' => 'draft',
			'locked' => false,
		];
	}//end varianceProvenance()

	/**
	 * The note on a variance StockMove naming the line and its reason code.
	 *
	 * @param array<string,mixed> $line The count line.
	 *
	 * @return string The note.
	 */
	private function varianceNote(array $line): string {
		return sprintf(
			'Variance adjustment for line %s (reasonCode=%s)',
			(string)($line['lineId'] ?? ''),
			(string)($line['reasonCode'] ?? 'n/a')
		);
	}//end varianceNote()

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
			$this->saveRow(schema: 'InventoryCycleCountLine', payload: $refreshed);
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
	 * @param CycleCountScope $scope The count being snapshotted.
	 *
	 * @return array<int,array<string,mixed>> The rows, or [] when the lookup fails.
	 */
	private function findInScopeStock(CycleCountScope $scope): array {
		try {
			return $this->readScopeStock(scope: $scope);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: findInScopeStock failed',
				[
					'administrationId' => $scope->administrationId,
					'countType' => $scope->countType,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end findInScopeStock()

	/**
	 * Read the stock rows of the scope, applying the category filter when the
	 * count is scoped by category alone.
	 *
	 * @param CycleCountScope $scope The count being snapshotted.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function readScopeStock(CycleCountScope $scope): array {
		// ADR-084: findAll() is declared `: array`, never null. Its ELEMENTS
		// are ObjectEntity rows, not arrays: snapshotScope() skips any row
		// that is not an array, so without normalising every snapshot produced
		// zero lines on a real instance.
		$rows = $this->findRows(schema: 'InventoryStock', filters: $scope->stockFilters());

		// Category filter requires a Product lookup. Fall back to client-side
		// filter (the volume of stock rows per administration is bounded by SKU
		// count + location count, which is small for the SMB target).
		if ($scope->filtersByCategoryOnly() === true) {
			$rows = $this->filterByCategory(stockRows: $rows, scope: $scope);
		}

		return $rows;
	}//end readScopeStock()

	/**
	 * Client-side category filter for partial counts scoped by category.
	 * Reads the Product schema for each distinct sku in the stock rows and
	 * keeps only the stock rows whose product's category matches.
	 *
	 * @param array<int,array<string,mixed>> $stockRows Pre-filtered stock rows.
	 * @param CycleCountScope                $scope     The count, carrying administration and category.
	 *
	 * @return array<int,array<string,mixed>> The matching rows, or [] when a lookup fails.
	 */
	private function filterByCategory(array $stockRows, CycleCountScope $scope): array {
		try {
			return $this->rowsInCategory(stockRows: $stockRows, scope: $scope);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CycleCountService: filterByCategory failed',
				[
					'administrationId' => $scope->administrationId,
					'category' => $scope->categoryFilter,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}//end try

	}//end filterByCategory()

	/**
	 * Keep the stock rows whose product carries the scope's category.
	 *
	 * @param array<int,array<string,mixed>> $stockRows Pre-filtered stock rows.
	 * @param CycleCountScope                $scope     The count, carrying administration and category.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsInCategory(array $stockRows, CycleCountScope $scope): array {
		$skuToCategory = [];
		foreach ($this->distinctSkus(stockRows: $stockRows) as $sku) {
			$skuToCategory[$sku] = $this->productCategory(sku: $sku, administrationId: $scope->administrationId);
		}

		$category = $scope->categoryFilter;
		return array_values(
			array_filter(
				$stockRows,
				static fn (array $row): bool => isset($row['sku']) === true
					&& ($skuToCategory[(string)$row['sku']] ?? '') === $category
			)
		);
	}//end rowsInCategory()

	/**
	 * The distinct non-empty skus of the stock rows, in first-seen order.
	 *
	 * @param array<int,array<string,mixed>> $stockRows Stock rows.
	 *
	 * @return list<string>
	 */
	private function distinctSkus(array $stockRows): array {
		$skus = [];
		foreach ($stockRows as $row) {
			if (isset($row['sku']) === true && (string)$row['sku'] !== '') {
				$skus[] = (string)$row['sku'];
			}
		}

		return array_values(array_unique($skus));
	}//end distinctSkus()

	/**
	 * The category of the administration's product with this sku ('' when the
	 * product is unknown or has no category).
	 *
	 * @param string $sku              The sku.
	 * @param string $administrationId Administration scope.
	 *
	 * @return string
	 */
	private function productCategory(string $sku, string $administrationId): string {
		// ADR-084: this passed a filter ARRAY to find(int|string $id), which
		// looks up a single object by identifier — it never applied the
		// filters, so every SKU was categorised ''. findAll() is the filtered
		// lookup; its rows are entities, so they are normalised before the
		// category is read.
		$filters = [
			'administrationId' => $administrationId,
			'sku' => $sku,
		];
		$product = ($this->findRows(schema: 'Product', filters: $filters)[0] ?? null);
		if (is_array($product) === true && isset($product['category']) === true) {
			return (string)$product['category'];
		}

		return '';
	}//end productCategory()

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
			// ADR-084: findAll() rows are entities; emitAdjustments() skips any
			// line that is not an array, so findRows() normalises them or no
			// adjustment is ever posted.
			$filters = [
				'administrationId' => $administrationId,
				'countId' => $countId,
			];
			return $this->findRows(schema: 'InventoryCycleCountLine', filters: $filters);
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
	 * Query one schema with filters and normalise the rows to arrays.
	 *
	 * @param string              $schema  The schema to query.
	 * @param array<string,mixed> $filters The findAll() filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findRows(string $schema, array $filters): array {
		return $this->asArrays(
			rows: $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters])
		);
	}//end findRows()

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
	 * Normalise OpenRegister rows to plain arrays.
	 *
	 * A row that is already an array passes through; an entity yields its
	 * jsonSerialize() payload; anything else is dropped rather than handed on
	 * as an empty row.
	 *
	 * @param array<int|string,mixed> $rows Rows from ObjectService::findAll().
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function asArrays(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$array = $this->rowAsArray(row: $row);
			if ($array !== null) {
				$out[] = $array;
			}
		}

		return $out;
	}//end asArrays()

	/**
	 * One row as an array: an array as is, an entity as its jsonSerialize()
	 * payload, anything else as null.
	 *
	 * @param mixed $row One row from ObjectService::findAll().
	 *
	 * @return array<string,mixed>|null
	 */
	private function rowAsArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === false) {
			return null;
		}

		$serialized = $row->jsonSerialize();
		if (is_array($serialized) === true) {
			return $serialized;
		}

		return null;
	}//end rowAsArray()

	/**
	 * The stateless helper that builds snapshot lines and does the cent arithmetic.
	 *
	 * @return CycleCountLines
	 */
	private function lines(): CycleCountLines {
		return new CycleCountLines();
	}//end lines()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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

}//end class
