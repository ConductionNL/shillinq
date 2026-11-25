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
	/**
	 * A guard method whose first parameter is a string id gets the object's id
	 * and the object (`canVoid(string $journalEntryId, ?array $object)`), and a
	 * method that takes only the id gets only the id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/missing-lifecycle-guards/spec.md
	 */
	public function testAnIdTakingGuardGetsTheIdAndTheObject(): void {
		$guard = new class {
			/**
			 * Every call's arguments.
			 *
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record the call.
			 *
			 * @param string                    $entryId The object id.
			 * @param array<string, mixed>|null $object  The object.
			 *
			 * @return bool
			 */
			public function canVoid(string $entryId, ?array $object = null): bool {
				$this->calls[] = [$entryId, $object];
				return $entryId === 'je-1';
			}//end canVoid()

			/**
			 * Record the call.
			 *
			 * @param string $runId The object id.
			 *
			 * @return bool
			 */
			public function canExecute(string $runId): bool {
				$this->calls[] = [$runId];
				return true;
			}//end canExecute()
		};

		$voids = new RegisterRequiresGuardAdapter(
			guard: $guard,
			method: 'canVoid',
			denyMessage: 'no',
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		self::assertTrue(condition: $voids->check(['id' => 'je-1', 'state' => 'posted'], 'void', 'user-1')->isAllowed());
		self::assertFalse(condition: $voids->check(['@self' => ['id' => 'je-2']], 'void', 'user-1')->isAllowed());

		$runs = new RegisterRequiresGuardAdapter(
			guard: $guard,
			method: 'canExecute',
			denyMessage: 'no',
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		self::assertTrue(condition: $runs->check(['uuid' => 'run-1'], 'execute', 'user-1')->isAllowed());

		self::assertSame(
			expected: [['je-1', ['id' => 'je-1', 'state' => 'posted']], ['je-2', ['@self' => ['id' => 'je-2']]], ['run-1']],
			actual: $guard->calls
		);

	}//end testAnIdTakingGuardGetsTheIdAndTheObject()
}//end class
