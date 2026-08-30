<?php

/**
 * Unit tests for BalanceGuard.
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
 * @spec openspec/changes/bookkeeping-general-ledger/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BalanceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BalanceGuard.
 *
 * Covers REQ-GL-005:
 * - Balanced 2-line transaction returns true
 * - Unbalanced transaction (debit != credit) returns false
 * - Float rounding handled by integer-cent arithmetic
 * - Exception causes fail-closed (returns false)
 * - Empty lines returns true (zero = zero)
 */
class BalanceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var BalanceGuard
	 */
	private BalanceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new BalanceGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * A balanced 2-line transaction returns true per REQ-GL-005.
	 *
	 * @return void
	 */
	public function testBalancedTwoLineTransactionReturnsTrue(): void {
		$lines = [
			['transactionId' => 'txn-1', 'side' => 'debit',  'amount' => 100.00],
			['transactionId' => 'txn-1', 'side' => 'credit', 'amount' => 100.00],
		];

		$this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: $lines));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->isBalanced(transactionId: 'txn-1'));

	}//end testBalancedTwoLineTransactionReturnsTrue()

	/**
	 * An unbalanced transaction (debit 100, credit 99.99) returns false per REQ-GL-005.
	 *
	 * @return void
	 */
	public function testUnbalancedTransactionReturnsFalse(): void {
		$lines = [
			['transactionId' => 'txn-2', 'side' => 'debit',  'amount' => 100.00],
			['transactionId' => 'txn-2', 'side' => 'credit', 'amount' => 99.99],
		];

		$this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: $lines));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->isBalanced(transactionId: 'txn-2'));

	}//end testUnbalancedTransactionReturnsFalse()

	/**
	 * Float rounding: 0.1+0.2 debit vs 0.3 credit is balanced via integer-cent arithmetic.
	 *
	 * IEEE-754: (float)(0.1+0.2) !== 0.3, but (int)round(0.1*100)+(int)round(0.2*100)
	 * === (int)round(0.3*100), so the guard correctly returns true.
	 *
	 * @return void
	 */
	public function testFloatRoundingHandledByIntegerCents(): void {
		$lines = [
			['transactionId' => 'txn-3', 'side' => 'debit',  'amount' => 0.1],
			['transactionId' => 'txn-3', 'side' => 'debit',  'amount' => 0.2],
			['transactionId' => 'txn-3', 'side' => 'credit', 'amount' => 0.3],
		];

		$this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: $lines));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->isBalanced(transactionId: 'txn-3'));

	}//end testFloatRoundingHandledByIntegerCents()

	/**
	 * Exception in ObjectService causes fail-closed response (returns false, no re-throw).
	 *
	 * @return void
	 */
	public function testExceptionCausesFailClosed(): void {
		$this->container->method('get')
			->willThrowException(new \RuntimeException('ObjectService unavailable'));

		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->isBalanced(transactionId: 'txn-fail'));

	}//end testExceptionCausesFailClosed()

	/**
	 * A transaction with no lines is trivially balanced (0 debit = 0 credit).
	 *
	 * @return void
	 */
	public function testEmptyLinesIsBalanced(): void {
		$this->container->method('get')->willReturn($this->buildObjectServiceStub(lines: []));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->isBalanced(transactionId: 'txn-empty'));

	}//end testEmptyLinesIsBalanced()

	/**
	 * Build an anonymous ObjectService stub that returns the given lines from findAll().
	 *
	 * @param array<mixed> $lines GL line records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $lines): object {
		return new class($lines) {
			/**
			 * GL line records to return from findAll().
			 *
			 * @var array<mixed>
			 */
			private array $lines;

			/**
			 * Constructor.
			 *
			 * @param array<mixed> $lines Lines to return.
			 */
			public function __construct(array $lines) {
				$this->lines = $lines;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return all stubbed lines.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->lines;
			}//end findAll()
		};
	}//end buildObjectServiceStub()
}//end class
