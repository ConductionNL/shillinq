<?php

/**
 * Unit tests for SettlementGuard.
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
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SettlementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettlementGuard (expense-reimbursement-or-passthrough).
 *
 * Covers:
 * - REQ-ERP-003 mixed-mode rejection + single-mode submit;
 * - REQ-ERP-002 pass-through customer + AR-account validation;
 * - REQ-ERP-005 markup-rule priority lookup;
 * - REQ-ERP-002 markup amount (percentage + fixedAmount);
 * - REQ-ERP-003 claim aggregate totals;
 * - REQ-ERP-011 mode-change authorisation (bookkeeper-only after submit);
 * - REQ-ERP-008 reimbursement notification payload.
 */
class SettlementGuardTest extends TestCase {

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
	 * @var SettlementGuard
	 */
	private SettlementGuard $guard;

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

		$this->guard = new SettlementGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning records by schema.
	 *
	 * @param array<string, array<mixed>> $itemsBySchema Map of schema → records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $itemsBySchema = []): object {
		return new class($itemsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<mixed>>
			 */
			private array $itemsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			public string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<mixed>> $itemsBySchema Items by schema.
			 */
			public function __construct(array $itemsBySchema) {
				$this->itemsBySchema = $itemsBySchema;

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
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return all stubbed records for the current schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->itemsBySchema[$this->currentSchema] ?? [];
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Wire the container to return the given ObjectService stub.
	 *
	 * @param object $stub The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $stub): void {
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($stub);

	}//end withObjectService()

	/**
	 * REQ-ERP-003: a single-mode reimbursable claim passes submit.
	 *
	 * @return void
	 */
	public function testSingleModeReimbursableClaimPassesSubmit(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Receipt' => [
						['id' => 'r1', 'settlementMode' => 'reimbursable'],
						['id' => 'r2', 'settlementMode' => 'reimbursable'],
					],
				]
			)
		);

		$claim = ['id' => 'c1', 'settlementMode' => 'reimbursable'];
		self::assertTrue($this->guard->requireSettlementClassification($claim));

	}//end testSingleModeReimbursableClaimPassesSubmit()

	/**
	 * REQ-ERP-003: a mixed-mode claim is rejected at submit.
	 *
	 * @return void
	 */
	public function testMixedModeClaimIsRejected(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Receipt' => [
						['id' => 'r1', 'settlementMode' => 'reimbursable'],
						['id' => 'r2', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'CUST-1', 'passthroughDebitAccountCode' => '1300'],
					],
				]
			)
		);

		$claim = ['id' => 'c1', 'settlementMode' => 'reimbursable'];
		self::assertFalse($this->guard->requireSettlementClassification($claim));

	}//end testMixedModeClaimIsRejected()

	/**
	 * REQ-ERP-002: a pass-through receipt missing its AR account fails submit.
	 *
	 * @return void
	 */
	public function testPassThroughMissingArAccountIsRejected(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Receipt' => [
						['id' => 'r1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'CUST-1', 'passthroughDebitAccountCode' => ''],
					],
				]
			)
		);

		$claim = ['id' => 'c1', 'settlementMode' => 'pass-through'];
		self::assertFalse($this->guard->requireSettlementClassification($claim));

	}//end testPassThroughMissingArAccountIsRejected()

	/**
	 * REQ-ERP-002: a complete pass-through claim passes submit.
	 *
	 * @return void
	 */
	public function testCompletePassThroughClaimPassesSubmit(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Receipt' => [
						['id' => 'r1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'CUST-1', 'passthroughDebitAccountCode' => '1300'],
					],
				]
			)
		);

		$claim = ['id' => 'c1', 'settlementMode' => 'pass-through'];
		self::assertTrue($this->guard->requireSettlementClassification($claim));

	}//end testCompletePassThroughClaimPassesSubmit()

	/**
	 * REQ-ERP-005: customer+category rule beats customer-only and global.
	 *
	 * @return void
	 */
	public function testMarkupRulePriorityCustomerAndCategoryWins(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'PassThroughMarkupRule' => [
						['ruleId' => 'A', 'targetCustomerId' => 'CUST-123', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.15],
						['ruleId' => 'B', 'targetCustomerId' => 'CUST-123', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10],
						['ruleId' => 'C', 'targetCustomerId' => '', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.05],
					],
				]
			)
		);

		$rule = $this->guard->matchMarkupRule(
			customerId: 'CUST-123',
			category: 'travel',
			administrationId: 'adm-1',
			fiscalYear: 2026,
		);

		self::assertNotNull($rule);
		self::assertSame('B', $rule['ruleId']);

	}//end testMarkupRulePriorityCustomerAndCategoryWins()

	/**
	 * REQ-ERP-005: falls back to the global default when no customer rule.
	 *
	 * @return void
	 */
	public function testMarkupRuleFallsBackToGlobalDefault(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'PassThroughMarkupRule' => [
						['ruleId' => 'A', 'targetCustomerId' => 'CUST-999', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.15],
						['ruleId' => 'C', 'targetCustomerId' => '', 'targetCategory' => '', 'markupType' => 'percentage', 'markupValue' => 0.05],
					],
				]
			)
		);

		$rule = $this->guard->matchMarkupRule(
			customerId: 'CUST-123',
			category: 'travel',
			administrationId: 'adm-1',
			fiscalYear: 2026,
		);

		self::assertNotNull($rule);
		self::assertSame('C', $rule['ruleId']);

	}//end testMarkupRuleFallsBackToGlobalDefault()

	/**
	 * REQ-ERP-002: percentage markup multiplies the base amount.
	 *
	 * @return void
	 */
	public function testComputeMarkupAmountPercentage(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'PassThroughMarkupRule' => [
						['ruleId' => 'B', 'targetCustomerId' => 'CUST-123', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10],
					],
				]
			)
		);

		$receipt = [
			'settlementMode' => 'pass-through',
			'linkedCustomerId' => 'CUST-123',
			'category' => 'travel',
			'administrationId' => 'adm-1',
			'receiptDate' => '2026-03-01',
			'amountInBaseCurrency' => 100.0,
		];

		self::assertSame(10.0, $this->guard->computeMarkupAmount($receipt));

	}//end testComputeMarkupAmountPercentage()

	/**
	 * REQ-ERP-005: fixed-amount markup returns the flat value.
	 *
	 * @return void
	 */
	public function testComputeMarkupAmountFixed(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'PassThroughMarkupRule' => [
						['ruleId' => 'D', 'targetCustomerId' => '', 'targetCategory' => 'meals', 'markupType' => 'fixedAmount', 'markupValue' => 2.5],
					],
				]
			)
		);

		$receipt = [
			'settlementMode' => 'pass-through',
			'linkedCustomerId' => '',
			'category' => 'meals',
			'administrationId' => 'adm-1',
			'receiptDate' => '2026-03-01',
			'amountInBaseCurrency' => 35.0,
		];

		self::assertSame(2.5, $this->guard->computeMarkupAmount($receipt));

	}//end testComputeMarkupAmountFixed()

	/**
	 * REQ-ERP-002: reimbursable receipts have no markup.
	 *
	 * @return void
	 */
	public function testComputeMarkupAmountReimbursableIsNull(): void {
		self::assertNull(
			$this->guard->computeMarkupAmount(
				['settlementMode' => 'reimbursable', 'amountInBaseCurrency' => 100.0]
			)
		);

	}//end testComputeMarkupAmountReimbursableIsNull()

	/**
	 * REQ-ERP-003: claim aggregates split reimbursable vs pass-through totals.
	 *
	 * @return void
	 */
	public function testAggregateClaimTotals(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Receipt' => [
						['id' => 'r1', 'settlementMode' => 'reimbursable', 'amountInBaseCurrency' => 50.0],
						['id' => 'r2', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'CUST-123', 'category' => 'meals', 'administrationId' => 'adm-1', 'receiptDate' => '2026-03-01', 'amountInBaseCurrency' => 35.0],
						['id' => 'r3', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'CUST-456', 'category' => 'travel', 'administrationId' => 'adm-1', 'receiptDate' => '2026-03-01', 'amountInBaseCurrency' => 100.0],
					],
					'PassThroughMarkupRule' => [
						['ruleId' => 'M', 'targetCustomerId' => 'CUST-123', 'targetCategory' => 'meals', 'markupType' => 'percentage', 'markupValue' => 0.15],
						['ruleId' => 'T', 'targetCustomerId' => 'CUST-456', 'targetCategory' => 'travel', 'markupType' => 'percentage', 'markupValue' => 0.10],
					],
				]
			)
		);

		$totals = $this->guard->aggregateClaimTotals(['id' => 'c1']);

		self::assertSame(50.0, $totals['totalReimbursableAmount']);
		// r2: 35 + 35*0.15 (5.25) = 40.25 ; r3: 100 + 100*0.10 (10) = 110 ; total 150.25.
		self::assertSame(150.25, $totals['totalPassThroughAmount']);
		self::assertSame(['CUST-123', 'CUST-456'], $totals['passThroughCustomerIds']);

	}//end testAggregateClaimTotals()

	/**
	 * REQ-ERP-010/011: mode change is free while draft.
	 *
	 * @return void
	 */
	public function testModeChangeAllowedWhileDraft(): void {
		self::assertTrue(
			$this->guard->canChangeSettlementMode(['id' => 'c1', 'state' => 'draft'], ['roles' => []])
		);

	}//end testModeChangeAllowedWhileDraft()

	/**
	 * REQ-ERP-011: a non-bookkeeper cannot change mode after submission.
	 *
	 * @return void
	 */
	public function testModeChangeDeniedForNonBookkeeperAfterSubmit(): void {
		self::assertFalse(
			$this->guard->canChangeSettlementMode(
				['id' => 'c1', 'state' => 'submitted'],
				['roles' => ['employee']]
			)
		);

	}//end testModeChangeDeniedForNonBookkeeperAfterSubmit()

	/**
	 * REQ-ERP-011: a bookkeeper may change mode after submission (with reversal).
	 *
	 * @return void
	 */
	public function testModeChangeAllowedForBookkeeperAfterSubmit(): void {
		self::assertTrue(
			$this->guard->canChangeSettlementMode(
				['id' => 'c1', 'state' => 'posted'],
				['roles' => ['bookkeeper']]
			)
		);

	}//end testModeChangeAllowedForBookkeeperAfterSubmit()

	/**
	 * REQ-ERP-008: reimbursable post yields a notification payload.
	 *
	 * @return void
	 */
	public function testReimbursementNotificationPayload(): void {
		$claim = [
			'id' => 'c1',
			'claimNumber' => 'EXP-2026-0001',
			'settlementMode' => 'reimbursable',
			'employeeId' => 'EMP-123',
			'employeeBankAccount' => 'NL12ABCD0123456789',
			'totalReimbursableAmount' => 500.0,
			'currency' => 'EUR',
			'glReimbursableTransactionId' => 'GL-2026-001234',
		];

		$event = $this->guard->onReimbursablePosted($claim, 'POL-NL-01');

		self::assertNotNull($event);
		self::assertSame('ExpenseClaimReimbursementNotification', $event['event']);
		self::assertSame('EXP-2026-0001', $event['payload']['claimId']);
		self::assertSame('NL12ABCD0123456789', $event['payload']['employeeBankAccount']);
		self::assertSame(500.0, $event['payload']['amount']);
		self::assertSame('GL-2026-001234', $event['payload']['glEntryId']);
		self::assertSame('POL-NL-01', $event['payload']['policyId']);

	}//end testReimbursementNotificationPayload()

	/**
	 * REQ-ERP-008: a pass-through claim yields no reimbursement notification.
	 *
	 * @return void
	 */
	public function testNoNotificationForPassThroughClaim(): void {
		self::assertNull(
			$this->guard->onReimbursablePosted(['settlementMode' => 'pass-through'], 'POL-NL-01')
		);

	}//end testNoNotificationForPassThroughClaim()
}//end class
