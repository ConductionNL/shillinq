<?php

/**
 * Golden tests for JournalVoidGuard: the reversal query, answers and log lines.
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
 * @spec openspec/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\JournalVoidGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins every lookup, answer and log line of the void precondition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class JournalVoidGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed> $journal   The journal.
	 * @param mixed               $reversals findAll()'s answer, or a Throwable it throws.
	 *
	 * @return bool
	 */
	private function check(array $journal, mixed $reversals=[]): bool {
		$store     = new class($this, $reversals) {
			/**
			 * Construct.
			 *
			 * @param JournalVoidGuardGoldenTest $test      Recorder.
			 * @param mixed                      $reversals Answer.
			 */
			public function __construct(private JournalVoidGuardGoldenTest $test, private mixed $reversals) {
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
				if ($this->reversals instanceof \Throwable) {
					throw $this->reversals;
				}

				return $this->reversals;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store): object {
				$this->calls[] = ['get', $id];
				return $store;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return (new JournalVoidGuard($container, $appConfig, $logger))->requireReversedGLTransaction($journal);
	}//end check()

	/**
	 * A journal without a GL transaction is refused before any lookup.
	 *
	 * @return void
	 */
	public function testNoTransactionRefused(): void {
		self::assertFalse(condition: $this->check(journal: ['glTransactionId' => '']));
		self::assertSame(
			expected: [['info', 'JournalVoidGuard: void denied — journal has no materialised glTransactionId', ['journalNumber' => 'unknown']]],
			actual: $this->calls
		);
	}//end testNoTransactionRefused()

	/**
	 * A found reversal permits; the query is exact.
	 *
	 * @return void
	 */
	public function testReversedPermits(): void {
		self::assertTrue(condition: $this->check(journal: ['glTransactionId' => 12, 'journalNumber' => 'J1'], reversals: [['id' => 'r']]));
		self::assertSame(
			expected: [
				['get', 'OCA\OpenRegister\Service\ObjectService'],
				['setRegister', 'shillinq'],
				['setSchema', 'GLTransaction'],
				['findAll', ['filters' => ['reversesTransactionId' => '12'], 'limit' => 1]],
			],
			actual: $this->calls
		);
	}//end testReversedPermits()

	/**
	 * No reversal, or a non-array answer, refuses with the info line; a failure refuses with the error line.
	 *
	 * @return void
	 */
	public function testNotReversedAndFailure(): void {
		self::assertFalse(condition: $this->check(journal: ['glTransactionId' => 'g1', 'journalNumber' => 'J2'], reversals: []));
		$line = ['info', 'JournalVoidGuard: void denied — storneer eerst de grootboektransactie', ['journalNumber' => 'J2', 'glTransactionId' => 'g1']];
		self::assertSame(expected: $line, actual: end($this->calls));
		self::assertFalse(condition: $this->check(journal: ['glTransactionId' => 'g1', 'journalNumber' => 'J2'], reversals: 'x'));
		self::assertSame(expected: $line, actual: end($this->calls));
		self::assertFalse(condition: $this->check(journal: ['glTransactionId' => 'g1'], reversals: new \RuntimeException('db')));
		$actual = end($this->calls);
		$expected = ['error', 'JournalVoidGuard: reversal check failed — denying void (fail-closed)', ['exception' => 'db']];
		self::assertSame(expected: $expected, actual: $actual);
	}//end testNotReversedAndFailure()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
