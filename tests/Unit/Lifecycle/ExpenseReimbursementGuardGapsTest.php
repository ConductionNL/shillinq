<?php

/**
 * Gap tests for ExpenseReimbursementGuard: register fallback, fail-closed
 * catches, the markInvoiced precondition and the GL-reversal precondition.
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
 * @spec openspec/specs/expense-reimbursement-or-passthrough/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\Shillinq\Lifecycle\ExpenseReimbursementGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the branches the main suite leaves open (REQ-ERP-003/006/007/011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ExpenseReimbursementGuard::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ExpenseReimbursementGuardGapsTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * Registers the guard scoped the store to, in order.
	 *
	 * @var list<string>
	 */
	public array $registers = [];

	/**
	 * Build the guard over an in-memory store.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows         Rows per schema.
	 * @param string                                  $registerSlug What IAppConfig answers for the register.
	 * @param string|null                             $failFind     Message find() throws with, or null.
	 * @param string|null                             $failFindAll  Message findAll() throws with, or null.
	 * @param bool                                    $infoThrows   Whether logger->info() throws.
	 *
	 * @return ExpenseReimbursementGuard
	 */
	private function guard(
		array $rows,
		string $registerSlug = 'shillinq',
		?string $failFind = null,
		?string $failFindAll = null,
		bool $infoThrows = false
	): ExpenseReimbursementGuard {
		$store = new class($rows, $this, $failFind, $failFindAll) extends InMemoryObjectServiceStub {
			/**
			 * Constructor.
			 *
			 * @param array<string,list<array<string,mixed>>> $rows        The rows.
			 * @param ExpenseReimbursementGuardGapsTest       $test        The test recording registers.
			 * @param string|null                             $failFind    find() failure message.
			 * @param string|null                             $failFindAll findAll() failure message.
			 */
			public function __construct(
				array $rows,
				private readonly ExpenseReimbursementGuardGapsTest $test,
				private readonly ?string $failFind,
				private readonly ?string $failFindAll
			) {
				parent::__construct($rows);
			}

			public function setRegister(string|int $register): static {
				$this->test->registers[] = (string)$register;
				return parent::setRegister($register);
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->failFindAll !== null) {
					throw new RuntimeException($this->failFindAll);
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true
			): ?ObjectEntityInterface {
				if ($this->failFind !== null) {
					throw new RuntimeException($this->failFind);
				}

				return parent::find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit);
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($registerSlug);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use ($infoThrows): void {
				$this->log[] = ['info', $message, $context];
				if ($infoThrows === true) {
					throw new RuntimeException('log sink down');
				}
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->log[] = ['error', $message, $context];
			}
		);

		return new ExpenseReimbursementGuard($appConfig, $logger, $store);

	}//end guard()

	/**
	 * GL transactions in every reversal state.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function glRows(): array {
		return [
			'GLTransaction' => [
				['id' => 'gl-flag', 'status' => 'posted', 'isReversed' => true],
				['id' => 'gl-reversed', 'status' => 'reversed'],
				['id' => 'gl-voided', 'status' => 'voided'],
				['id' => 'gl-posted', 'status' => 'posted', 'isReversed' => false],
				['id' => 'gl-truthy', 'status' => 7, 'isReversed' => 1],
			],
			'Receipt' => [
				['id' => 'r-1', 'settlementMode' => 'reimbursable'],
			],
		];

	}//end glRows()

	/**
	 * An empty configured register falls back to 'shillinq' for item lookups.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterFallsBackToShillinq(): void {
		$guard = $this->guard(self::glRows(), registerSlug: '');

		self::assertTrue($guard->requireSettlementModeConsistency(['settlementMode' => 'reimbursable', 'receiptIds' => ['r-1']]));
		self::assertSame(['shillinq'], $this->registers);

	}//end testAnEmptyRegisterFallsBackToShillinq()

	/**
	 * A configured register is used as-is.
	 *
	 * @return void
	 */
	public function testAConfiguredRegisterIsUsed(): void {
		$guard = $this->guard(['Receipt' => [['id' => 'r-1']]], registerSlug: 'finance');

		self::assertTrue($guard->requireSettlementModeConsistency(['settlementMode' => 'reimbursable', 'receiptIds' => ['r-1']]));
		self::assertSame(['finance'], $this->registers);

	}//end testAConfiguredRegisterIsUsed()

	/**
	 * A failing item lookup denies submit and logs the exception.
	 *
	 * @return void
	 */
	public function testAFailingItemLookupDeniesSubmit(): void {
		$guard = $this->guard(self::glRows(), failFind: 'store offline');

		self::assertFalse($guard->requireSettlementModeConsistency(['id' => 'cl-9', 'settlementMode' => 'reimbursable', 'receiptIds' => ['r-1']]));
		self::assertSame(
			[['error', 'ExpenseReimbursementGuard: requireSettlementModeConsistency failed — denying submit (fail-closed)', ['claimId' => 'cl-9', 'exception' => 'store offline']]],
			$this->log
		);

	}//end testAFailingItemLookupDeniesSubmit()

	/**
	 * A failing policy lookup denies approve and logs the exception.
	 *
	 * @return void
	 */
	public function testAFailingPolicyLookupDeniesApprove(): void {
		$guard = $this->guard([], failFindAll: 'policy store offline');

		self::assertFalse($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'pass-through', 'reimbursementPolicyId' => 'POL-1', 'totalPassThroughAmount' => 10]));
		self::assertSame(
			[['error', 'ExpenseReimbursementGuard: requireMarkupApprovalIfThreshold failed — denying approve (fail-closed)', ['claimId' => 'unknown', 'exception' => 'policy store offline']]],
			$this->log
		);

	}//end testAFailingPolicyLookupDeniesApprove()

	/**
	 * Only a pass-through claim may be marked invoiced.
	 *
	 * @return void
	 */
	public function testMarkInvoicedRequiresPassThrough(): void {
		$guard = $this->guard([]);

		self::assertFalse($guard->requirePassThroughMode(['id' => 'cl-1', 'settlementMode' => 'reimbursable', 'glPassThroughTransactionId' => 'gl-1']));
		self::assertFalse($guard->requirePassThroughMode([]));
		self::assertSame(
			[
				['info', 'ExpenseReimbursementGuard: markInvoiced denied — claim is not pass-through (REQ-ERP-007)', ['claimId' => 'cl-1', 'settlementMode' => 'reimbursable']],
				['info', 'ExpenseReimbursementGuard: markInvoiced denied — claim is not pass-through (REQ-ERP-007)', ['claimId' => 'unknown', 'settlementMode' => null]],
			],
			$this->log
		);

	}//end testMarkInvoicedRequiresPassThrough()

	/**
	 * A pass-through claim needs its GL pass-through back-reference.
	 *
	 * @return void
	 */
	public function testMarkInvoicedRequiresTheGlBackReference(): void {
		$guard = $this->guard([]);

		self::assertFalse($guard->requirePassThroughMode(['id' => 'cl-2', 'settlementMode' => 'pass-through']));
		self::assertFalse($guard->requirePassThroughMode(['id' => 'cl-3', 'settlementMode' => 'pass-through', 'glPassThroughTransactionId' => '']));
		self::assertTrue($guard->requirePassThroughMode(['id' => 'cl-4', 'settlementMode' => 'pass-through', 'glPassThroughTransactionId' => 'gl-1']));
		self::assertSame(
			[
				['info', 'ExpenseReimbursementGuard: markInvoiced denied — glPassThroughTransactionId missing', ['claimId' => 'cl-2']],
				['info', 'ExpenseReimbursementGuard: markInvoiced denied — glPassThroughTransactionId missing', ['claimId' => 'cl-3']],
			],
			$this->log
		);

	}//end testMarkInvoicedRequiresTheGlBackReference()

	/**
	 * A failure while logging the denial still denies, through the fail-closed path.
	 *
	 * @return void
	 */
	public function testMarkInvoicedFailsClosed(): void {
		$guard = $this->guard([], infoThrows: true);

		self::assertFalse($guard->requirePassThroughMode(['id' => 'cl-5', 'settlementMode' => 'reimbursable']));
		self::assertSame(
			['error', 'ExpenseReimbursementGuard: requirePassThroughMode failed — denying (fail-closed)', ['claimId' => 'cl-5', 'exception' => 'log sink down']],
			$this->log[1]
		);

	}//end testMarkInvoicedFailsClosed()

	/**
	 * A mode change without a posted GL transaction is permitted without a lookup.
	 *
	 * @return void
	 */
	public function testAModeChangeWithoutGlEntryIsPermitted(): void {
		$guard = $this->guard(self::glRows());

		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable']));
		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => '']));
		// A pass-through claim reads the pass-through field, not the reimbursable one.
		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'pass-through', 'glReimbursableTransactionId' => 'gl-posted']));
		self::assertSame([], $this->registers);

	}//end testAModeChangeWithoutGlEntryIsPermitted()

	/**
	 * Only a reversed or voided GL transaction permits the mode change.
	 *
	 * @return void
	 */
	public function testAModeChangeNeedsTheGlTransactionReversed(): void {
		$guard = $this->guard(self::glRows());

		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => 'gl-flag']));
		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'pass-through', 'glPassThroughTransactionId' => 'gl-reversed']));
		self::assertTrue($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => 'gl-voided']));
		self::assertFalse($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => 'gl-posted']));
		// Truthy-but-not-true isReversed and a non-string status do not count.
		self::assertFalse($guard->requireGlReversalForModeChange(['settlementMode' => 'reimbursable', 'glReimbursableTransactionId' => 'gl-truthy']));
		// An unknown transaction is denied.
		self::assertFalse($guard->requireGlReversalForModeChange(['settlementMode' => 'pass-through', 'glPassThroughTransactionId' => 'gl-missing']));
		self::assertSame([], $this->log);

	}//end testAModeChangeNeedsTheGlTransactionReversed()

	/**
	 * A failing GL lookup denies the mode change and logs the exception.
	 *
	 * @return void
	 */
	public function testAFailingGlLookupDeniesTheModeChange(): void {
		$guard = $this->guard(self::glRows(), failFind: 'gl offline');

		self::assertFalse($guard->requireGlReversalForModeChange(['id' => 'cl-6', 'settlementMode' => 'pass-through', 'glPassThroughTransactionId' => 'gl-reversed']));
		self::assertSame(
			[['error', 'ExpenseReimbursementGuard: requireGlReversalForModeChange failed — denying (fail-closed)', ['claimId' => 'cl-6', 'exception' => 'gl offline']]],
			$this->log
		);

	}//end testAFailingGlLookupDeniesTheModeChange()
}//end class
