<?php

/**
 * Unit tests for FefoSort.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Sort
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-lot-batch-expiry/specs/inventory-lot-batch-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Sort;

use OCA\Shillinq\Sort\FefoSort;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FefoSort.
 *
 * Covers REQ-LOT-005:
 * - Three lots with different expiry dates are returned in ascending order.
 * - Lots without an expiryDate sort after all dated lots (NULL last).
 * - Equal-expiry lots preserve input order (stable).
 */
class FefoSortTest extends TestCase {
	/**
	 * The sort under test.
	 *
	 * @var FefoSort
	 */
	private FefoSort $sort;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sort = new FefoSort();

	}//end setUp()

	/**
	 * REQ-LOT-005: ascending expiryDate order across three lots.
	 *
	 * @return void
	 */
	public function testSortsThreeLotsAscendingExpiryDate(): void {
		$lots = [
			['lotNumber' => 'LOT-A', 'expiryDate' => '2027-01-15'],
			['lotNumber' => 'LOT-B', 'expiryDate' => '2026-06-15'],
			['lotNumber' => 'LOT-C', 'expiryDate' => '2027-09-01'],
		];

		$sorted = $this->sort->sortLots($lots);

		self::assertSame('LOT-B', $sorted[0]['lotNumber']);
		self::assertSame('LOT-A', $sorted[1]['lotNumber']);
		self::assertSame('LOT-C', $sorted[2]['lotNumber']);

	}//end testSortsThreeLotsAscendingExpiryDate()

	/**
	 * REQ-LOT-005: NULL-last — lots with no expiryDate sort after dated lots.
	 *
	 * @return void
	 */
	public function testNullExpiryDateSortsLast(): void {
		$lots = [
			['lotNumber' => 'LOT-NULL', 'expiryDate' => null],
			['lotNumber' => 'LOT-DATED', 'expiryDate' => '2027-01-15'],
		];

		$sorted = $this->sort->sortLots($lots);

		self::assertSame('LOT-DATED', $sorted[0]['lotNumber']);
		self::assertSame('LOT-NULL', $sorted[1]['lotNumber']);

	}//end testNullExpiryDateSortsLast()

	/**
	 * REQ-LOT-005: missing expiryDate key is treated as null.
	 *
	 * @return void
	 */
	public function testMissingExpiryDateKeyTreatedAsNull(): void {
		$lots = [
			['lotNumber' => 'LOT-NO-KEY'],
			['lotNumber' => 'LOT-DATED', 'expiryDate' => '2027-01-15'],
			['lotNumber' => 'LOT-EMPTY', 'expiryDate' => ''],
		];

		$sorted = $this->sort->sortLots($lots);

		self::assertSame('LOT-DATED', $sorted[0]['lotNumber']);
		// Both null/missing sort after the dated lot.
		$tailIds = [$sorted[1]['lotNumber'], $sorted[2]['lotNumber']];
		sort($tailIds);
		self::assertSame(['LOT-EMPTY', 'LOT-NO-KEY'], $tailIds);

	}//end testMissingExpiryDateKeyTreatedAsNull()

	/**
	 * Sorting an empty list returns an empty list.
	 *
	 * @return void
	 */
	public function testEmptyListReturnsEmpty(): void {
		self::assertSame([], $this->sort->sortLots([]));

	}//end testEmptyListReturnsEmpty()

	/**
	 * Equal-expiry lots preserve input order (stable sort).
	 *
	 * @return void
	 */
	public function testEqualExpiryLotsPreserveInputOrder(): void {
		$lots = [
			['lotNumber' => 'LOT-FIRST', 'expiryDate' => '2027-01-15'],
			['lotNumber' => 'LOT-SECOND', 'expiryDate' => '2027-01-15'],
			['lotNumber' => 'LOT-THIRD', 'expiryDate' => '2027-01-15'],
		];

		$sorted = $this->sort->sortLots($lots);

		self::assertSame('LOT-FIRST', $sorted[0]['lotNumber']);
		self::assertSame('LOT-SECOND', $sorted[1]['lotNumber']);
		self::assertSame('LOT-THIRD', $sorted[2]['lotNumber']);

	}//end testEqualExpiryLotsPreserveInputOrder()
}//end class
