<?php

/**
 * Tests for ExpenseReimbursementGuard's settlement-mode and markup-approval preconditions.
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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the allowed and refused outcomes, their log lines and the item lookups (REQ-ERP-002/003/006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ExpenseReimbursementGuardTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * Item ids looked up with find(), in order.
	 *
	 * @var list<string>
	 */
	public array $found = [];

	/**
	 * The guard over the given rows per schema.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows The rows per schema.
	 *
	 * @return ExpenseReimbursementGuard
	 */
	private function guard(array $rows): ExpenseReimbursementGuard {
		$test = $this;
		$store = new class($rows, $test) extends InMemoryObjectServiceStub {
			/**
			 * Constructor.
			 *
			 * @param array<string,list<array<string,mixed>>> $rows The rows.
			 * @param ExpenseReimbursementGuardTest            $test The test recording lookups.
			 */
			public function __construct(array $rows, private readonly ExpenseReimbursementGuardTest $test) {
				parent::__construct($rows);
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
				$this->test->found[] = (string)$id;
				return parent::find($id, $_extend, $files, $register, $schema, $_rbac, $_multitenancy, $_render, $_audit);
			}
		};

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new ExpenseReimbursementGuard($appConfig, $logger, $store);

	}//end guard()

	/**
	 * Items of every kind.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function rows(): array {
		return [
			'Receipt' => [
				['id' => 'r-pt', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1', 'amountInBaseCurrency' => 100.0, 'markupRateApplied' => 0.10],
				['id' => 'r-pt-nocust', 'settlementMode' => 'pass-through', 'linkedCustomerId' => '', 'amountInBaseCurrency' => 50.0],
				['id' => 'r-re', 'settlementMode' => 'reimbursable', 'amountInBaseCurrency' => 80.0],
				['id' => 'r-inherit', 'linkedCustomerId' => 'c-1', 'amountInBaseCurrency' => 10.0],
			],
			'MileageEntry' => [
				['id' => 'm-pt', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1', 'totalAmount' => 300.0, 'markupRateApplied' => 0.20],
				['id' => 'm-zero', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1', 'totalAmount' => 0.0, 'markupRateApplied' => 0.90],
			],
			'PerDiem' => [
				['id' => 'p-re', 'settlementMode' => 'reimbursable', 'allowanceAmount' => 40.0],
				['id' => 'p-pt', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'c-1', 'allowanceAmount' => 100.0, 'markupRateApplied' => 0.0],
			],
			'ReimbursementPolicy' => [
				['id' => 'pol-1', 'policyId' => 'POL-1', 'requiresMarkupApprovalThreshold' => 25],
				['id' => 'pol-2', 'policyId' => 'POL-2'],
			],
		];

	}//end rows()

	/**
	 * A claim without, or with an invalid, settlement mode is refused before any lookup.
	 *
	 * @return void
	 */
	public function testTheClaimModeIsRequired(): void {
		self::assertFalse($this->guard(self::rows())->requireSettlementModeConsistency(['id' => 'cl-1', 'receiptIds' => ['r-pt']]));
		self::assertFalse($this->guard(self::rows())->requireSettlementModeConsistency(['settlementMode' => 'cash']));
		self::assertSame([], $this->found);
		self::assertSame(
			[
				['info', 'ExpenseReimbursementGuard: claim has no settlementMode — denying submit (REQ-ERP-003)', ['claimId' => 'cl-1']],
				['info', 'ExpenseReimbursementGuard: claim has invalid settlementMode — denying submit', ['claimId' => 'unknown', 'settlementMode' => 'cash']],
			],
			$this->log
		);

	}//end testTheClaimModeIsRequired()

	/**
	 * Matching and inheriting items pass; empty and unknown ids are skipped; items are read in kind order.
	 *
	 * @return void
	 */
	public function testMatchingItemsPass(): void {
		$claim = ['id' => 'cl-2', 'settlementMode' => 'pass-through', 'receiptIds' => ['r-pt', '', 'missing', 'r-inherit'], 'mileageIds' => ['m-pt'], 'perDiemIds' => ['p-pt']];

		self::assertTrue($this->guard(self::rows())->requireSettlementModeConsistency($claim));
		self::assertSame(['r-pt', 'missing', 'r-inherit', 'm-pt', 'p-pt'], $this->found);
		self::assertSame([], $this->log);

	}//end testMatchingItemsPass()

	/**
	 * A mixed-mode item refuses and stops the lookups.
	 *
	 * @return void
	 */
	public function testAMixedModeItemRefusesAndStops(): void {
		$claim = ['id' => 'cl-3', 'settlementMode' => 'reimbursable', 'receiptIds' => ['r-re'], 'mileageIds' => ['m-pt', 'm-zero'], 'perDiemIds' => ['p-re']];

		self::assertFalse($this->guard(self::rows())->requireSettlementModeConsistency($claim));
		self::assertSame(['r-re', 'm-pt'], $this->found);
		self::assertSame(
			[['info', 'ExpenseReimbursementGuard: mixed-mode claim rejected (REQ-ERP-003)', ['claimId' => 'cl-3', 'itemId' => 'm-pt', 'schema' => 'MileageEntry', 'claimMode' => 'reimbursable', 'itemMode' => 'pass-through']]],
			$this->log
		);

	}//end testAMixedModeItemRefusesAndStops()

	/**
	 * A pass-through item without a customer refuses; a reimbursable claim does not need one.
	 *
	 * @return void
	 */
	public function testAPassThroughItemNeedsACustomer(): void {
		self::assertFalse($this->guard(self::rows())->requireSettlementModeConsistency(['id' => 'cl-4', 'settlementMode' => 'pass-through', 'receiptIds' => ['r-pt-nocust', 'r-pt']]));
		self::assertSame(['r-pt-nocust'], $this->found);
		self::assertSame(
			[['info', 'ExpenseReimbursementGuard: pass-through item missing linkedCustomerId (REQ-ERP-002)', ['claimId' => 'cl-4', 'itemId' => 'r-pt-nocust', 'schema' => 'Receipt']]],
			$this->log
		);

		self::assertTrue($this->guard(self::rows())->requireSettlementModeConsistency(['settlementMode' => 'reimbursable', 'receiptIds' => ['r-re'], 'perDiemIds' => ['p-re']]));

	}//end testAPassThroughItemNeedsACustomer()

	/**
	 * Only pass-through claims with a threshold policy are gated.
	 *
	 * @return void
	 */
	public function testTheMarkupGateAppliesOnlyToPolicedPassThrough(): void {
		$guard = $this->guard(self::rows());

		self::assertTrue($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'reimbursable', 'reimbursementPolicyId' => 'POL-1', 'totalPassThroughAmount' => 1000]));
		self::assertTrue($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'pass-through', 'reimbursementPolicyId' => '', 'totalPassThroughAmount' => 1000]));
		self::assertTrue($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'pass-through', 'reimbursementPolicyId' => 'POL-2', 'totalPassThroughAmount' => 1000]));
		self::assertTrue($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'pass-through', 'reimbursementPolicyId' => 'POL-9', 'totalPassThroughAmount' => 1000]));
		self::assertTrue($guard->requireMarkupApprovalIfThreshold(['settlementMode' => 'pass-through', 'reimbursementPolicyId' => 'POL-1', 'totalPassThroughAmount' => 0]));
		self::assertSame([], $this->found);

	}//end testTheMarkupGateAppliesOnlyToPolicedPassThrough()

	/**
	 * The cost-weighted rate decides the markup; zero-cost and non-pass-through items do not count.
	 *
	 * @return void
	 */
	public function testTheWeightedMarkupDecides(): void {
		// Rates: 100 @ 0.10, 300 @ 0.20, 100 @ 0.0 -> (10 + 60 + 0) / 500 = 0.14; markup = T * 0.14 / 1.14.
		$claim = [
			'id' => 'cl-5',
			'settlementMode' => 'pass-through',
			'reimbursementPolicyId' => 'POL-1',
			'receiptIds' => ['r-pt', 'r-re', ''],
			'mileageIds' => ['m-pt', 'm-zero'],
			'perDiemIds' => ['p-pt', 'missing'],
		];

		self::assertTrue($this->guard(self::rows())->requireMarkupApprovalIfThreshold(['totalPassThroughAmount' => 200.0] + $claim));
		self::assertSame(['r-pt', 'r-re', 'm-pt', 'm-zero', 'p-pt', 'missing'], $this->found);

		self::assertFalse($this->guard(self::rows())->requireMarkupApprovalIfThreshold(['totalPassThroughAmount' => 204.0] + $claim));
		$last = end($this->log);
		self::assertSame('info', $last[0]);
		self::assertSame(['claimId' => 'cl-5', 'policyId' => 'POL-1', 'threshold' => 25.0], array_diff_key($last[2], ['markupAmount' => 0]));
		self::assertEqualsWithDelta(204.0 * 0.14 / 1.14, $last[2]['markupAmount'], 1e-9);

	}//end testTheWeightedMarkupDecides()

	/**
	 * Without a resolvable rate the whole pass-through amount counts as markup.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRateCountsTheWholeAmount(): void {
		$claim = ['settlementMode' => 'pass-through', 'reimbursementPolicyId' => 'POL-1', 'receiptIds' => ['r-re'], 'perDiemIds' => ['p-pt']];

		self::assertFalse($this->guard(self::rows())->requireMarkupApprovalIfThreshold(['totalPassThroughAmount' => 25.0] + $claim));
		self::assertTrue($this->guard(self::rows())->requireMarkupApprovalIfThreshold(['totalPassThroughAmount' => 24.99] + $claim));

	}//end testAnUnresolvableRateCountsTheWholeAmount()
}//end class
