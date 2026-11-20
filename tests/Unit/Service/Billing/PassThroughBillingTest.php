<?php

/**
 * PassThroughBillingTest
 *
 * @category  Tests
 * @package   OCA\Shillinq\Tests\Unit\Service\Billing
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Billing;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Service\Billing\PassThroughBilling;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PassThroughMarkupResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Ticked pass-through items as engine rows, and claims closed once fully billed.
 */
#[CoversClass(PassThroughBilling::class)]
#[UsesClass(PassThroughMarkupResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PassThroughBillingTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Seed the administration "adm-1": a posted pass-through claim of a train
	 * receipt (locked at 10 percent), a parking receipt (no rule), an
	 * unlocked receipt from before the lock, a reimbursable receipt, a receipt
	 * of another customer and one of another administration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			data: [
				'ExpenseClaimEntry' => [
					['id' => 'c-1', 'administrationId' => 'adm-1', 'state' => 'posted', 'settlementMode' => 'pass-through',
						'receiptIds' => ['r-train', 'r-parking', 'r-own']],
					['id' => 'c-draft', 'administrationId' => 'adm-1', 'state' => 'approved', 'receiptIds' => ['r-old']],
				],
				'Receipt' => [
					['id' => 'r-train', 'administrationId' => 'adm-1', 'claimId' => 'c-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'description' => 'Treinreis Utrecht-Zwolle v.v.', 'amountInBaseCurrency' => 42.80, 'receiptDate' => '2026-10-03',
						'markupRuleId' => 'rule-travel', 'markupRateApplied' => 0.10, 'markupAmountCalculated' => 4.28],
					['id' => 'r-parking', 'administrationId' => 'adm-1', 'claimId' => 'c-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'description' => 'Parkeren Zwolle', 'amountInBaseCurrency' => 12.50, 'receiptDate' => '2026-10-03',
						'markupRuleId' => null, 'markupRateApplied' => null, 'markupAmountCalculated' => 0.0],
					['id' => 'r-own', 'administrationId' => 'adm-1', 'claimId' => 'c-1', 'settlementMode' => 'reimbursable', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 8.00],
					['id' => 'r-old', 'administrationId' => 'adm-1', 'claimId' => 'c-draft', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'category' => 'travel', 'amountInBaseCurrency' => 20.00],
					['id' => 'r-other', 'administrationId' => 'adm-1', 'claimId' => 'c-1', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'other',
						'amountInBaseCurrency' => 9.00, 'markupAmountCalculated' => 0.0],
					['id' => 'r-foreign', 'administrationId' => 'adm-2', 'claimId' => 'c-9', 'settlementMode' => 'pass-through', 'linkedCustomerId' => 'anker',
						'amountInBaseCurrency' => 99.00, 'markupAmountCalculated' => 0.0],
				],
				'PassThroughMarkupRule' => [
					['id' => 'rule-travel', 'administrationId' => 'adm-1', 'targetCustomerId' => 'anker', 'targetCategory' => 'travel',
						'markupType' => 'percentage', 'markupValue' => 0.10],
				],
				'BillableInvoice' => [],
			]
		);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The subject over the store.
	 *
	 * @return PassThroughBilling
	 */
	private function billing(): PassThroughBilling {
		return new PassThroughBilling(
			objectService: $this->store,
			location: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			transitions: $this->transitions,
			logger: $this->logger,
		);
	}//end billing()

	/**
	 * A reference to a receipt.
	 *
	 * @param string $id           The receipt id.
	 * @param bool   $disbursement Ticked as a disbursement.
	 *
	 * @return array{schema:string,id:string,disbursement:bool}
	 */
	private static function receipt(string $id, bool $disbursement=false): array {
		return ['schema' => 'Receipt', 'id' => $id, 'disbursement' => $disbursement];
	}//end receipt()

	/**
	 * The train receipt carries its cost and the markup locked at submit.
	 *
	 * @return void
	 */
	public function testALockedItemBecomesARowWithItsCostAndMarkup(): void {
		$rows = $this->billing()->expenseRows(refs: [self::receipt(id: 'r-train')], administrationId: 'adm-1', customerId: 'anker');

		self::assertSame(
			[
				[
					'expenseId' => 'r-train',
					'description' => 'Treinreis Utrecht-Zwolle v.v.',
					'costAmountCents' => 4280,
					'recharge' => [
						'schema' => 'Receipt',
						'claimId' => 'c-1',
						'costAmountCents' => 4280,
						'markupRuleId' => 'rule-travel',
						'markupRate' => 0.10,
						'markupAmountCents' => 428,
						'disbursement' => false,
					],
				],
			],
			$rows
		);
		self::assertSame([], $this->store->saved, 'A locked item is not written again.');
	}//end testALockedItemBecomesARowWithItsCostAndMarkup()

	/**
	 * Reimbursable items, another customer's and another administration's,
	 * unknown ids and unknown schemas are skipped; the disbursement flag is kept.
	 *
	 * @return void
	 */
	public function testOnlyTheCustomersPassThroughItemsOfTheAdministrationAreBilled(): void {
		$rows = $this->billing()->expenseRows(
			refs: [
				self::receipt(id: 'r-own'),
				self::receipt(id: 'r-other'),
				self::receipt(id: 'r-foreign'),
				self::receipt(id: 'r-missing'),
				['schema' => 'UrenRegistratie', 'id' => 'r-parking', 'disbursement' => false],
				self::receipt(id: 'r-parking', disbursement: true),
			],
			administrationId: 'adm-1',
			customerId: 'anker'
		);

		self::assertCount(1, $rows);
		self::assertSame('r-parking', $rows[0]['expenseId']);
		self::assertNull($rows[0]['recharge']['markupRate']);
		self::assertSame(0, $rows[0]['recharge']['markupAmountCents']);
		self::assertTrue($rows[0]['recharge']['disbursement']);
	}//end testOnlyTheCustomersPassThroughItemsOfTheAdministrationAreBilled()

	/**
	 * An item submitted before the lock existed is resolved now and written once.
	 *
	 * @return void
	 */
	public function testAnUnlockedItemIsLockedOnceWhenBilled(): void {
		$rows = $this->billing()->expenseRows(refs: [self::receipt(id: 'r-old')], administrationId: 'adm-1', customerId: 'anker');

		self::assertSame(2000, $rows[0]['costAmountCents']);
		self::assertSame(0.10, $rows[0]['recharge']['markupRate']);
		self::assertSame(200, $rows[0]['recharge']['markupAmountCents']);
		self::assertCount(1, $this->store->saved);
		self::assertSame('Receipt', $this->store->saved[0]['schema']);
		self::assertSame('rule-travel', $this->store->saved[0]['object']['markupRuleId']);
		self::assertSame(2.0, $this->store->saved[0]['object']['markupAmountCalculated']);
	}//end testAnUnlockedItemIsLockedOnceWhenBilled()

	/**
	 * A claim billed in two invoices takes markInvoiced only once the second
	 * invoice, holding its last pass-through item, is posted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.3
	 */
	public function testAClaimBilledInTwoInvoicesIsMarkedInvoicedAfterTheSecond(): void {
		$first = ['id' => 'inv-1', 'administrationId' => 'adm-1', 'status' => 'posted',
			'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-train', 'claimId' => 'c-1', 'disbursement' => false]]];
		$second = ['id' => 'inv-2', 'administrationId' => 'adm-1', 'status' => 'posted',
			'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-parking', 'claimId' => 'c-1', 'disbursement' => false]]];

		$this->transitions->expects(self::once())->method('run')->with('c-1', 'markInvoiced');

		self::assertSame([], $this->billing()->markBilledClaims(invoice: $first));
		$this->store->saveObject(object: $first, options: new ObjectSaveOptions(target: new ObjectFindTarget(schema: 'BillableInvoice')));
		self::assertSame(['c-1'], $this->billing()->markBilledClaims(invoice: $second));
	}//end testAClaimBilledInTwoInvoicesIsMarkedInvoicedAfterTheSecond()

	/**
	 * A draft invoice holding the other item does not count as billed.
	 *
	 * @return void
	 */
	public function testAnItemOnADraftInvoiceDoesNotCloseTheClaim(): void {
		$this->store->saveObject(
			object: ['id' => 'inv-d', 'administrationId' => 'adm-1', 'status' => 'draft',
				'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-train', 'claimId' => 'c-1']]],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(schema: 'BillableInvoice'))
		);
		$this->transitions->expects(self::never())->method('run');

		$marked = $this->billing()->markBilledClaims(
			invoice: ['id' => 'inv-2', 'administrationId' => 'adm-1', 'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-parking', 'claimId' => 'c-1']]]
		);

		self::assertSame([], $marked);
	}//end testAnItemOnADraftInvoiceDoesNotCloseTheClaim()

	/**
	 * A claim that is not posted is left; one the engine refuses is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAnUnpostedClaimIsLeftAndARefusalIsLogged(): void {
		$this->transitions->method('run')->willThrowException(new RuntimeException('guard refused'));
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains('guard refused'), self::callback(static fn (array $ctx): bool => $ctx['claimId'] === 'c-1'));

		$unposted = $this->billing()->markBilledClaims(
			invoice: ['id' => 'inv-3', 'administrationId' => 'adm-1', 'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-old', 'claimId' => 'c-draft']]]
		);
		$refused = $this->billing()->markBilledClaims(
			invoice: ['id' => 'inv-4', 'administrationId' => 'adm-1', 'expenseItemRefs' => [
				['schema' => 'Receipt', 'id' => 'r-train', 'claimId' => 'c-1'],
				['schema' => 'Receipt', 'id' => 'r-parking', 'claimId' => 'c-1'],
			]]
		);

		self::assertSame([], $unposted);
		self::assertSame([], $refused);
	}//end testAnUnpostedClaimIsLeftAndARefusalIsLogged()
}//end class
