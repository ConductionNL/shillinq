<?php

/**
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

use OCA\Shillinq\PaymentPlan\PaymentPlanRefusedException;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Agree, follow and end a payment plan (REQ-RPPL-001 to REQ-RPPL-005).
 */
final class PaymentPlanServiceTest extends TestCase {
	use PaymentPlanFixture;

	/**
	 * Seed Café De Zwaan.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * REQ-RPPL-001: six monthly instalments, numbered after the six earlier plans,
	 * every payload valid against the register.
	 *
	 * @return void
	 */
	public function testACafeAgreesSixMonthlyInstalments(): void {
		$draft = $this->service()->draft(
			['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0266', 'ar-0231'], 'instalmentCount' => 6, 'frequency' => 'monthly', 'firstDueDate' => '2026-11-01', 'graceDays' => 14],
			'bookkeeper'
		);

		$plan = $draft['plan'];
		$this->assertSame('RGL-2026-0007', $plan['planNumber']);
		$this->assertSame('RGL-2026-0007', $plan['paymentReference']);
		$this->assertSame(2420.0, $plan['totalAmount']);
		$this->assertSame(['ar-0231', 'ar-0266'], $plan['invoiceIds'], 'oldest invoice first');
		$this->assertSame('Café De Zwaan', $plan['customerName']);
		$this->assertSame([403.33, 403.33, 403.33, 403.33, 403.33, 403.35], array_column($draft['instalments'], 'amount'));
		$this->assertSame([], RegisterSchema::errors('PaymentPlan', $plan));
		foreach ($draft['instalments'] as $instalment) {
			$this->assertSame([], RegisterSchema::errors('PaymentPlanInstalment', $instalment));
		}
	}//end testACafeAgreesSixMonthlyInstalments()

	/**
	 * REQ-RPPL-001 and REQ-RPPL-002: activation pauses dunning of both invoices
	 * until the last due date plus grace, stamps the plan and mails the schedule.
	 *
	 * @return void
	 */
	public function testActivationPausesDunningAndMailsTheSchedule(): void {
		$plan = $this->activeZwaanPlan();

		$this->assertSame('active', $plan['lifecycleState']);
		$pauses = $this->all('DunningPauseDispute');
		$this->assertCount(2, $pauses);
		foreach ($pauses as $pause) {
			$this->assertSame('PAYMENT_PLAN', $pause['reason']);
			$this->assertSame('2027-04-15', substr((string)$pause['hardDeadlineEindigt'], 0, 10));
			$this->assertStringContainsString('RGL-2026-0007', (string)$pause['details']);
			$this->assertSame([], RegisterSchema::errors('DunningPauseDispute', $pause));
		}

		$this->assertCount(2, $plan['pauseIds']);
		$this->assertSame($plan['id'], $this->stored('ARInvoice', 'ar-0231')['paymentPlanId']);
		$this->assertSame($plan['id'], $this->stored('ARInvoice', 'ar-0266')['paymentPlanId']);
		$this->assertTrue($this->dunning()->hasActivePause('adm-hoekstra', 'ar-0231'), 'no reminder while the plan is kept');

		$this->assertCount(1, $this->mails);
		$mail = $this->mails[0];
		$this->assertSame(['administratie@dezwaan.example'], $mail['to']);
		$this->assertStringContainsString('RGL-2026-0007', $mail['subject']);
		$this->assertStringContainsString('NL20INGB0001234567', $mail['body']);
		$this->assertStringContainsString('2026-0231', $mail['body']);
		$this->assertStringContainsString('6. 2027-04-01: EUR 403.35', $mail['body']);
		$this->assertNotNull($plan['confirmationSentAt']);
	}//end testActivationPausesDunningAndMailsTheSchedule()

	/**
	 * REQ-RPPL-003: the first instalment is allocated in full to the oldest invoice.
	 *
	 * @return void
	 */
	public function testTheFirstInstalmentPaysTheOldestInvoice(): void {
		$plan = $this->activeZwaanPlan();

		$result = $this->service()->receive((string)$plan['id'], 403.33, '2026-11-01', 'by-hand');

		$this->assertSame([1], $result['instalmentsPaid']);
		$first = $this->all('PaymentPlanInstalment')[0];
		$this->assertSame('paid', $first['state']);
		$this->assertSame('2026-11-01', $first['paidDate']);
		$this->assertSame('ar-0231', $first['allocations'][0]['invoiceId']);
		$invoice = $this->stored('ARInvoice', 'ar-0231');
		$this->assertSame(403.33, $invoice['paidAmount']);
		$this->assertSame(1411.67, $invoice['amountDue']);
		$this->assertSame('overdue', $invoice['lifecycleState']);
		$this->assertSame(403.33, $result['plan']['paidAmount']);
		$this->assertSame('2026-12-01', $result['plan']['nextDueDate']);
	}//end testTheFirstInstalmentPaysTheOldestInvoice()

	/**
	 * A partial payment stays on the instalment, which stays open.
	 *
	 * @return void
	 */
	public function testAPartialPaymentLeavesTheInstalmentOpen(): void {
		$plan = $this->activeZwaanPlan();

		$result = $this->service()->receive((string)$plan['id'], 200.00, '2026-11-01', 'by-hand');

		$this->assertSame([], $result['instalmentsPaid']);
		$first = $this->all('PaymentPlanInstalment')[0];
		$this->assertSame('pending', $first['state']);
		$this->assertSame(200.0, $first['paidAmount']);
		$this->assertSame(203.33, $result['plan']['nextDueAmount']);
	}//end testAPartialPaymentLeavesTheInstalmentOpen()

	/**
	 * An amount above the instalment pays the next ones in order, and crosses
	 * from the oldest invoice to the next.
	 *
	 * @return void
	 */
	public function testAnOverpaymentPaysTheNextInstalments(): void {
		$plan = $this->activeZwaanPlan();

		$result = $this->service()->receive((string)$plan['id'], 1900.00, '2026-11-01', 'by-hand');

		$this->assertSame([1, 2, 3, 4], $result['instalmentsPaid']);
		$fifth = $this->all('PaymentPlanInstalment')[4];
		$this->assertSame(286.68, $fifth['paidAmount']);
		$this->assertSame('paid', $this->stored('ARInvoice', 'ar-0231')['lifecycleState'], 'the oldest invoice is paid off');
		$this->assertSame(85.0, $this->stored('ARInvoice', 'ar-0266')['paidAmount']);
		$this->assertSame(['ar-0231' => 1815.0, 'ar-0266' => 85.0], $result['invoices']);
	}//end testAnOverpaymentPaysTheNextInstalments()

	/**
	 * REQ-RPPL-005: the last instalment completes the plan, pays both invoices
	 * and closes the pauses.
	 *
	 * @return void
	 */
	public function testTheLastInstalmentCompletesThePlan(): void {
		$plan = $this->activeZwaanPlan();
		$service = $this->service();
		for ($i = 0; $i < 5; $i++) {
			$service->receive((string)$plan['id'], 403.33, '2026-11-01', 'bank');
		}

		$result = $service->receive((string)$plan['id'], 403.35, '2027-04-01', 'bank');

		$this->assertSame('completed', $result['plan']['lifecycleState']);
		$this->assertSame('paid', $this->stored('ARInvoice', 'ar-0231')['lifecycleState']);
		$this->assertSame('paid', $this->stored('ARInvoice', 'ar-0266')['lifecycleState']);
		$this->assertSame(0.0, $this->stored('ARInvoice', 'ar-0266')['amountDue']);
		$this->assertSame(['resolved', 'resolved'], array_column($this->all('DunningPauseDispute'), 'lifecycleState'));
	}//end testTheLastInstalmentCompletesThePlan()

	/**
	 * REQ-RPPL-004: an instalment unpaid past its grace period breaks the plan,
	 * resumes dunning and tells the customer. A day earlier it is only due.
	 *
	 * @return void
	 */
	public function testAMissedInstalmentBreaksThePlan(): void {
		$plan = $this->activeZwaanPlan();

		$this->today = '2026-11-15';
		$counts = $this->service()->monitor();
		$this->assertSame(['due' => 1, 'missed' => 0, 'broken' => 0, 'completed' => 0], $counts);
		$this->assertSame('active', $this->stored('PaymentPlan', (string)$plan['id'])['lifecycleState']);
		$this->assertSame(403.33, $this->stored('PaymentPlan', (string)$plan['id'])['arrears']);

		$this->today = '2026-11-16';
		$counts = $this->service()->monitor();

		$this->assertSame(1, $counts['broken']);
		$stored = $this->stored('PaymentPlan', (string)$plan['id']);
		$this->assertSame('broken', $stored['lifecycleState']);
		$this->assertSame('Instalment 1, due on 2026-11-01, was not paid.', $stored['endReason']);
		$this->assertSame('missed', $this->all('PaymentPlanInstalment')[0]['state']);
		$this->assertFalse($this->dunning()->hasActivePause('adm-hoekstra', 'ar-0231'), 'the invoice is back in the dunning ladder');
		$this->assertStringContainsString('has ended', $this->mails[1]['subject']);
		$this->assertSame(['administratie@dezwaan.example'], $this->mails[1]['to']);
	}//end testAMissedInstalmentBreaksThePlan()

	/**
	 * An invoice of another customer cannot join the plan.
	 *
	 * @return void
	 */
	public function testAnotherCustomersInvoiceIsRefused(): void {
		$this->expectException(PaymentPlanRefusedException::class);
		$this->expectExceptionMessage('Invoice 2026-0100 belongs to another customer.');

		$this->service()->draft(['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0231', 'ar-other'], 'instalmentCount' => 2, 'frequency' => 'monthly', 'firstDueDate' => '2026-11-01'], 'bookkeeper');
	}//end testAnotherCustomersInvoiceIsRefused()

	/**
	 * An invoice that is not yet due cannot join the plan.
	 *
	 * @return void
	 */
	public function testAnInvoiceNotYetDueIsRefused(): void {
		$this->expectException(PaymentPlanRefusedException::class);
		$this->expectExceptionMessage('Invoice 2026-0300 is not overdue with an amount due.');

		$this->service()->draft(['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0300'], 'instalmentCount' => 2, 'frequency' => 'monthly', 'firstDueDate' => '2026-11-01'], 'bookkeeper');
	}//end testAnInvoiceNotYetDueIsRefused()

	/**
	 * An invoice already on an active plan cannot join a second one.
	 *
	 * @return void
	 */
	public function testAnInvoiceOnAnActivePlanIsRefused(): void {
		$this->activeZwaanPlan();

		$this->expectException(PaymentPlanRefusedException::class);
		$this->expectExceptionMessage('Invoice 2026-0231 is already on payment plan RGL-2026-0007.');
		$this->service()->draft(['administrationId' => 'adm-hoekstra', 'customerId' => 'cust-zwaan', 'invoiceIds' => ['ar-0231'], 'instalmentCount' => 2, 'frequency' => 'monthly', 'firstDueDate' => '2026-11-01'], 'bookkeeper');
	}//end testAnInvoiceOnAnActivePlanIsRefused()
}//end class
