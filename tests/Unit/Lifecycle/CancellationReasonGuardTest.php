<?php

/**
 * Unit tests for CancellationReasonGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OCA\Shillinq\Lifecycle\CancellationReasonGuard;
use PHPUnit\Framework\TestCase;

/**
 * Staff say why a subscription ends; a customer and the generator need not.
 *
 * @covers \OCA\Shillinq\Lifecycle\CancellationReasonGuard
 */
class CancellationReasonGuardTest extends TestCase {

	/**
	 * A staff end without a code is refused, with the reason.
	 *
	 * @return void
	 */
	public function testAStaffEndWithoutACodeIsRefused(): void {
		$result = (new CancellationReasonGuard())->check(['status' => 'ended'], 'endFromActive', 'anna');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('A cancellation reason is required to end this subscription.', $result->getMessage());
	}//end testAStaffEndWithoutACodeIsRefused()

	/**
	 * Staff cannot use not-given, nor a code outside the list.
	 *
	 * @return void
	 */
	public function testAStaffEndWithNotGivenOrAnUnlistedCodeIsRefused(): void {
		$guard = new CancellationReasonGuard();

		$this->assertFalse($guard->check(['cancellationReasonCode' => 'not-given'], 'endFromActive', 'anna')->isAllowed());
		$this->assertFalse($guard->check(['cancellationReasonCode' => 'bored'], 'endFromPaused', 'anna')->isAllowed());
	}//end testAStaffEndWithNotGivenOrAnUnlistedCodeIsRefused()

	/**
	 * A staff end with a listed code proceeds.
	 *
	 * @return void
	 */
	public function testAStaffEndWithACodeProceeds(): void {
		$this->assertTrue((new CancellationReasonGuard())->check(['cancellationReasonCode' => 'too-expensive'], 'endFromActive', 'anna')->isAllowed());
	}//end testAStaffEndWithACodeProceeds()

	/**
	 * With nobody signed in (the portal's forward) the reason may be left out.
	 *
	 * @return void
	 */
	public function testACustomerEndWithoutAReasonProceeds(): void {
		$guard = new CancellationReasonGuard();

		$this->assertTrue($guard->check(['status' => 'ended'], 'endFromActive', '')->isAllowed());
		$this->assertTrue($guard->check(['cancellationReasonCode' => 'not-given'], 'endFromActive', '')->isAllowed());
	}//end testACustomerEndWithoutAReasonProceeds()

	/**
	 * A profile that reached its own end is not refused, whoever saves it.
	 *
	 * @return void
	 */
	public function testAProfileAtItsOwnEndIsNotRefused(): void {
		$guard = new CancellationReasonGuard();
		$today = new DateTimeImmutable('2026-09-15');

		$this->assertTrue($guard->requireReason(profile: ['remainingOccurrences' => 0], userId: 'anna', today: $today));
		$this->assertTrue($guard->requireReason(profile: ['endCondition' => ['endDate' => '2026-09-15']], userId: 'anna', today: $today));
		$this->assertFalse($guard->requireReason(profile: ['endCondition' => ['endDate' => '2026-09-16']], userId: 'anna', today: $today));
		$this->assertFalse($guard->requireReason(profile: ['remainingOccurrences' => 3], userId: 'anna', today: $today));
		$this->assertFalse($guard->requireReason(profile: ['remainingOccurrences' => null], userId: 'anna', today: $today));
	}//end testAProfileAtItsOwnEndIsNotRefused()
}//end class
