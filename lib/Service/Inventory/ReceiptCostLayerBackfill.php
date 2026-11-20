<?php

/**
 * Receipt Cost Layer Backfill
 *
 * Receipts posted before inventory-receipt-cost-layers were written straight
 * as posted, so the valuation engine never saw them: the valuation snapshots
 * miss their layers and an issue after them could be costed without them.
 * This replays every product's posted receipts and issues per location in
 * posting order, writes the snapshot the layers give, and lists the issues
 * whose posted cost of goods sold differs from the cost the layers give now.
 * It posts nothing: correcting a past COGS entry is an accountant's journal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Inventory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Inventory;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReceiptCostLayerBackfillInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\InventoryValuationStore;
use OCA\Shillinq\Service\StockMoveValuationKey;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Replays the stock ledger into the valuation snapshots, once.
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */
class ReceiptCostLayerBackfill implements ReceiptCostLayerBackfillInterface {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface    $objectService    OpenRegister's object service.
	 * @param RegisterLocationInterface $registerLocation The app's register.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $registerLocation,
	) {
	}//end __construct()

	/**
	 * Replay every product at every location and write its snapshot.
	 *
	 * @return array{locations: int, receipts: int, undercosted: array<int, array<string, mixed>>}
	 *         `undercosted` lists each issue whose posted COGS differs from the
	 *         layer-based one: move, movementNumber, postedCogs, layerCogs (EUR).
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
	 */
	public function run(): array {
		$result = ['locations' => 0, 'receipts' => 0, 'undercosted' => []];
		foreach ($this->groups() as $moves) {
			$result['locations']++;
			$result['receipts'] += count(array_filter($moves, static fn (array $move): bool => ($move['movementType'] ?? '') === 'receipt'));
			array_push($result['undercosted'], ...$this->replayLocation(moves: $moves));
		}

		return $result;
	}//end run()

	/**
	 * The posted receipts and issues, by administration, product and location.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function groups(): array {
		$store  = $this->store();
		$groups = [];
		foreach (['receipt', 'issue'] as $type) {
			$rows = $this->rows(schema: 'StockMove', filters: ['movementType' => $type, 'lifecycleState' => 'posted']);
			foreach ($rows as $row) {
				$move = $store->toArray(row: $row);
				$key  = new StockMoveValuationKey(move: $move);
				if ($key->isComplete() === true) {
					$groups[$key->administrationId."\n".$key->productId."\n".$key->warehouse][] = $move;
				}
			}
		}

		return $groups;
	}//end groups()

	/**
	 * Replay one location, write its snapshot, and list its miscosted issues.
	 *
	 * @param array<int, array<string, mixed>> $moves The location's posted moves.
	 *
	 * @return array<int, array<string, mixed>> The issues whose COGS differs.
	 */
	private function replayLocation(array $moves): array {
		$store     = $this->store();
		$first     = new StockMoveValuationKey(move: $moves[0]);
		$valuation = $store->findActive(key: $first, register: $this->register());
		$method    = (string)($valuation['valuationMethod'] ?? 'FIFO');
		$replay    = (new StockLedgerReplay(method: $method))->replay(moves: $moves);

		$valuation = ($valuation ?? $first->newValuation(valuationMethod: $method));
		$valuation['quantity']   = $replay['quantity'];
		$valuation['unitCost']   = $replay['unitCost'];
		$valuation['totalValue'] = $replay['totalValue'];
		$store->save(data: (new StockMoveValuationKey(move: $replay['lastMove']))->stamp(valuation: $valuation), register: $this->register());

		$miscosted = [];
		foreach ($moves as $move) {
			if (($move['movementType'] ?? '') !== 'issue') {
				continue;
			}

			$uuid        = (new StockMoveValuationKey(move: $move))->moveUuid;
			$layerCents  = ($replay['issueCosts'][$uuid] ?? 0);
			$postedCents = $this->postedCogsCents(move: $move);
			if ($postedCents !== $layerCents) {
				$miscosted[] = [
					'move'           => $uuid,
					'movementNumber' => (string)($move['movementNumber'] ?? ''),
					'postedCogs'     => round($postedCents / 100, 2),
					'layerCogs'      => round($layerCents / 100, 2),
				];
			}
		}

		return $miscosted;
	}//end replayLocation()

	/**
	 * The COGS an issue carried, in cents: the debit lines of the ledger
	 * transactions CogsPosterService wrote for it, found by their
	 * `sourceReference`. 0 when none was written.
	 *
	 * @param array<string, mixed> $move The issue.
	 *
	 * @return int
	 */
	private function postedCogsCents(array $move): int {
		$reference = (string)($move['id'] ?? ($move['movementNumber'] ?? ''));
		if ($reference === '') {
			return 0;
		}

		$cents = 0;
		$filters      = ['sourceReference' => $reference, 'administrationId' => (string)($move['administrationId'] ?? '')];
		$transactions = $this->rows(schema: 'GLTransaction', filters: $filters);
		foreach ($transactions as $transaction) {
			$lines = $this->rows(schema: 'GLLine', filters: ['transactionId' => ObjectIdentifier::resolve(saved: $transaction), 'side' => 'debit']);
			foreach ($lines as $line) {
				$cents += (int)round((float)($this->store()->toArray(row: $line)['amount'] ?? 0) * 100);
			}
		}

		return $cents;
	}//end postedCogsCents()

	/**
	 * Every row of a schema matching the filters, read past RBAC in batches.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, mixed>
	 */
	private function rows(string $schema, array $filters): array {
		return $this->readAllRows(objectService: $this->objectService, registerSlug: $this->register(), schema: $schema, filters: $filters);
	}//end rows()

	/**
	 * The valuation snapshots.
	 *
	 * @return InventoryValuationStore
	 */
	private function store(): InventoryValuationStore {
		return new InventoryValuationStore(objectService: $this->objectService, source: 'ReceiptCostLayerBackfill');
	}//end store()

	/**
	 * The app's register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		return $this->registerLocation->getRegisterSlug();
	}//end register()
}//end class
