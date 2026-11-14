<?php

/**
 * Unit tests for PaymentPlanAllocator: instalments first, invoices oldest
 * first, overpayments, and what happens when an invoice cannot be paid.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\PaymentPlan\PaymentPlanAllocator;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
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
 * The allocator over an in-memory store, with the transition runner and the
 * logger as doubles.
 */
#[CoversClass(PaymentPlanAllocator::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PaymentPlanAllocatorTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The transition runner double.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Transitions run, as "id:action".
	 *
	 * @var array<int,string>
	 */
	private array $ran = [];

	/**
	 * Build the store and the doubles.
	 *
	 * @param array<int,array<string,mixed>> $instalments The plan's instalments.
	 * @param array<int,array<string,mixed>> $invoices    The invoices.
	 *
	 * @return PaymentPlanAllocator
	 */
	private function allocator(array $instalments, array $invoices): PaymentPlanAllocator {
		$this->store = new InMemoryObjectServiceStub(['PaymentPlanInstalment' => $instalments, 'ARInvoice' => $invoices]);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$this->transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->ran[] = $objectId . ':' . $action;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new PaymentPlanAllocator(
			$this->store,
			$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			$this->transitions,
			$this->logger
		);

	}//end allocator()

	/**
	 * A stored object's data.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $schema, string $id): array {
		$entity = $this->store->find(id: $id, schema: $schema);
		$this->assertNotNull($entity, $schema . ' ' . $id . ' is stored');
		return $entity->getObject();

	}//end stored()

	/**
	 * An instalment row.
	 *
	 * @param int    $number The instalment number.
	 * @param float  $amount Its amount.
	 * @param float  $paid   What is already paid on it.
	 * @param string $state  Its state.
	 *
	 * @return array<string,mixed>
	 */
	private static function instalment(int $number, float $amount, float $paid = 0.0, string $state = 'pending'): array {
		return [
			'id' => 'inst-' . $number, 'planId' => 'plan-1', 'instalmentNumber' => $number,
			'amount' => $amount, 'paidAmount' => $paid, 'state' => $state,
		];

	}//end instalment()

	/**
	 * An invoice row.
	 *
	 * @param string $id      The id.
	 * @param string $dueDate Its due date.
	 * @param float  $due     The amount due.
	 * @param string $state   Its lifecycle state.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(string $id, string $dueDate, float $due, string $state = 'overdue'): array {
		return [
			'id' => $id, 'invoiceNumber' => 'N-' . $id, 'dueDate' => $dueDate, 'invoiceDate' => '2026-01-01',
			'amountDue' => $due, 'paidAmount' => 0, 'lifecycleState' => $state,
		];

	}//end invoice()

	/**
	 * An exact instalment payment pays the instalment and the oldest invoice first.
	 *
	 * @return void
	 */
	public function testAnExactPaymentPaysTheInstalmentAndTheOldestInvoiceFirst(): void {
		$allocator = $this->allocator(
			[self::instalment(2, 100.0), self::instalment(1, 100.0)],
			[self::invoice('inv-new', '2026-03-01', 150.0), self::invoice('inv-old', '2026-02-01', 50.0, 'issued')]
		);

		$result = $allocator->allocate(['id' => 'plan-1', 'invoiceIds' => ['inv-new', 'inv-old']], 100.0, '2026-04-01', 'bank');

		$this->assertSame(100.0, $result['allocated']);
		$this->assertSame(0.0, $result['unallocated']);
		$this->assertSame(['inv-old' => 50.0, 'inv-new' => 50.0], $result['invoices']);
		$this->assertSame([1], $result['instalmentsPaid']);
		$this->assertFalse($result['allPaid']);

		$first = $this->stored('PaymentPlanInstalment', 'inst-1');
		$this->assertSame(100.0, $first['paidAmount']);
		$this->assertSame('2026-04-01', $first['paidDate']);
		$this->assertCount(2, $first['allocations']);
		$this->assertSame(
			['invoiceId' => 'inv-old', 'invoiceNumber' => 'N-inv-old', 'amount' => 50.0, 'paidDate' => '2026-04-01', 'source' => 'bank'],
			$first['allocations'][0]
		);

		$old = $this->stored('ARInvoice', 'inv-old');
		$this->assertSame(0.0, $old['amountDue']);
		$this->assertSame(50.0, $old['paidAmount']);
		$new = $this->stored('ARInvoice', 'inv-new');
		$this->assertSame(100.0, $new['amountDue']);

		$this->assertSame(['inst-1:pay', 'inv-old:mark-paid'], $this->ran);

	}//end testAnExactPaymentPaysTheInstalmentAndTheOldestInvoiceFirst()

	/**
	 * A partial payment leaves the instalment open: no paid date, no transition.
	 *
	 * @return void
	 */
	public function testAPartialPaymentRecordsTheAmountButDoesNotPayTheInstalment(): void {
		$allocator = $this->allocator([self::instalment(1, 100.0, 20.0)], [self::invoice('inv-a', '2026-02-01', 80.0)]);

		$result = $allocator->allocate(['id' => 'plan-1', 'invoiceIds' => ['inv-a']], 30.0, '2026-04-01', 'by-hand');

		$this->assertSame(30.0, $result['allocated']);
		$this->assertSame([], $result['instalmentsPaid']);
		$instalment = $this->stored('PaymentPlanInstalment', 'inst-1');
		$this->assertSame(50.0, $instalment['paidAmount']);
		$this->assertArrayNotHasKey('paidDate', $instalment);
		$this->assertSame(50.0, $this->stored('ARInvoice', 'inv-a')['amountDue']);
		$this->assertSame([], $this->ran);

	}//end testAPartialPaymentRecordsTheAmountButDoesNotPayTheInstalment()

	/**
	 * An overpayment pays the next instalments and the rest stays unallocated.
	 *
	 * @return void
	 */
	public function testAnOverpaymentPaysTheNextInstalmentsAndReportsTheRest(): void {
		$allocator = $this->allocator(
			[self::instalment(1, 50.0, 50.0, 'paid'), self::instalment(2, 50.0), self::instalment(3, 50.0)],
			[self::invoice('inv-a', '2026-02-01', 100.0)]
		);

		$result = $allocator->allocate(['id' => 'plan-1', 'invoiceIds' => ['inv-a']], 130.0, '2026-04-01', 'payment-link');

		$this->assertSame(100.0, $result['allocated']);
		$this->assertSame(30.0, $result['unallocated']);
		$this->assertSame([2, 3], $result['instalmentsPaid']);
		$this->assertSame(['inv-a' => 100.0], $result['invoices']);
		$this->assertContains('inst-2:pay', $this->ran);
		$this->assertContains('inst-3:pay', $this->ran);
		$this->assertContains('inv-a:pay-overdue', $this->ran);

	}//end testAnOverpaymentPaysTheNextInstalmentsAndReportsTheRest()

	/**
	 * An invoice paid off in a state with no pay transition is logged, not moved.
	 *
	 * @return void
	 */
	public function testAFullyPaidInvoiceInAnUnpayableStateIsLoggedAndLeftAlone(): void {
		$allocator = $this->allocator([self::instalment(1, 40.0)], [self::invoice('inv-a', '2026-02-01', 40.0, 'draft')]);
		$this->logger->expects($this->once())->method('info')->with(
			'PaymentPlanAllocator: invoice fully paid but not in a payable state',
			['invoiceId' => 'inv-a']
		);

		$allocator->allocate(['id' => 'plan-1', 'invoiceIds' => ['inv-a']], 40.0, '2026-04-01', 'bank');

		$this->assertSame(['inst-1:pay'], $this->ran);
		$this->assertSame(0.0, $this->stored('ARInvoice', 'inv-a')['amountDue']);

	}//end testAFullyPaidInvoiceInAnUnpayableStateIsLoggedAndLeftAlone()

	/**
	 * A refused invoice transition is logged and the allocation still stands.
	 *
	 * @return void
	 */
	public function testARefusedInvoiceTransitionIsLoggedAndTheAllocationStands(): void {
		$allocator = $this->allocator([self::instalment(1, 40.0)], [self::invoice('inv-a', '2026-02-01', 40.0)]);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$this->transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action): void {
				if ($action === 'pay-overdue') {
					throw new RuntimeException('guard refused');
				}
			}
		);
		$allocator = new PaymentPlanAllocator(
			$this->store,
			$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			$this->transitions,
			$this->logger
		);
		$this->logger->expects($this->once())->method('error')->with(
			'PaymentPlanAllocator: invoice could not be moved to paid',
			['invoiceId' => 'inv-a', 'exception' => 'guard refused']
		);

		$result = $allocator->allocate(['id' => 'plan-1', 'invoiceIds' => ['inv-a']], 40.0, '2026-04-01', 'bank');

		$this->assertSame(40.0, $result['allocated']);
		$this->assertSame(40.0, $this->stored('ARInvoice', 'inv-a')['paidAmount']);

	}//end testARefusedInvoiceTransitionIsLoggedAndTheAllocationStands()

	/**
	 * AllPaid is true only when every instalment is paid, false for a plan without instalments.
	 *
	 * @return void
	 */
	public function testAllPaidNeedsEveryInstalmentPaidAndAtLeastOne(): void {
		$allocator = $this->allocator([self::instalment(1, 10.0, 10.0, 'paid'), self::instalment(2, 10.0, 10.0, 'paid')], []);
		$this->assertTrue($allocator->allocate(['id' => 'plan-1', 'invoiceIds' => []], 0.0, '2026-04-01', 'bank')['allPaid']);

		$empty = $this->allocator([], []);
		$result = $empty->allocate(['id' => 'plan-1', 'invoiceIds' => []], 25.0, '2026-04-01', 'bank');
		$this->assertFalse($result['allPaid']);
		$this->assertSame(0.0, $result['allocated']);
		$this->assertSame(25.0, $result['unallocated']);

	}//end testAllPaidNeedsEveryInstalmentPaidAndAtLeastOne()

	/**
	 * Instalments come back in schedule order and only for the asked plan.
	 *
	 * @return void
	 */
	public function testInstalmentsAreInScheduleOrderForThePlan(): void {
		$other = self::instalment(9, 10.0);
		$other['id'] = 'inst-other';
		$other['planId'] = 'plan-2';
		$allocator = $this->allocator([self::instalment(3, 10.0), self::instalment(1, 10.0), $other, self::instalment(2, 10.0)], []);

		$numbers = array_map(static fn (array $row): int => $row['instalmentNumber'], $allocator->instalments('plan-1'));

		$this->assertSame([1, 2, 3], $numbers);

	}//end testInstalmentsAreInScheduleOrderForThePlan()

	/**
	 * Invoices are keyed by id, sorted by due date, and missing ones are skipped.
	 *
	 * @return void
	 */
	public function testInvoicesOldestFirstSortsByDueDateAndSkipsMissingOnes(): void {
		$allocator = $this->allocator([], [self::invoice('inv-b', '2026-05-01', 1.0), self::invoice('inv-a', '2026-03-01', 1.0)]);

		$invoices = $allocator->invoicesOldestFirst(['invoiceIds' => ['inv-b', 'inv-missing', 'inv-a']]);

		$this->assertSame(['inv-a', 'inv-b'], array_keys($invoices));
		$this->assertSame('2026-03-01', $invoices['inv-a']['dueDate']);

	}//end testInvoicesOldestFirstSortsByDueDateAndSkipsMissingOnes()
}//end class
