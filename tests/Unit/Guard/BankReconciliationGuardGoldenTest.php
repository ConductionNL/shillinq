<?php

/**
 * Golden tests for BankReconciliationGuard: lookups, paging, the balance write and log lines.
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BankReconciliationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every call the guard makes and every line it logs.
 */
class BankReconciliationGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Build a guard over a recording store.
	 *
	 * @param array<string,mixed>|null|\Throwable    $stored  find()'s answer, or its failure.
	 * @param array<int,mixed>|\Throwable            $matches All match rows, or the findAll failure.
	 * @param bool                                   $failUpdate Whether updateObject throws.
	 *
	 * @return BankReconciliationGuard
	 */
	private function guard(array|null|\Throwable $stored=null, array|\Throwable $matches=[], bool $failUpdate=false): BankReconciliationGuard {
		$store     = new class($this, $stored, $matches, $failUpdate) {
			/**
			 * Construct.
			 *
			 * @param BankReconciliationGuardGoldenTest   $test       Recorder.
			 * @param array<string,mixed>|null|\Throwable $stored     find()'s answer.
			 * @param array<int,mixed>|\Throwable         $matches    All rows.
			 * @param bool                                $failUpdate Whether updateObject throws.
			 */
			public function __construct(
				private BankReconciliationGuardGoldenTest $test,
				private array|null|\Throwable $stored,
				private array|\Throwable $matches,
				private bool $failUpdate,
			) {
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
			 * @param string $id Id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				$this->test->calls[] = ['find', $id];
				if ($this->stored instanceof \Throwable) {
					throw $this->stored;
				}

				return $this->stored;
			}//end find()

			/**
			 * Record and answer one page.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $params];
				if ($this->matches instanceof \Throwable) {
					throw $this->matches;
				}

				return array_slice($this->matches, (int)($params['offset'] ?? 0), (int)($params['limit'] ?? 0));
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
				$this->test->calls[] = ['updateObject', $id, $data];
				if ($this->failUpdate === true) {
					throw new \RuntimeException('write failed');
				}

				return $data;
			}//end updateObject()
		};
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-b');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['info', $message, $context];
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);

		return new BankReconciliationGuard(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter(inner: $store));
	}//end guard()

	/**
	 * The calls of one kind.
	 *
	 * @param string $kind Call name.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function callsOf(string $kind): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => $call[0] === $kind));
	}//end callsOf()

	/**
	 * An inverted period is refused before any lookup; equal dates pass.
	 *
	 * @return void
	 */
	public function testPeriodRefusal(): void {
		$guard = $this->guard();
		$inverted = ['id' => 'r1', 'statementStartDate' => '2026-02-02', 'statementEndDate' => '2026-02-01'];
		self::assertFalse(condition: $guard->requireUnlockedAndValidDates($inverted));
		self::assertSame(
			expected: [[
				'info',
				'BankReconciliationGuard: rejecting save — statementStartDate after statementEndDate',
				['start' => '2026-02-02', 'end' => '2026-02-01'],
			]],
			actual: $this->calls
		);
		self::assertTrue(condition: $guard->requireUnlockedAndValidDates(['statementStartDate' => '2026-02-01', 'statementEndDate' => '2026-02-01']));
		self::assertTrue(condition: $guard->requireUnlockedAndValidDates(['statementStartDate' => '2026-02-09']));
		self::assertCount(expectedCount: 1, haystack: $this->calls);
	}//end testPeriodRefusal()

	/**
	 * An edit of a reconciled session is refused with its statuses logged.
	 *
	 * @return void
	 */
	public function testLockedEditRefusedAndLogged(): void {
		$guard = $this->guard(stored: ['status' => 'reconciled']);
		self::assertFalse(condition: $guard->requireUnlockedAndValidDates(['id' => 7, 'memo' => 'x']));
		self::assertSame(expected: [['find', '7']], actual: $this->callsOf(kind: 'find'));
		self::assertSame(expected: ['setRegister', 'reg-b'], actual: $this->calls[0]);
		self::assertSame(expected: ['setSchema', 'BankReconciliation'], actual: $this->calls[1]);
		self::assertSame(
			expected: [[
				'info',
				'BankReconciliationGuard: rejecting save — reconciliation is locked',
				['id' => 7, 'storedStatus' => 'reconciled', 'incomingStatus' => 'reconciled'],
			]],
			actual: $this->callsOf(kind: 'info')
		);
	}//end testLockedEditRefusedAndLogged()

	/**
	 * Only reconciled → archived passes a lock; archived stays locked; draft and lookup failure pass.
	 *
	 * @return void
	 */
	public function testLockTransitions(): void {
		self::assertTrue(condition: $this->guard(stored: ['status' => 'reconciled'])->requireUnlockedAndValidDates(['id' => 'a', 'status' => 'archived']));
		self::assertFalse(condition: $this->guard(stored: ['status' => 'archived'])->requireUnlockedAndValidDates(['id' => 'a', 'status' => 'archived']));
		self::assertTrue(condition: $this->guard(stored: [])->requireUnlockedAndValidDates(['id' => 'a', 'status' => 'archived']));
		self::assertTrue(condition: $this->guard(stored: new \RuntimeException('x'))->requireUnlockedAndValidDates(['id' => 'a']));
		self::assertTrue(condition: $this->guard(stored: null)->requireUnlockedAndValidDates(['id' => 'a']));
	}//end testLockTransitions()

	/**
	 * A match under a locked parent is refused and logged; without a parent id nothing is looked up.
	 *
	 * @return void
	 */
	public function testParentLock(): void {
		self::assertTrue(condition: $this->guard(stored: ['status' => 'archived'])->requireParentUnlocked([]));
		self::assertSame(expected: [], actual: $this->calls);
		self::assertFalse(condition: $this->guard(stored: ['status' => 'archived'])->requireParentUnlocked(['reconciliationId' => 'p9']));
		self::assertSame(expected: [['find', 'p9']], actual: $this->callsOf(kind: 'find'));
		self::assertSame(
			expected: [[
				'info',
				'BankReconciliationGuard: rejecting match save — parent reconciliation is locked',
				['reconciliationId' => 'p9', 'parentStatus' => 'archived'],
			]],
			actual: $this->callsOf(kind: 'info')
		);
		self::assertTrue(condition: $this->guard(stored: ['status' => 'in-progress'])->requireParentUnlocked(['reconciliationId' => 'p9']));
		self::assertTrue(condition: $this->guard(stored: null)->requireParentUnlocked(['reconciliationId' => 'p9']));
	}//end testParentLock()

	/**
	 * The first unresolved match is logged and nothing is written.
	 *
	 * @return void
	 */
	public function testUnresolvedMatchRefused(): void {
		$guard = $this->guard(matches: [['matchType' => 'approved'], ['matchType' => 'rejected'], [], ['matchType' => 'auto-matched']]);
		self::assertFalse(condition: $guard->requireResolvedMatches(['id' => 'r1']));
		self::assertSame(
			expected: [[
				'info',
				'BankReconciliationGuard: approve denied — unresolved matches remain',
				['reconciliationId' => 'r1', 'unresolvedType' => 'pending-review'],
			]],
			actual: $this->callsOf(kind: 'info')
		);
		self::assertSame(expected: [], actual: $this->callsOf(kind: 'updateObject'));
	}//end testUnresolvedMatchRefused()

	/**
	 * Matches are paged by 500 and the balance write carries the exact fields.
	 *
	 * @return void
	 */
	public function testPagingAndBalanceWrite(): void {
		$matches = [];
		for ($i = 0; $i < 500; $i++) {
			$matches[] = ['matchType' => 'approved', 'bankTransactionAmount' => '0.01', 'journalEntryId' => 'j' . $i];
		}

		$matches[] = ['matchType' => 'approved', 'bankTransactionAmount' => 2.345];
		$matches[] = ['matchType' => 'rejected', 'bankTransactionAmount' => 1000];
		$guard     = $this->guard(matches: $matches);
		self::assertTrue(condition: $guard->requireResolvedMatches(['id' => 'r2', 'openingBalance' => '100.005', 'closingBalance' => 110]));
		self::assertSame(
			expected: [
				['findAll', ['filters' => ['reconciliationId' => 'r2'], 'limit' => 500, 'offset' => 0]],
				['findAll', ['filters' => ['reconciliationId' => 'r2'], 'limit' => 500, 'offset' => 500]],
			],
			actual: $this->callsOf(kind: 'findAll')
		);
		self::assertSame(
			expected: [[
				'updateObject',
				'r2',
				[
					'reconciledBalance' => 107.36,
					'variance' => 2.64,
					'matchedCount' => 501,
					'unmatchedBankCount' => 1,
					'unmatchedJournalCount' => 1,
				],
			]],
			actual: $this->callsOf(kind: 'updateObject')
		);
		self::assertSame(expected: ['setSchema', 'BankReconciliation'], actual: $this->calls[count($this->calls) - 2]);
	}//end testPagingAndBalanceWrite()

	/**
	 * A failed balance write is logged and approval still passes; no id writes nothing.
	 *
	 * @return void
	 */
	public function testBalanceWriteFailureAndNoId(): void {
		self::assertTrue(condition: $this->guard(matches: [], failUpdate: true)->requireResolvedMatches(['id' => 'r3']));
		self::assertSame(
			expected: [['error', 'BankReconciliationGuard: balance recomputation failed', ['reconciliationId' => 'r3', 'exception' => 'write failed']]],
			actual: $this->callsOf(kind: 'error')
		);
		$this->calls = [];
		$this->guard()->recalculateBalance(['openingBalance' => 1]);
		self::assertSame(expected: [], actual: $this->calls);
		$this->guard(matches: [['matchType' => 'approved', 'bankTransactionAmount' => 1]])->recalculateBalance(['id' => 'r4']);
		self::assertSame(expected: 'findAll', actual: $this->calls[2][0]);
		self::assertSame(expected: 1, actual: end($this->calls)[2]['reconciledBalance']);
	}//end testBalanceWriteFailureAndNoId()

	/**
	 * A failed match lookup refuses and is logged; a missing id refuses without a lookup.
	 *
	 * @return void
	 */
	public function testLookupFailureAndMissingId(): void {
		self::assertFalse(condition: $this->guard(matches: new \RuntimeException('db'))->requireResolvedMatches(['id' => 'r5']));
		self::assertSame(
			expected: [['error', 'BankReconciliationGuard: approve denied — match lookup failed (fail-closed)', ['exception' => 'db']]],
			actual: $this->callsOf(kind: 'error')
		);
		$this->calls = [];
		self::assertFalse(condition: $this->guard()->requireResolvedMatches([]));
		self::assertSame(expected: [['info', 'BankReconciliationGuard: approve denied — reconciliation has no id', []]], actual: $this->calls);
	}//end testLookupFailureAndMissingId()
}//end class
