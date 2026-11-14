<?php

/**
 * Unit tests for ReceiptCostLayerBackfill.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Inventory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Inventory;

use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\Inventory\ReceiptCostLayerBackfill;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * The backfill over a seeded register: the receipts' layers land in the
 * valuation snapshot, nothing is posted, and an issue costed without them is
 * listed with its old and its layer-based cost.
 *
 * @covers \OCA\Shillinq\Service\Inventory\ReceiptCostLayerBackfill
 * @uses   \OCA\Shillinq\Service\Inventory\StockLedgerReplay
 * @uses   \OCA\Shillinq\Service\InventoryValuationStore
 * @uses   \OCA\Shillinq\Service\StockMoveValuationKey
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ReceiptCostLayerBackfillTest extends TestCase {

	/**
	 * The seeded register.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * Seed the flour of the design: two receipts posted straight as posted,
	 * an issue of 50 costed at zero, and an average-valued product whose
	 * issue was costed right.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryObjectService();
		$move = static fn (string $id, string $type, string $item, float $quantity, float $unitCost, string $day, string $state = 'posted'): array => [
			'id'                    => $id,
			'uuid'                  => $id,
			'movementNumber'        => strtoupper($id),
			'movementType'          => $type,
			'itemId'                => $item,
			'quantity'              => $quantity,
			'unitCost'              => $unitCost,
			'sourceLocationId'      => ($type === 'issue' ? 'MAGAZIJN' : null),
			'destinationLocationId' => ($type === 'receipt' ? 'MAGAZIJN' : null),
			'postedAt'              => $day.'T09:00:00Z',
			'lifecycleState'        => $state,
			'administrationId'      => 'bakkerij-jansen',
		];
		$this->objects->seed(
			'StockMove',
			[
				$move('r-1', 'receipt', 'TARWEBLOEM', 40, 18.50, '2026-09-02'),
				$move('r-2', 'receipt', 'TARWEBLOEM', 40, 19.25, '2026-09-16'),
				$move('i-1', 'issue', 'TARWEBLOEM', 50, 0, '2026-09-20'),
				$move('r-3', 'receipt', 'GIST', 10, 3.00, '2026-09-03'),
				$move('i-2', 'issue', 'GIST', 4, 0, '2026-09-04'),
				$move('d-1', 'receipt', 'GIST', 99, 9.00, '2026-09-05', 'draft'),
			]
		);
		$this->objects->seed(
			'InventoryValuation',
			[['id' => 'v-gist', 'productId' => 'GIST', 'warehouse' => 'MAGAZIJN', 'status' => 'active', 'administrationId' => 'bakkerij-jansen', 'valuationMethod' => 'average', 'quantity' => 0, 'unitCost' => 0, 'totalValue' => 0]]
		);
		$this->objects->seed('GLTransaction', [['id' => 'tx-1', 'sourceReference' => 'i-2', 'administrationId' => 'bakkerij-jansen']]);
		$this->objects->seed(
			'GLLine',
			[
				['id' => 'l-1', 'transactionId' => 'tx-1', 'side' => 'debit', 'amount' => 12.00],
				['id' => 'l-2', 'transactionId' => 'tx-1', 'side' => 'credit', 'amount' => 12.00],
			]
		);
	}//end setUp()

	/**
	 * The backfill under test.
	 *
	 * @return ReceiptCostLayerBackfill
	 */
	private function backfill(): ReceiptCostLayerBackfill {
		$location = $this->createStub(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new ReceiptCostLayerBackfill(objectService: new DuckObjectServiceAdapter($this->objects), registerLocation: $location);
	}//end backfill()

	/**
	 * The scenario of REQ-IRCL-003: the layers exist and the zero-costed issue
	 * is listed with its old and its layer-based cost.
	 *
	 * @return void
	 */
	public function testTheLayersLandAndTheUndercostedIssueIsListed(): void {
		$result = $this->backfill()->run();

		$this->assertSame(2, $result['locations']);
		$this->assertSame(3, $result['receipts']);
		$this->assertSame([['move' => 'i-1', 'movementNumber' => 'I-1', 'postedCogs' => 0.0, 'layerCogs' => 932.5]], $result['undercosted']);

		$flour = array_values(array_filter($this->objects->dump('InventoryValuation'), static fn (array $v): bool => $v['productId'] === 'TARWEBLOEM'));
		$this->assertCount(1, $flour);
		$this->assertSame('FIFO', $flour[0]['valuationMethod']);
		$this->assertSame(30.0, $flour[0]['quantity']);
		$this->assertSame(577.5, $flour[0]['totalValue']);
		$this->assertSame('i-1', $flour[0]['lastStockMoveUuid']);
		$this->assertSame('bakkerij-jansen', $flour[0]['administrationId']);

		$yeast = array_values(array_filter($this->objects->dump('InventoryValuation'), static fn (array $v): bool => $v['productId'] === 'GIST'));
		$this->assertCount(1, $yeast);
		$this->assertSame('v-gist', $yeast[0]['id']);
		$this->assertSame(6.0, $yeast[0]['quantity']);
		$this->assertSame(18.0, $yeast[0]['totalValue']);
	}//end testTheLayersLandAndTheUndercostedIssueIsListed()

	/**
	 * The backfill posts nothing: the ledger holds what it held before.
	 *
	 * @return void
	 */
	public function testNothingIsPosted(): void {
		$this->backfill()->run();

		$this->assertCount(1, $this->objects->dump('GLTransaction'));
		$this->assertCount(2, $this->objects->dump('GLLine'));
		$this->assertCount(6, $this->objects->dump('StockMove'));
	}//end testNothingIsPosted()

	/**
	 * An empty ledger replays nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyLedgerReplaysNothing(): void {
		$this->objects = new InMemoryObjectService();

		$this->assertSame(['locations' => 0, 'receipts' => 0, 'undercosted' => []], $this->backfill()->run());
		$this->assertSame([], $this->objects->dump('InventoryValuation'));
	}//end testAnEmptyLedgerReplaysNothing()
}//end class
