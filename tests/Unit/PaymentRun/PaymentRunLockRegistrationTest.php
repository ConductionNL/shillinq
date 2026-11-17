<?php

/**
 * Tests the registration of the approved payment run lock.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentRunLockListener;
use OCA\Shillinq\PaymentRun\PaymentRunLockRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The lock listener runs on both pre-save events, create first.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 */
#[CoversClass(PaymentRunLockRegistration::class)]
final class PaymentRunLockRegistrationTest extends TestCase {

	/**
	 * Both pre-save events get the lock listener, in order.
	 *
	 * @return void
	 */
	public function testRegistersTheLockListenerOnCreateAndUpdate(): void {
		$calls = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(2))->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$calls): void {
				$calls[] = [$event, $listener];
			}
		);

		(new PaymentRunLockRegistration())->register(context: $context);

		self::assertSame(
			[
				['OCA\\OpenRegister\\Event\\ObjectCreatingEvent', PaymentRunLockListener::class],
				['OCA\\OpenRegister\\Event\\ObjectUpdatingEvent', PaymentRunLockListener::class],
			],
			$calls
		);
	}//end testRegistersTheLockListenerOnCreateAndUpdate()
}//end class
