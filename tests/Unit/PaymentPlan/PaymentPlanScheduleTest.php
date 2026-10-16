<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use InvalidArgumentException;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use PHPUnit\Framework\TestCase;

/**
 * The schedule adds up to the cent (design.md D3).
 */
final class PaymentPlanScheduleTest extends TestCase {
	/**
	 * Six monthly instalments of 2,420.00: five of 403.33 and a last of 403.35.
	 *
	 * @return void
	 */
	public function testSixMonthlyInstalmentsLeaveTheDifferenceOnTheLast(): void {
		$rows = (new PaymentPlanSchedule())->build(2420.00, 'monthly', '2026-11-01', 6, null);

		$this->assertSame([403.33, 403.33, 403.33, 403.33, 403.33, 403.35], array_column($rows, 'amount'));
		$this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01'], array_column($rows, 'dueDate'));
		$this->assertSame([1, 2, 3, 4, 5, 6], array_column($rows, 'instalmentNumber'));
	}//end testSixMonthlyInstalmentsLeaveTheDifferenceOnTheLast()

	/**
	 * By amount: 2,420.00 at 500.00 a week is four of 500.00 and 420.00.
	 *
	 * @return void
	 */
	public function testAnInstalmentAmountSetsTheCountAndTheRemainder(): void {
		$rows = (new PaymentPlanSchedule())->build(2420.00, 'weekly', '2026-11-02', null, 500.00);

		$this->assertSame([500.0, 500.0, 500.0, 500.0, 420.0], array_column($rows, 'amount'));
		$this->assertSame('2026-11-30', $rows[4]['dueDate']);
	}//end testAnInstalmentAmountSetsTheCountAndTheRemainder()

	/**
	 * A first due date on the 31st falls on the month's last day in shorter months.
	 *
	 * @return void
	 */
	public function testMonthEndDatesStayInTheirMonth(): void {
		$rows = (new PaymentPlanSchedule())->build(300.00, 'monthly', '2027-01-31', 3, null);

		$this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_column($rows, 'dueDate'));
	}//end testMonthEndDatesStayInTheirMonth()

	/**
	 * The balance check the activate guard uses.
	 *
	 * @return void
	 */
	public function testBalancesToTheCent(): void {
		$schedule = new PaymentPlanSchedule();

		$this->assertTrue($schedule->balances(2420.00, [403.33, 403.33, 403.33, 403.33, 403.33, 403.35]));
		$this->assertFalse($schedule->balances(2420.00, [403.33, 403.33, 403.33, 403.33, 403.33, 403.33]));
		$this->assertFalse($schedule->balances(2420.00, []));
	}//end testBalancesToTheCent()

	/**
	 * Terms that make no schedule are refused.
	 *
	 * @return void
	 */
	public function testNoCountAndNoAmountIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		(new PaymentPlanSchedule())->build(2420.00, 'monthly', '2026-11-01', null, null);
	}//end testNoCountAndNoAmountIsRefused()
}//end class
