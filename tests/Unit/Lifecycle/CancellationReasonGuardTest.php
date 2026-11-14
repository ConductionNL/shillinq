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

use OCA\Shillinq\Lifecycle\CancellationReasonGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Staff say why a subscription ends; a customer may not.
 *
 * @covers \OCA\Shillinq\Lifecycle\CancellationReasonGuard
 */
class CancellationReasonGuardTest extends TestCase {

	/**
	 * A staff end without a code is refused.
	 *
	 * @return void
	 */
	public function testAStaffEndWithoutACodeIsRefused(): void {
		$this->assertFalse((new CancellationReasonGuard())->requireReason(['status' => 'ended']));
	}//end testAStaffEndWithoutACodeIsRefused()

	/**
	 * Staff cannot use not-given as their reason.
	 *
	 * @return void
	 */
	public function testAStaffEndWithNotGivenIsRefused(): void {
		$this->assertFalse((new CancellationReasonGuard())->requireReason(['cancellationReasonCode' => 'not-given']));
	}//end testAStaffEndWithNotGivenIsRefused()

	/**
	 * A staff end with a listed code proceeds.
	 *
	 * @return void
	 */
	public function testAStaffEndWithACodeProceeds(): void {
		$this->assertTrue((new CancellationReasonGuard())->requireReason(['cancellationReasonCode' => 'too-expensive']));
	}//end testAStaffEndWithACodeProceeds()

	/**
	 * A code outside the fixed list is refused.
	 *
	 * @return void
	 */
	public function testAnUnlistedCodeIsRefused(): void {
		$this->assertFalse((new CancellationReasonGuard())->requireReason(['cancellationReasonCode' => 'bored']));
	}//end testAnUnlistedCodeIsRefused()

	/**
	 * A customer end recorded by the portal may leave the reason out.
	 *
	 * @return void
	 */
	public function testACustomerEndWithoutAReasonProceeds(): void {
		$guard = new CancellationReasonGuard();

		$this->assertTrue($guard->requireReason(['cancellation' => ['cancelledBy' => 'customer']]));
		$this->assertTrue($guard->requireReason(['cancellation' => ['cancelledBy' => 'customer'], 'cancellationReasonCode' => 'not-given']));
	}//end testACustomerEndWithoutAReasonProceeds()

	/**
	 * Through the registered adapter the refusal names the missing reason.
	 *
	 * @return void
	 */
	public function testTheRefusalSaysAReasonIsRequired(): void {
		$adapter = new RegisterRequiresGuardAdapter(
			guard: new CancellationReasonGuard(),
			method: 'requireReason',
			denyMessage: 'A cancellation reason is required to end this subscription.',
			logger: new NullLogger(),
		);

		$result = $adapter->check(['status' => 'active'], 'endFromActive', 'anna');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('A cancellation reason is required to end this subscription.', $result->getMessage());
	}//end testTheRefusalSaysAReasonIsRequired()
}//end class
