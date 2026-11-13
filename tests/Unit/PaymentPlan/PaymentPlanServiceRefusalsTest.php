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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plans drawn up with charges or a fixed instalment amount, and what a plan refuses.
 */
final class PaymentPlanServiceRefusalsTest extends TestCase {
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
	 * The Café De Zwaan draft input with the given overrides.
	 *
	 * @param array<string,mixed> $overrides Members to replace.
	 *
	 * @return array<string,mixed>
	 */
	private static function input(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-hoekstra',
				'customerId'       => 'cust-zwaan',
				'invoiceIds'       => ['ar-0266', 'ar-0231'],
				'instalmentCount'  => 6,
				'frequency'        => 'monthly',
				'firstDueDate'     => '2026-11-01',
			],
			$overrides
		);
	}//end input()

	/**
	 * The message a refused call gives.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private static function refusal(callable $call): string {
		try {
			$call();
		} catch (PaymentPlanRefusedException $e) {
			return $e->getMessage();
		}

		self::fail('The call was not refused.');
	}//end refusal()

	/**
	 * Agreed collection charges are added to the plan total; a negative charge counts as none.
	 *
	 * @return void
	 */
	public function testChargesAreAddedToTheTotal(): void {
		$withCharges = $this->service()->draft(self::input(['includesCharges' => true, 'chargesAmount' => 40.005]), 'bookkeeper')['plan'];

		$this->assertSame(2460.01, $withCharges['totalAmount']);
		$this->assertSame(40.01, $withCharges['chargesAmount']);
		$this->assertTrue($withCharges['includesCharges']);

		$negative = $this->service()->draft(self::input(['includesCharges' => true, 'chargesAmount' => -15]), 'bookkeeper')['plan'];
		$this->assertSame(2420.0, $negative['totalAmount']);

		$notAgreed = $this->service()->draft(self::input(['includesCharges' => 'yes', 'chargesAmount' => 40]), 'bookkeeper')['plan'];
		$this->assertSame(2420.0, $notAgreed['totalAmount']);
		$this->assertFalse($notAgreed['includesCharges']);
	}//end testChargesAreAddedToTheTotal()

	/**
	 * Without a count, a fixed instalment amount sets the schedule; a count wins over an amount.
	 *
	 * @return void
	 */
	public function testAFixedInstalmentAmountSetsTheSchedule(): void {
		$byAmount = $this->service()->draft(self::input(['instalmentCount' => 0, 'instalmentAmount' => 500]), 'bookkeeper');

		$this->assertSame(500.0, $byAmount['plan']['instalmentAmount']);
		$this->assertSame(5, $byAmount['plan']['instalmentCount']);
		$this->assertSame(2420.0, round(array_sum(array_column($byAmount['instalments'], 'amount')), 2));

		$byCount = $this->service()->draft(self::input(['instalmentAmount' => 500]), 'bookkeeper');
		$this->assertNull($byCount['plan']['instalmentAmount']);
		$this->assertSame(6, $byCount['plan']['instalmentCount']);
	}//end testAFixedInstalmentAmountSetsTheSchedule()

	/**
	 * A schedule the calendar refuses is refused as a plan, with the schedule's reason.
	 *
	 * @return void
	 */
	public function testAnImpossibleScheduleIsRefusedWithItsReason(): void {
		$this->assertSame(
			'Choose a first due date and a weekly or monthly frequency.',
			self::refusal(fn () => $this->service()->draft(self::input(['frequency' => 'yearly']), 'bookkeeper'))
		);
		$this->assertSame(
			'Choose a number of instalments or an instalment amount.',
			self::refusal(fn () => $this->service()->draft(self::input(['instalmentCount' => 0]), 'bookkeeper'))
		);
		$this->assertSame([], array_values(array_filter($this->all('PaymentPlan'), static fn (array $p): bool => $p['lifecycleState'] === 'draft')));
	}//end testAnImpossibleScheduleIsRefusedWithItsReason()

	/**
	 * Incomplete input and unknown invoices.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function badDrafts(): array {
		return [
			'no customer'          => [['customerId' => ''], 'Choose the customer and at least one overdue invoice.'],
			'no invoices'          => [['invoiceIds' => ['', null]], 'Choose the customer and at least one overdue invoice.'],
			'no administration'    => [['administrationId' => ''], 'Choose the customer and at least one overdue invoice.'],
			'unknown invoice'      => [['invoiceIds' => ['ar-9999']], 'Invoice ar-9999 could not be found.'],
			'other administration' => [['administrationId' => 'adm-elsewhere'], 'Invoice ar-0266 could not be found.'],
		];
	}//end badDrafts()

	/**
	 * A draft without customer or invoices, or with an invoice not in this administration, is refused.
	 *
	 * @param array<string,mixed> $overrides The input changes.
	 * @param string              $message   The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('badDrafts')]
	public function testABadDraftIsRefused(array $overrides, string $message): void {
		$this->assertSame($message, self::refusal(fn () => $this->service()->draft(self::input($overrides), 'bookkeeper')));
	}//end testABadDraftIsRefused()

	/**
	 * Only a draft activates, an unknown plan does not exist, and a payment needs an amount above zero.
	 *
	 * @return void
	 */
	public function testWhatAPlanRefuses(): void {
		$plan = $this->activeZwaanPlan();
		$service = $this->service();

		$this->assertSame('Only a draft plan can be activated.', self::refusal(fn () => $service->activate((string)$plan['id'], 'bookkeeper')));
		$this->assertSame('This payment plan does not exist.', self::refusal(fn () => $service->plan('plan-nope')));
		$this->assertSame('Enter an amount above zero.', self::refusal(fn () => $service->receive((string)$plan['id'], 0.0, '2026-11-01', 'manual')));
		$this->assertSame(
			'Payments are only taken on an active plan.',
			self::refusal(fn () => $service->receive('plan-old-1', 10.0, '2026-11-01', 'manual'))
		);
		$this->assertSame('pending', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testWhatAPlanRefuses()
}//end class
