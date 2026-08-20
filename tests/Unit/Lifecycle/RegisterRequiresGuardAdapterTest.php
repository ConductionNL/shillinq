<?php

/**
 * Unit tests for RegisterRequiresGuardAdapter.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RegisterRequiresGuardAdapterTest extends TestCase {
	/**
	 * Verifies the adapter genuinely implements OpenRegister's contract —
	 * this is what makes it resolvable by LifecycleGuardRegistry::resolve()
	 * (which type-checks the resolved instance against
	 * LifecycleGuardInterface before calling ->check()).
	 *
	 * @return void
	 */
	public function testImplementsOpenRegisterLifecycleGuardInterface(): void {
		$adapter = new RegisterRequiresGuardAdapter(
			guard: new class {
				public function ok(array $object): bool {
					return true;
				}//end ok()
			},
			method: 'ok',
			denyMessage: 'denied',
			logger: $this->createMock(LoggerInterface::class),
		);

		self::assertInstanceOf(LifecycleGuardInterface::class, $adapter);

	}//end testImplementsOpenRegisterLifecycleGuardInterface()

	/**
	 * Good path: wrapped method returns true -> GuardResult::allow().
	 *
	 * @return void
	 */
	public function testAllowsWhenWrappedMethodReturnsTrue(): void {
		$guard = new class {
			public function passes(array $object): bool {
				return true;
			}//end passes()
		};

		$adapter = new RegisterRequiresGuardAdapter(
			guard: $guard,
			method: 'passes',
			denyMessage: 'should not appear',
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->check(['id' => 'obj-1'], 'submit', 'user-1');
		self::assertTrue($result->isAllowed());

	}//end testAllowsWhenWrappedMethodReturnsTrue()

	/**
	 * Bad path: wrapped method returns false -> GuardResult::deny($message).
	 *
	 * @return void
	 */
	public function testDeniesWithMessageWhenWrappedMethodReturnsFalse(): void {
		$guard = new class {
			public function fails(array $object): bool {
				return false;
			}//end fails()
		};

		$adapter = new RegisterRequiresGuardAdapter(
			guard: $guard,
			method: 'fails',
			denyMessage: 'Precondition not met.',
			logger: $this->createMock(LoggerInterface::class),
		);

		$result = $adapter->check(['id' => 'obj-1'], 'submit', 'user-1');
		self::assertFalse($result->isAllowed());
		self::assertSame('Precondition not met.', $result->getMessage());

	}//end testDeniesWithMessageWhenWrappedMethodReturnsFalse()

	/**
	 * Fail-closed: an exception thrown by the wrapped method denies rather
	 * than propagating (CWE-863 / OWASP A01:2021).
	 *
	 * @return void
	 */
	public function testFailsClosedWhenWrappedMethodThrows(): void {
		$guard = new class {
			public function explodes(array $object): bool {
				throw new \RuntimeException('boom');
			}//end explodes()
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error');

		$adapter = new RegisterRequiresGuardAdapter(
			guard: $guard,
			method: 'explodes',
			denyMessage: 'Precondition check failed.',
			logger: $logger,
		);

		$result = $adapter->check(['id' => 'obj-1'], 'submit', 'user-1');
		self::assertFalse($result->isAllowed());

	}//end testFailsClosedWhenWrappedMethodThrows()
}//end class
