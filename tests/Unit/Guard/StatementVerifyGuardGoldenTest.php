<?php

/**
 * Golden tests for StatementVerifyGuard: lookups, paging, the variance write and log lines.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\StatementVerifyGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every store call and log line of both preconditions, in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class StatementVerifyGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build a guard over a recording store.
	 *
	 * @param array<string,array<int,mixed>|\Throwable> $schemas    Rows per schema, or a failure.
	 * @param bool                                      $failUpdate Whether updateObject throws.
	 *
	 * @return StatementVerifyGuard
	 */
	private function guard(array $schemas=[], bool $failUpdate=false): StatementVerifyGuard {
		$store     = new class($this, $schemas, $failUpdate) {
			/**
			 * The schema set last.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param StatementVerifyGuardGoldenTest $test       Recorder.
			 * @param array<string,mixed>            $schemas    Rows per schema.
			 * @param bool                           $failUpdate Whether updateObject throws.
			 */
			public function __construct(private StatementVerifyGuardGoldenTest $test, private array $schemas, private bool $failUpdate) {
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer one page.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$rows = ($this->schemas[$this->schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return array_slice($rows, $params['offset'], $params['limit']);
			}//end findAll()

			/**
			 * Record.
			 *
			 * @param string              $id   Id.
			 * @param array<string,mixed> $data Fields.
			 *
			 * @return array<string,mixed>
			 */
			public function updateObject(string $id, array $data): array {
				$this->test->calls[] = ['updateObject', $this->schema, $id, $data];
				if ($this->failUpdate === true) {
					throw new \RuntimeException('write failed');
				}

				return $data;
			}//end updateObject()
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-d');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		return new StatementVerifyGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));
	}//end guard()

	/**
	 * The calls of the given kinds.
	 *
	 * @param array<int,string> $kinds Call names.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function only(array $kinds): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => in_array($call[0], $kinds, true)));
	}//end only()

	/**
	 * GL lines are paged, summed by side in cents, persisted, and the variance is logged.
	 *
	 * @return void
	 */
	public function testVariancePagingWriteAndLog(): void {
		$lines = [];
		for ($i = 0; $i < 500; $i++) {
			$lines[] = ['amount' => '0.01', 'side' => 'debit'];
		}

		$lines[] = ['amount' => 2.005, 'side' => 'credit'];
		$lines[] = ['amount' => 1];
		$recon   = [
			'id' => 'r1',
			'openingBalance' => '100',
			'closingBalance' => 100.5,
			'bankAccountId' => 'NL01',
			'statementPeriodStart' => '2026-01-01',
			'statementPeriodEnd' => '2026-01-31',
		];
		self::assertTrue(condition: $this->guard(schemas: ['GLLine' => $lines])->verifyStatementBalance($recon));
		$filters = ['accountNumber' => 'NL01', 'entryDate' => ['gte' => '2026-01-01', 'lte' => '2026-01-31']];
		self::assertSame(
			expected: [
				['findAll', 'GLLine', ['filters' => $filters, 'limit' => 500, 'offset' => 0]],
				['findAll', 'GLLine', ['filters' => $filters, 'limit' => 500, 'offset' => 500]],
				['updateObject', 'BankReconciliation', 'r1', ['expectedGLBalance' => 103.99, 'variance' => 3.49]],
				[
					'info',
					'StatementVerifyGuard: statement-balance variance detected on initiate '
					. '(transition allowed; operator must resolve via matching or REQ-REC-004 classification)',
					['reconciliationId' => 'r1', 'expectedCents' => 10399, 'closingCents' => 10050, 'varianceCents' => -349],
				],
			],
			actual: $this->only(kinds: ['findAll', 'updateObject', 'info', 'warning', 'error'])
		);
		self::assertSame(expected: ['setRegister', 'reg-d'], actual: $this->calls[0]);
	}//end testVariancePagingWriteAndLog()

	/**
	 * Without a full period no GL lookup runs; a tying balance writes but does not log.
	 *
	 * @return void
	 */
	public function testNoPeriodTyingBalance(): void {
		$guard = $this->guard(schemas: ['GLLine' => [['amount' => 5]]]);
		$subject = ['id' => 'r2', 'openingBalance' => 7, 'closingBalance' => 7, 'bankAccountId' => 'NL01'];
		self::assertTrue(condition: $guard->verifyStatementBalance($subject));
		self::assertSame(
			expected: [['updateObject', 'BankReconciliation', 'r2', ['expectedGLBalance' => 7, 'variance' => 0]]],
			actual: $this->only(kinds: ['findAll', 'updateObject', 'info', 'warning', 'error'])
		);
	}//end testNoPeriodTyingBalance()

	/**
	 * A failed write is a warning and still passes; a failed lookup is an error and refuses without a write.
	 *
	 * @return void
	 */
	public function testWriteAndLookupFailures(): void {
		self::assertTrue(condition: $this->guard(failUpdate: true)->verifyStatementBalance(['id' => 'r3']));
		self::assertSame(
			expected: [[
				'warning',
				'StatementVerifyGuard: variance persistence failed (non-fatal — warning surface still works)',
				['reconciliationId' => 'r3', 'exception' => 'write failed'],
			]],
			actual: $this->only(kinds: ['info', 'warning', 'error'])
		);
		$this->calls = [];
		$recon       = ['id' => 'r4', 'bankAccountId' => 'b', 'statementPeriodStart' => 's', 'statementPeriodEnd' => 'e'];
		self::assertFalse(condition: $this->guard(schemas: ['GLLine' => new \RuntimeException('gl down')])->verifyStatementBalance($recon));
		self::assertSame(
			expected: [[
				'error',
				'StatementVerifyGuard: initiate denied — statement-balance verification failed',
				['exception' => 'gl down', 'reconciliationId' => 'r4'],
			]],
			actual: $this->only(kinds: ['updateObject', 'info', 'warning', 'error'])
		);
		$this->calls = [];
		self::assertFalse(condition: $this->guard()->verifyStatementBalance([]));
		self::assertSame(expected: [['info', 'StatementVerifyGuard: initiate denied — reconciliation has no id', []]], actual: $this->calls);
	}//end testWriteAndLookupFailures()

	/**
	 * The sign-off comment is checked before the id, and neither looks anything up.
	 *
	 * @return void
	 */
	public function testSignOffAndIdRefusals(): void {
		self::assertFalse(condition: $this->guard()->requireResolvedAndSignedOff(['signOffComment' => '   ']));
		self::assertFalse(condition: $this->guard()->requireResolvedAndSignedOff(['signOffComment' => 'ok', 'id' => '']));
		self::assertSame(
			expected: [
				['info', 'StatementVerifyGuard: verify denied — signOffComment is required (REQ-REC-006)', ['reconciliationId' => null]],
				['info', 'StatementVerifyGuard: verify denied — reconciliation has no id', []],
			],
			actual: $this->calls
		);
	}//end testSignOffAndIdRefusals()

	/**
	 * Matches are paged; the first unresolved one is logged with its id fallback.
	 *
	 * @return void
	 */
	public function testMatchPagingAndUnresolved(): void {
		$matches = array_fill(0, 500, ['resolutionStatus' => 'matched']);
		$matches[] = ['resolutionStatus' => 'timing'];
		$matches[] = ['id' => 'm-9', 'resolutionStatus' => 'open'];
		$matches[] = ['matchId' => 'm-10'];
		$guard = $this->guard(schemas: ['ReconciliationMatch' => $matches]);
		$subject = ['signOffComment' => 'ok', 'id' => 'r5'];
		self::assertFalse(condition: $guard->requireResolvedAndSignedOff($subject));
		self::assertSame(
			expected: [
				['findAll', 'ReconciliationMatch', ['filters' => ['reconId' => 'r5'], 'limit' => 500, 'offset' => 0]],
				['findAll', 'ReconciliationMatch', ['filters' => ['reconId' => 'r5'], 'limit' => 500, 'offset' => 500]],
				[
					'info',
					'StatementVerifyGuard: verify denied — unresolved matches remain (REQ-REC-004)',
					['reconciliationId' => 'r5', 'matchId' => 'm-9', 'resolutionStatus' => 'open'],
				],
			],
			actual: $this->only(kinds: ['findAll', 'info', 'error'])
		);
		$this->calls = [];
		$guard = $this->guard(schemas: ['ReconciliationMatch' => [['resolutionStatus' => 'adjustment'], ['resolutionStatus' => 'pending']]]);
		$subject = ['signOffComment' => 'ok', 'id' => 'r6'];
		self::assertTrue(condition: $guard->requireResolvedAndSignedOff($subject));
		self::assertSame(expected: [], actual: $this->only(kinds: ['info', 'error']));
	}//end testMatchPagingAndUnresolved()

	/**
	 * A failed match lookup refuses with the error line.
	 *
	 * @return void
	 */
	public function testMatchLookupFailure(): void {
		$guard = $this->guard(schemas: ['ReconciliationMatch' => new \RuntimeException('m down')]);
		$subject = ['signOffComment' => 'ok', 'id' => 'r7'];
		self::assertFalse(condition: $guard->requireResolvedAndSignedOff($subject));
		self::assertSame(
			expected: [[
				'error',
				'StatementVerifyGuard: verify denied — match lookup failed (fail-closed)',
				['reconciliationId' => 'r7', 'exception' => 'm down'],
			]],
			actual: $this->only(kinds: ['info', 'error'])
		);
	}//end testMatchLookupFailure()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
