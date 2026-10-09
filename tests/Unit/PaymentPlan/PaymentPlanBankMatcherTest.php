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

use OCA\Shillinq\Service\Bank\ExactMatchBooker;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Bank lines that pay a plan (REQ-RPPL-003, design.md D4.1).
 */
final class PaymentPlanBankMatcherTest extends TestCase {
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
	 * The first instalment arrives by bank with the reference: booked on arrival,
	 * the instalment paid and invoice 2026-0231 down to 1,411.67.
	 *
	 * @return void
	 */
	public function testALineNamingTheReferenceIsBookedToThePlan(): void {
		$plan = $this->activeZwaanPlan();
		$line = $this->line('line-t1', 403.33, 'RGL-2026-0007 termijn 1');

		$candidates = $this->matcher()->candidates($line);
		$this->assertSame('high', $candidates[0]['confidence']);

		$match = $this->matcher()->book($line);

		$this->assertNotNull($match);
		$this->assertSame($plan['id'], $match['paymentPlanId']);
		$this->assertTrue($match['isPartial']);
		$this->assertSame('confirmed', $match['status']);
		$stored = $match;
		unset($stored['allocation'], $stored['remainder']);
		$this->assertSame([], RegisterSchema::errors('ReconciliationMatch', $stored));
		$this->assertSame('paid', $this->all('PaymentPlanInstalment')[0]['state']);
		$this->assertSame('2026-11-01', $this->all('PaymentPlanInstalment')[0]['paidDate']);
		$this->assertSame(1411.67, $this->stored('ARInvoice', 'ar-0231')['amountDue']);
		$this->assertSame('confirmed', $this->stored('BankStatementLine', 'line-t1')['matchState']);
	}//end testALineNamingTheReferenceIsBookedToThePlan()

	/**
	 * The next instalment's amount from the customer's IBAN is offered at
	 * medium confidence and not booked.
	 *
	 * @return void
	 */
	public function testTheAmountFromTheCustomersAccountIsOnlyOffered(): void {
		$this->activeZwaanPlan();
		$line = $this->line('line-t1', 403.33, 'termijn november');

		$candidates = $this->matcher()->candidates($line);

		$this->assertCount(1, $candidates);
		$this->assertSame('medium', $candidates[0]['confidence']);
		$this->assertNull($this->matcher()->book($line));
		$this->assertSame('pending', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testTheAmountFromTheCustomersAccountIsOnlyOffered()

	/**
	 * Another amount from another account is no candidate.
	 *
	 * @return void
	 */
	public function testALineThatFitsNoPlanIsLeftAlone(): void {
		$this->activeZwaanPlan();
		$line = $this->line('line-x', 403.33, 'termijn november', 'NL02ABNA0123456789');

		$this->assertSame([], $this->matcher()->candidates($line));
		$this->assertNull($this->matcher()->book($line));
	}//end testALineThatFitsNoPlanIsLeftAlone()

	/**
	 * A bank feed line naming the plan reaches the plan through the booker
	 * the feed intake calls on arrival.
	 *
	 * @return void
	 */
	public function testTheFeedBookerHandsAPlanLineToThePlan(): void {
		$plan = $this->activeZwaanPlan();
		$line = $this->line('line-feed', 403.33, 'RGL-2026-0007 termijn 1');
		$logger = $this->createMock(LoggerInterface::class);
		$matches = new ManualMatchService($this->store, $this->runner(), $this->settings(), $logger);
		$booker = new ExactMatchBooker($this->store, $matches, $this->settings(), $logger, $this->matcher());

		$match = $booker->book($line);

		$this->assertSame($plan['id'], $match['paymentPlanId'] ?? null);
		$this->assertSame('paid', $this->all('PaymentPlanInstalment')[0]['state']);
	}//end testTheFeedBookerHandsAPlanLineToThePlan()
}//end class
