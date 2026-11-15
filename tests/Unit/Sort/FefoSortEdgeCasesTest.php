<?php

/**
 * Edge-case tests for FefoSort.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);


namespace OCA\Shillinq\Tests\Unit\Sort;

use OCA\Shillinq\Sort\FefoSort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * FEFO order for undated lots, mixed input order, keys and full rows.
 */
#[CoversClass(FefoSort::class)]
class FefoSortEdgeCasesTest extends TestCase {

	/**
	 * The lot numbers in the order the sort returns them.
	 *
	 * @param array<int, array<string, mixed>> $lots The lots to sort.
	 *
	 * @return array<int, string>
	 */
	private function order(array $lots): array {
		return array_column((new FefoSort())->sortLots($lots), 'lotNumber');
	}//end order()

	/**
	 * Undated lots keep their input order among themselves, after every dated lot.
	 *
	 * @return void
	 */
	public function testUndatedLotsKeepTheirOrderAfterTheDatedOnes(): void {
		$lots = [
			['lotNumber' => 'U1'],
			['lotNumber' => 'U2', 'expiryDate' => ''],
			['lotNumber' => 'D2', 'expiryDate' => '2027-03-01'],
			['lotNumber' => 'U3', 'expiryDate' => null],
			['lotNumber' => 'D1', 'expiryDate' => '2026-12-31'],
		];

		$this->assertSame(['D1', 'D2', 'U1', 'U2', 'U3'], $this->order($lots));
	}//end testUndatedLotsKeepTheirOrderAfterTheDatedOnes()

	/**
	 * A dated lot listed after an undated one still comes first, whichever side
	 * of the comparison the undated lot is on.
	 *
	 * @return void
	 */
	public function testADatedLotPrecedesAnUndatedOneInEitherInputOrder(): void {
		$this->assertSame(['D', 'U'], $this->order([['lotNumber' => 'D', 'expiryDate' => '2030-01-01'], ['lotNumber' => 'U']]));
		$this->assertSame(['D', 'U'], $this->order([['lotNumber' => 'U'], ['lotNumber' => 'D', 'expiryDate' => '2030-01-01']]));
	}//end testADatedLotPrecedesAnUndatedOneInEitherInputOrder()

	/**
	 * Only undated lots come back unchanged.
	 *
	 * @return void
	 */
	public function testOnlyUndatedLotsAreReturnedInInputOrder(): void {
		$this->assertSame(['A', 'B', 'C'], $this->order([['lotNumber' => 'A'], ['lotNumber' => 'B', 'expiryDate' => ''], ['lotNumber' => 'C']]));
	}//end testOnlyUndatedLotsAreReturnedInInputOrder()

	/**
	 * Expiry dates compare as ISO strings, so a timestamp on the same day sorts after the bare date.
	 *
	 * @return void
	 */
	public function testIsoTimestampsCompareChronologically(): void {
		$lots = [
			['lotNumber' => 'LATE', 'expiryDate' => '2027-01-15T18:00:00Z'],
			['lotNumber' => 'DAY', 'expiryDate' => '2027-01-15'],
			['lotNumber' => 'EARLY', 'expiryDate' => '2027-01-15T06:00:00Z'],
			['lotNumber' => 'PREV', 'expiryDate' => '2027-01-14T23:59:59Z'],
		];

		$this->assertSame(['PREV', 'DAY', 'EARLY', 'LATE'], $this->order($lots));
	}//end testIsoTimestampsCompareChronologically()

	/**
	 * The result is a list re-keyed from 0 and every row keeps all its fields.
	 *
	 * @return void
	 */
	public function testTheResultIsAReindexedListOfWholeRows(): void {
		$lots = [
			7 => ['lotNumber' => 'B', 'expiryDate' => '2027-02-01', 'quantity' => 4],
			3 => ['lotNumber' => 'A', 'expiryDate' => '2027-01-01', 'quantity' => 9],
		];

		$sorted = (new FefoSort())->sortLots($lots);

		$this->assertSame(
			[
				['lotNumber' => 'A', 'expiryDate' => '2027-01-01', 'quantity' => 9],
				['lotNumber' => 'B', 'expiryDate' => '2027-02-01', 'quantity' => 4],
			],
			$sorted
		);
	}//end testTheResultIsAReindexedListOfWholeRows()
}//end class
