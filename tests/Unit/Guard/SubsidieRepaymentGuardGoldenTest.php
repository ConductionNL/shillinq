<?php

/**
 * Golden tests for SubsidieRepaymentGuard: the installment query, the outstanding sum and log lines.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\SubsidieRepaymentGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every lookup, answer and log line of the close precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SubsidieRepaymentGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed> $subsidy      The subsidy.
	 * @param mixed               $installments findAll()'s answer, or a Throwable it throws.
	 *
	 * @return bool
	 */
	private function check(array $subsidy, mixed $installments=[]): bool {
		$store     = new class($this, $installments) {
			/**
			 * Construct.
			 *
			 * @param SubsidieRepaymentGuardGoldenTest $test         Recorder.
			 * @param mixed                            $installments Answer.
			 */
			public function __construct(private SubsidieRepaymentGuardGoldenTest $test, private mixed $installments) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return mixed
			 */
			public function findAll(array $params=[]): mixed {
				$this->test->calls[] = ['findAll', $params];
				if ($this->installments instanceof \Throwable) {
					throw $this->installments;
				}

				return $this->installments;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-j');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new SubsidieRepaymentGuard($container, $appConfig, $logger))->requireZeroRepaymentBalance($subsidy);
	}//end check()

	/**
	 * Without an id the close is refused without a lookup.
	 *
	 * @return void
	 */
	public function testNoIdRefused(): void {
		self::assertFalse(condition: $this->check(subsidy: ['id' => null]));
		self::assertSame(expected: [], actual: $this->calls);
	}//end testNoIdRefused()

	/**
	 * Paid installments are skipped and the rest summed in cents; zero permits.
	 *
	 * @return void
	 */
	public function testZeroBalancePermits(): void {
		$installments = [['state' => 'paid', 'amount' => 99], ['amount' => '0.1'], ['state' => 'open', 'amount' => -0.104], 'junk'];
		self::assertTrue(condition: $this->check(subsidy: ['id' => 7], installments: $installments));
		self::assertSame(
			expected: [['setRegister', 'reg-j'], ['setSchema', 'RepaymentInstallment'], ['findAll', ['filters' => ['subsidyId' => 7]]]],
			actual: $this->calls
		);
		self::assertTrue(condition: $this->check(subsidy: ['id' => 's1'], installments: 'not-an-array'));
	}//end testZeroBalancePermits()

	/**
	 * An outstanding balance refuses with the info line; a failure refuses with the error line.
	 *
	 * @return void
	 */
	public function testOutstandingAndFailure(): void {
		self::assertFalse(condition: $this->check(subsidy: ['id' => 's1'], installments: [['amount' => 12.345], ['state' => 'PAID', 'amount' => 1]]));
		self::assertSame(
			expected: [
				'info',
				'SubsidieRepaymentGuard: outstanding repayment balance — denying close',
				['subsidyId' => 's1', 'outstandingCents' => 1335,
			]],
			actual: end($this->calls)
		);
		self::assertFalse(condition: $this->check(subsidy: ['id' => 's1'], installments: new \RuntimeException('db')));
		self::assertSame(
			expected: ['error', 'SubsidieRepaymentGuard: requireZeroRepaymentBalance check failed — denying (fail-closed)', ['exception' => 'db']],
			actual: end($this->calls)
		);
	}//end testOutstandingAndFailure()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
