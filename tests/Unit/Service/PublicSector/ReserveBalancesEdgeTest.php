<?php

/**
 * Unit tests for ReserveBalances' edge cases: a mutation that is already
 * booked, a mutation whose reserve is gone, a year before the opening year,
 * and an opening balance stored in cents.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReserveBalances' edge cases.
 */
#[CoversClass(ReserveBalances::class)]
final class ReserveBalancesEdgeTest extends TestCase {

	/**
	 * A mutation that already carries its journal entry is returned as is,
	 * without reading or booking anything.
	 *
	 * @return void
	 */
	public function testAnAlreadyBookedMutationIsReturnedUnchanged(): void {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->expects($this->never())->method('find');
		$records->expects($this->never())->method('postJournal');

		$mutation = ['reserveId' => 'r-1', 'amount' => 100.0, 'journalEntryId' => 'je-9'];

		$this->assertSame($mutation, (new ReserveBalances($records))->realise($mutation));
	}//end testAnAlreadyBookedMutationIsReturnedUnchanged()

	/**
	 * A mutation whose reserve cannot be found is refused before any booking.
	 *
	 * @return void
	 */
	public function testAMutationWithoutItsReserveIsRefused(): void {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->expects($this->once())->method('find')->with('Reserve', 'r-gone')->willReturn(null);
		$records->expects($this->never())->method('postJournal');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The reserve of this mutation does not exist.');

		(new ReserveBalances($records))->realise(['reserveId' => 'r-gone', 'amount' => 100.0, 'journalEntryId' => '']);
	}//end testAMutationWithoutItsReserveIsRefused()

	/**
	 * Before the opening year a reserve has no balance and no mutations are read.
	 *
	 * @return void
	 */
	public function testABalanceBeforeTheOpeningYearIsZero(): void {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->expects($this->never())->method('records');
		$balances = new ReserveBalances($records);

		$this->assertSame(0.0, $balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalanceYear' => 2025, 'openingBalance' => 500.0], 2024));
		// Without an opening year the current year opens it; any earlier year is before it.
		$this->assertSame(0.0, $balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalance' => 500.0], 1999));
	}//end testABalanceBeforeTheOpeningYearIsZero()

	/**
	 * An opening balance kept in cents opens the reserve when no euro amount is set.
	 *
	 * @return void
	 */
	public function testAnOpeningBalanceInCentsIsUsed(): void {
		$records = $this->createMock(PublicSectorRecords::class);
		$records->method('records')->willReturn([]);
		$balances = new ReserveBalances($records);

		$this->assertSame(123.45, $balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalanceYear' => 2025, 'balanceYearStartCents' => 12345], 2025));
		$this->assertSame(123.45, $balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalanceYear' => 2025, 'balanceYearStartCents' => 12345], 2027));
		$this->assertSame(
			80.0,
			$balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalanceYear' => 2025, 'openingBalance' => 80.0, 'balanceYearStartCents' => 12345], 2025)
		);
		$this->assertSame(0.0, $balances->balanceOnFirstJanuary(['id' => 'r-1', 'openingBalanceYear' => 2025], 2025));
	}//end testAnOpeningBalanceInCentsIsUsed()
}//end class
