<?php

/**
 * Unit tests for BankReconciliationGuard.
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
 * @spec openspec/changes/bookkeeping-bank-reconciliation/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BankReconciliationGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BankReconciliationGuard.
 *
 * Covers:
 * - requireUnlockedAndValidDates: date validation + locked-session immutability
 * - requireParentUnlocked: match writes blocked when parent locked
 * - requireResolvedMatches: approval blocked while pending/auto-matched remain
 * - recalculateBalance: integer-cents server-authoritative balance + counts
 * - fail-closed behaviour
 */
class BankReconciliationGuardTest extends TestCase {

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
	 * @var BankReconciliationGuard
	 */
	private BankReconciliationGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = $this->buildGuard(store: $this->buildObjectServiceStub());

	}//end setUp()

	/**
	 * Build the guard over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the guard is built — parking it on the
	 * container after the fact leaves the guard reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return BankReconciliationGuard
	 */
	private function buildGuard(object $store): BankReconciliationGuard {
		return new BankReconciliationGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end buildGuard()

	/**
	 * A brand-new reconciliation (no id) with a valid period is permitted to save.
	 *
	 * @return void
	 */
	public function testSavePermitsNewReconciliationWithValidDates(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->requireUnlockedAndValidDates(
			[
				'statementStartDate' => '2026-05-01',
				'statementEndDate' => '2026-05-31',
			]
		);

		self::assertTrue(condition: $result, message: 'New reconciliation with valid dates must be permitted');

	}//end testSavePermitsNewReconciliationWithValidDates()

	/**
	 * A reconciliation whose start date is after its end date is rejected (REQ-BBR-001).
	 *
	 * @return void
	 */
	public function testSaveRejectsInvalidStatementPeriod(): void {
		$result = $this->guard->requireUnlockedAndValidDates(
			[
				'statementStartDate' => '2026-05-31',
				'statementEndDate' => '2026-05-01',
			]
		);

		self::assertFalse(condition: $result, message: 'statementStartDate after statementEndDate must be rejected');

	}//end testSaveRejectsInvalidStatementPeriod()

	/**
	 * Editing a reconciled (locked) reconciliation is rejected (REQ-BBR-006).
	 *
	 * @return void
	 */
	public function testSaveRejectsEditOfLockedReconciliation(): void {
		$stub = $this->buildObjectServiceStub(
			stored: ['id' => 'rec-1', 'status' => 'reconciled'],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireUnlockedAndValidDates(
			[
				'id' => 'rec-1',
				'status' => 'reconciled',
				'notes' => 'trying to edit a locked session',
				'statementStartDate' => '2026-05-01',
				'statementEndDate' => '2026-05-31',
			]
		);

		self::assertFalse(condition: $result, message: 'Editing a locked reconciliation must be rejected');

	}//end testSaveRejectsEditOfLockedReconciliation()

	/**
	 * The archive transition (reconciled → archived) is the one permitted change on a locked session.
	 *
	 * @return void
	 */
	public function testSavePermitsArchiveTransitionOnLockedReconciliation(): void {
		$stub = $this->buildObjectServiceStub(
			stored: ['id' => 'rec-1', 'status' => 'reconciled'],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireUnlockedAndValidDates(
			[
				'id' => 'rec-1',
				'status' => 'archived',
			]
		);

		self::assertTrue(condition: $result, message: 'reconciled → archived transition must be permitted');

	}//end testSavePermitsArchiveTransitionOnLockedReconciliation()

	/**
	 * A match write is rejected when its parent reconciliation is locked (REQ-BBR-008).
	 *
	 * @return void
	 */
	public function testMatchSaveRejectedWhenParentLocked(): void {
		$stub = $this->buildObjectServiceStub(
			stored: ['id' => 'rec-1', 'status' => 'reconciled'],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireParentUnlocked(
			[
				'reconciliationId' => 'rec-1',
				'matchType' => 'pending-review',
			]
		);

		self::assertFalse(condition: $result, message: 'Match write must be rejected when parent reconciliation is locked');

	}//end testMatchSaveRejectedWhenParentLocked()

	/**
	 * A match write is permitted when its parent reconciliation is in-progress.
	 *
	 * @return void
	 */
	public function testMatchSavePermittedWhenParentUnlocked(): void {
		$stub = $this->buildObjectServiceStub(
			stored: ['id' => 'rec-1', 'status' => 'in-progress'],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireParentUnlocked(
			[
				'reconciliationId' => 'rec-1',
				'matchType' => 'approved',
			]
		);

		self::assertTrue(condition: $result, message: 'Match write must be permitted when parent reconciliation is unlocked');

	}//end testMatchSavePermittedWhenParentUnlocked()

	/**
	 * Approval is denied while any pending-review match remains (REQ-BBR-006, 409 Conflict).
	 *
	 * @return void
	 */
	public function testApproveDeniedWithUnresolvedMatches(): void {
		$stub = $this->buildObjectServiceStub(
			stored: null,
			matches: [
				['matchType' => 'approved', 'bankTransactionAmount' => 100.00],
				['matchType' => 'pending-review', 'bankTransactionAmount' => 50.00],
			],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireResolvedMatches(
			[
				'id' => 'rec-1',
				'openingBalance' => 5000.00,
				'closingBalance' => 5100.00,
			]
		);

		self::assertFalse(condition: $result, message: 'Approval must be denied while a pending-review match remains');

	}//end testApproveDeniedWithUnresolvedMatches()

	/**
	 * Approval is permitted once every match is resolved (approved/rejected),
	 * and the balance is recomputed in integer cents (REQ-BBR-005/006).
	 *
	 * @return void
	 */
	public function testApprovePermittedAndRecomputesBalance(): void {
		// 0.1 + 0.2 cents discipline: opening 5000.00 + approved (0.10 + 0.20) = 5000.30.
		$stub = $this->buildObjectServiceStub(
			stored: null,
			matches: [
				['matchType' => 'approved', 'bankTransactionAmount' => 0.10, 'journalEntryId' => 'j-1'],
				['matchType' => 'approved', 'bankTransactionAmount' => 0.20, 'journalEntryId' => 'j-2'],
				['matchType' => 'rejected', 'bankTransactionAmount' => 99.00, 'journalEntryId' => null],
			],
		);
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireResolvedMatches(
			[
				'id' => 'rec-1',
				'openingBalance' => 5000.00,
				'closingBalance' => 5000.30,
			]
		);

		self::assertTrue(condition: $result, message: 'Approval must be permitted when all matches are resolved');

		$update = $stub->lastUpdate;
		self::assertNotNull(actual: $update, message: 'recalculateBalance must persist an update');
		// 5000.00 + 0.10 + 0.20 = 5000.30 exactly via integer cents (no float drift).
		self::assertSame(expected: 5000.30, actual: $update['reconciledBalance'], message: 'reconciledBalance must be integer-cents exact');
		self::assertEquals(expected: 0, actual: $update['variance'], message: 'variance must be exactly 0 (closing == reconciled)');
		self::assertSame(expected: 0, actual: (int)round(((float)$update['variance']) * 100), message: 'variance in cents must be exactly 0');
		self::assertSame(expected: 2, actual: $update['matchedCount'], message: 'two approved matches');
		self::assertSame(expected: 1, actual: $update['unmatchedBankCount'], message: 'the rejected match counts as an unmatched bank txn');

	}//end testApprovePermittedAndRecomputesBalance()

	/**
	 * Approval is denied fail-closed when the reconciliation has no id.
	 *
	 * @return void
	 */
	public function testApproveDeniedWhenNoId(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->requireResolvedMatches(['openingBalance' => 0.0, 'closingBalance' => 0.0]);

		self::assertFalse(condition: $result, message: 'Approval without a persisted id must be denied fail-closed');

	}//end testApproveDeniedWhenNoId()

	/**
	 * Approval is denied fail-closed when the match lookup throws.
	 *
	 * @return void
	 */
	public function testApproveFailClosedOnLookupError(): void {
		$stub = $this->buildObjectServiceStubThatThrows();
		$this->guard = $this->buildGuard(store: $stub);

		$result = $this->guard->requireResolvedMatches(
			[
				'id' => 'rec-1',
				'openingBalance' => 0.0,
				'closingBalance' => 0.0,
			]
		);

		self::assertFalse(condition: $result, message: 'Fail-closed: a match lookup error must deny approval');

	}//end testApproveFailClosedOnLookupError()

	/**
	 * Build a fluent ObjectService stub.
	 *
	 * @param array<string,mixed>|null $stored Object returned by find() (BankReconciliation lookups).
	 * @param array<int,array<string,mixed>> $matches Records returned by findAll() (BankReconciliationMatch).
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(?array $stored = null, array $matches = []): object {
		return new class($stored, $matches) {
			/**
			 * Last update payload captured by updateObject().
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $lastUpdate = null;

			/**
			 * Stored object returned by find().
			 *
			 * @var array<string,mixed>|null
			 */
			private ?array $stored;

			/**
			 * Match records returned by findAll().
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $matches;

			/**
			 * Construct the stub.
			 *
			 * @param array<string,mixed>|null $stored Stored object.
			 * @param array<int,array<string,mixed>> $matches Match records.
			 */
			public function __construct(?array $stored, array $matches) {
				$this->stored = $stored;
				$this->matches = $matches;
			}//end __construct()

			/**
			 * Set register context (fluent, no-op stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema context (fluent, no-op stub).
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return the stored object or null.
			 *
			 * @param string $id Object identifier.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				return $this->stored;
			}//end find()

			/**
			 * Return the preconfigured match records.
			 *
			 * @param array<string,mixed> $params Query parameters (ignored by stub).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				return $this->matches;
			}//end findAll()

			/**
			 * Capture the update payload and return it.
			 *
			 * @param string $id Object identifier.
			 * @param array<string,mixed> $data Update payload.
			 *
			 * @return array<string,mixed>
			 */
			public function updateObject(string $id, array $data): array {
				$this->lastUpdate = $data;
				return $data;
			}//end updateObject()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build an ObjectService stub whose findAll() throws.
	 *
	 * @return object
	 */
	private function buildObjectServiceStubThatThrows(): object {
		return new class {
			/**
			 * Set register context (fluent, no-op stub).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema context (fluent, no-op stub).
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Simulate a DB error on every call.
			 *
			 * @param array<string,mixed> $params Query parameters (ignored by stub).
			 *
			 * @return array<int,array<string,mixed>>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('DB error');
			}//end findAll()
		};

	}//end buildObjectServiceStubThatThrows()
}//end class
