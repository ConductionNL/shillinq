<?php

/**
 * Unit tests for ExactMatchBooker::book().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Bank
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

namespace OCA\Shillinq\Tests\Unit\Service\Bank;

use OCA\Shillinq\PaymentPlan\PaymentPlanBankMatcher;
use OCA\Shillinq\Service\Bank\ExactMatchBooker;
use OCA\Shillinq\Service\Bank\ManualMatchService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins which line ExactMatchBooker books, to what, and with which reason (REQ-BCON-003).
 */
final class ExactMatchBookerTest extends TestCase {

	/**
	 * The match writer.
	 *
	 * @var ManualMatchService&MockObject
	 */
	private ManualMatchService $matches;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the match writer and logger mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matches = $this->createMock(ManualMatchService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The booker over a fixed set of invoices.
	 *
	 * @param PaymentPlanBankMatcher|null $plans The payment-plan matcher, if any.
	 *
	 * @return ExactMatchBooker
	 */
	private function booker(?PaymentPlanBankMatcher $plans = null): ExactMatchBooker {
		$store = new InMemoryObjectServiceStub(
			[
				'ARInvoice' => [
					['id' => 'ar-1', 'invoiceNumber' => 'VF-1', 'lifecycleState' => 'issued', 'grossAmount' => 1210.00, 'administrationId' => 'adm'],
					['id' => 'ar-2', 'invoiceNumber' => 'VF-2', 'paymentReference' => 'RF-22', 'lifecycleState' => 'overdue', 'amountDue' => 50.00, 'grossAmount' => 99.00, 'administrationId' => 'adm'],
					['id' => 'ar-3', 'invoiceNumber' => 'VF-3', 'lifecycleState' => 'paid', 'grossAmount' => 75.00, 'administrationId' => 'adm'],
					['id' => 'ar-4', 'invoiceNumber' => 'VF-4', 'lifecycleState' => 'issued', 'grossAmount' => 60.00, 'administrationId' => 'adm'],
					['id' => 'ar-5', 'invoiceNumber' => 'VF-5', 'lifecycleState' => 'issued', 'grossAmount' => 60.00, 'administrationId' => 'adm'],
					['id' => 'ar-9', 'invoiceNumber' => 'VF-9', 'lifecycleState' => 'issued', 'grossAmount' => 1210.00, 'administrationId' => 'other'],
				],
				'APTransaction' => [
					['id' => 'ap-1', 'invoiceNumber' => 'INK-7', 'state' => 'issued', 'totalAmount' => 300.00, 'administrationId' => 'adm'],
				],
			]
		);
		$settings = $this->createConfiguredMock(SettingsService::class, ['getRegisterSlug' => 'shillinq']);

		return new ExactMatchBooker(objectService: $store, matches: $this->matches, settings: $settings, logger: $this->logger, plans: $plans);

	}//end booker()

	/**
	 * A bank line in administration `adm`.
	 *
	 * @param float  $amount     The signed amount.
	 * @param string $remittance The remittance text.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(float $amount, string $remittance): array {
		return ['id' => 'line-1', 'amount' => $amount, 'remittanceInfo' => $remittance, 'administrationId' => 'adm'];

	}//end line()

	/**
	 * Expect one matchInvoices call and answer it.
	 *
	 * @param string $targetId The invoice id expected.
	 * @param string $reason   The reason expected.
	 *
	 * @return void
	 */
	private function expectMatch(string $targetId, string $reason): void {
		$this->matches->expects(self::once())->method('matchInvoices')
			->with(self::anything(), [$targetId], ExactMatchBooker::ACTOR, $reason)
			->willReturn(['id' => 'match-1']);

	}//end expectMatch()

	/**
	 * A zero line is never booked.
	 *
	 * @return void
	 */
	public function testAZeroLineIsNotBooked(): void {
		$this->matches->expects(self::never())->method('matchInvoices');

		self::assertNull($this->booker()->book(self::line(0.004, 'VF-1')));

	}//end testAZeroLineIsNotBooked()

	/**
	 * An incoming line with an exact amount and a named invoice number is booked to that invoice.
	 *
	 * @return void
	 */
	public function testAnExactIncomingLineIsBooked(): void {
		$this->expectMatch(targetId: 'ar-1', reason: 'Booked on arrival by system:bankfeed: exact amount EUR 1,210.00 and reference VF-1');

		self::assertSame(['id' => 'match-1'], $this->booker()->book(self::line(1210.0, 'Betaling vf-1')));

	}//end testAnExactIncomingLineIsBooked()

	/**
	 * The amount due wins over the gross amount, and the payment reference counts, also in the end-to-end reference.
	 *
	 * @return void
	 */
	public function testAmountDueAndPaymentReferenceCount(): void {
		$this->expectMatch(targetId: 'ar-2', reason: 'Booked on arrival by system:bankfeed: exact amount EUR 50.00 and reference RF-22');

		$line = self::line(50.0, '');
		$line['endToEndRef'] = 'rf-22';
		self::assertSame(['id' => 'match-1'], $this->booker()->book($line));

	}//end testAmountDueAndPaymentReferenceCount()

	/**
	 * An outgoing line is matched against open purchase transactions.
	 *
	 * @return void
	 */
	public function testAnOutgoingLineIsMatchedToAPurchase(): void {
		$this->expectMatch(targetId: 'ap-1', reason: 'Booked on arrival by system:bankfeed: exact amount EUR 300.00 and reference INK-7');

		$line = self::line(-300.0, '');
		$line['reference'] = 'INK-7';
		self::assertSame(['id' => 'match-1'], $this->booker()->book($line));

	}//end testAnOutgoingLineIsMatchedToAPurchase()

	/**
	 * Paid invoices, other administrations, other amounts and unnamed references are not candidates.
	 *
	 * @return void
	 */
	public function testNonCandidatesWait(): void {
		$this->matches->expects(self::never())->method('matchInvoices');
		$booker = $this->booker();

		self::assertNull($booker->book(self::line(75.0, 'VF-3')), 'paid');
		self::assertNull($booker->book(self::line(1210.01, 'VF-1')), 'other amount');
		self::assertNull($booker->book(self::line(1210.0, 'VF-9')), 'other administration');
		self::assertNull($booker->book(self::line(1210.0, 'no reference')), 'no reference');

	}//end testNonCandidatesWait()

	/**
	 * Two fitting invoices leave the line for a person.
	 *
	 * @return void
	 */
	public function testTwoCandidatesWait(): void {
		$this->matches->expects(self::never())->method('matchInvoices');

		self::assertNull($this->booker()->book(self::line(60.0, 'VF-4 VF-5')));

	}//end testTwoCandidatesWait()

	/**
	 * A failing match write is logged and the line waits.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsLoggedAndWaits(): void {
		$this->matches->method('matchInvoices')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects(self::once())->method('warning')
			->with('ExactMatchBooker: exact match could not be booked, line waits', ['exception' => 'locked']);

		self::assertNull($this->booker()->book(self::line(1210.0, 'VF-1')));

	}//end testAFailingWriteIsLoggedAndWaits()

	/**
	 * An incoming line a payment plan books goes to that plan.
	 *
	 * @return void
	 */
	public function testAPaymentPlanTakesAnIncomingLine(): void {
		$plans = $this->createMock(PaymentPlanBankMatcher::class);
		$plans->expects(self::once())->method('book')->willReturn(['id' => 'plan-match']);
		$this->matches->expects(self::never())->method('matchInvoices');

		self::assertSame(['id' => 'plan-match'], $this->booker(plans: $plans)->book(self::line(1210.0, 'VF-1')));

	}//end testAPaymentPlanTakesAnIncomingLine()

	/**
	 * A line no plan takes falls through to invoices; an outgoing line never asks the plans.
	 *
	 * @return void
	 */
	public function testPlansAreAskedOnlyForIncomingLines(): void {
		$plans = $this->createMock(PaymentPlanBankMatcher::class);
		$plans->expects(self::once())->method('book')->willReturn(null);
		$this->matches->expects(self::exactly(2))->method('matchInvoices')->willReturn(['id' => 'match-1']);
		$booker = $this->booker(plans: $plans);

		self::assertSame(['id' => 'match-1'], $booker->book(self::line(1210.0, 'VF-1')));

		$line = self::line(-300.0, 'INK-7');
		self::assertSame(['id' => 'match-1'], $booker->book($line));

	}//end testPlansAreAskedOnlyForIncomingLines()
}//end class
