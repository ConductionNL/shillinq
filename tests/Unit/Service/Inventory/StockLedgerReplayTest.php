<?php

/**
 * Unit tests for StockLedgerReplay.
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

use OCA\Shillinq\Service\Inventory\StockLedgerReplay;
use PHPUnit\Framework\TestCase;

/**
 * The replay of one product at one location, at FIFO and at moving average.
 *
 * @covers \OCA\Shillinq\Service\Inventory\StockLedgerReplay
 */
final class StockLedgerReplayTest extends TestCase {

	/**
	 * A posted move.
	 *
	 * @param string $uuid     The move.
	 * @param string $type     receipt or issue.
	 * @param float  $quantity The quantity.
	 * @param float  $unitCost The unit cost (receipts).
	 * @param string $day      The posting day.
	 *
	 * @return array<string, mixed>
	 */
	private static function move(string $uuid, string $type, float $quantity, float $unitCost, string $day): array {
		return ['uuid' => $uuid, 'movementType' => $type, 'quantity' => $quantity, 'unitCost' => $unitCost, 'postedAt' => $day.'T09:00:00Z'];
	}//end move()

	/**
	 * The design's flour: the issue of 50 bags costs EUR 932.50 and 30 bags
	 * worth EUR 577.50 are left, whatever order the moves come in.
	 *
	 * @return void
	 */
	public function testFifoCostsTheIssueFromTheOldestLayers(): void {
		$result = (new StockLedgerReplay(method: 'FIFO'))->replay(
			moves: [
				self::move('i-1', 'issue', 50, 0, '2026-09-20'),
				self::move('r-2', 'receipt', 40, 19.25, '2026-09-16'),
				self::move('r-1', 'receipt', 40, 18.50, '2026-09-02'),
			]
		);

		$this->assertSame(['i-1' => 93250], $result['issueCosts']);
		$this->assertSame(30.0, $result['quantity']);
		$this->assertSame(577.5, $result['totalValue']);
		$this->assertSame(19.25, $result['unitCost']);
		$this->assertSame('i-1', $result['lastMove']['uuid']);
	}//end testFifoCostsTheIssueFromTheOldestLayers()

	/**
	 * At moving average the issue costs the average of what came in before it.
	 *
	 * @return void
	 */
	public function testAverageCostsTheIssueAtTheRunningAverage(): void {
		$result = (new StockLedgerReplay(method: 'average'))->replay(
			moves: [
				self::move('r-1', 'receipt', 40, 18.50, '2026-09-02'),
				self::move('r-2', 'receipt', 40, 19.25, '2026-09-16'),
				self::move('i-1', 'issue', 50, 0, '2026-09-20'),
			]
		);

		$this->assertSame(['i-1' => 94375], $result['issueCosts']);
		$this->assertSame(30.0, $result['quantity']);
		$this->assertSame(18.875, $result['unitCost']);
		$this->assertSame(566.25, $result['totalValue']);
	}//end testAverageCostsTheIssueAtTheRunningAverage()

	/**
	 * On the same moment a receipt is counted before an issue, and an issue
	 * past the layers is costed only for what they hold.
	 *
	 * @return void
	 */
	public function testAReceiptAtTheSameMomentCountsFirstAndAShortIssueStopsAtTheLayers(): void {
		$result = (new StockLedgerReplay(method: 'FIFO'))->replay(
			moves: [
				self::move('i-1', 'issue', 15, 0, '2026-09-02'),
				self::move('r-1', 'receipt', 10, 2.00, '2026-09-02'),
			]
		);

		$this->assertSame(['i-1' => 2000], $result['issueCosts']);
		$this->assertSame(0.0, $result['quantity']);
		$this->assertSame(0.0, $result['totalValue']);
		$this->assertSame(0.0, $result['unitCost']);
	}//end testAReceiptAtTheSameMomentCountsFirstAndAShortIssueStopsAtTheLayers()

	/**
	 * Nothing to replay leaves nothing, and empty quantities are ignored.
	 *
	 * @return void
	 */
	public function testNoMovesAndEmptyQuantities(): void {
		$byId = (new StockLedgerReplay(method: 'FIFO'))->replay(moves: [['id' => 'i-9', 'movementType' => 'issue', 'quantity' => 1, 'postedAt' => '2026-09-01']]);
		$this->assertSame(['i-9' => 0], $byId['issueCosts']);

		$empty = (new StockLedgerReplay(method: 'FIFO'))->replay(moves: []);
		$this->assertSame(['quantity' => 0.0, 'unitCost' => 0.0, 'totalValue' => 0.0, 'issueCosts' => [], 'lastMove' => null], $empty);

		$zero = (new StockLedgerReplay(method: 'average'))->replay(
			moves: [self::move('r-1', 'receipt', 0, 5, '2026-09-01'), self::move('i-1', 'issue', 0, 0, '2026-09-02')]
		);
		$this->assertSame(['i-1' => 0], $zero['issueCosts']);
		$this->assertSame(0.0, $zero['quantity']);
	}//end testNoMovesAndEmptyQuantities()
}//end class
