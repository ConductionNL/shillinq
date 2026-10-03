<?php

/**
 * Unit tests for DownPaymentService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use OCA\Shillinq\Service\Sales\DownPaymentRefusedException;
use OCA\Shillinq\Service\Sales\DownPaymentService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SDP-001, REQ-SDP-003, REQ-SDP-004, REQ-SDP-005: the Keuken Eiland example.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DownPaymentServiceTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Every save.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Seed Keukenstudio Van Leeuwen with a shillinq order and a draft kitchen invoice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(
			[
				'OrderPrimitive' => [
					[
						'id' => 'order-117', 'orderNumber' => 'Keuken Eiland 2026-117', 'administrationId' => 'adm-kvl',
						'orderType' => 'sales', 'totalAmount' => 18150.0,
					],
				],
				'OrderLine' => [
					['id' => 'ol-1', 'orderId' => 'order-117', 'description' => 'Keuken', 'lineAmount' => 15000.0, 'vatRate' => 21],
				],
				'ARInvoice' => [
					$this->kitchenInvoice(),
				],
			],
			$this->saved
		);

	}//end setUp()

	/**
	 * The draft final invoice for the kitchen: EUR 15,000 plus EUR 3,150 VAT.
	 *
	 * @return array<string,mixed>
	 */
	private function kitchenInvoice(): array {
		return [
			'id' => 'ar-kitchen', 'invoiceNumber' => '2026-0587', 'customerId' => '5b0c1a6e-2d4f-4c1e-9a7b-3f2e1d0c9b8a', 'administrationId' => 'adm-kvl',
			'invoiceDate' => '2026-11-02', 'dueDate' => '2026-11-16', 'periodId' => '2026-11', 'currency' => 'EUR',
			'lifecycleState' => 'draft', 'invoiceTypeCode' => '380',
			'netAmount' => 15000.0, 'vatAmount' => 3150.0, 'grossAmount' => 18150.0, 'amountDue' => 18150.0, 'lineNetTotal' => 15000.0,
			'invoiceLines' => [
				[
					'lineId' => '1', 'quantity' => 1, 'unitCode' => 'C62', 'itemName' => 'Keuken Eiland',
					'netPrice' => 15000.0, 'netAmount' => 15000.0, 'vatCategory' => 'S', 'vatRate' => 0.21,
				],
			],
			'vatBreakdown' => [
				['category' => 'S', 'rate' => 21, 'taxableAmount' => 15000.0, 'taxAmount' => 3150.0],
			],
		];

	}//end kitchenInvoice()

	/**
	 * The service over the store.
	 *
	 * @return DownPaymentService
	 */
	private function service(): DownPaymentService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new DownPaymentService($this->store, $settings, $l10n, $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * The request the dialog sends for 30 percent of order 117.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function request(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-kvl',
				'customerId' => '5b0c1a6e-2d4f-4c1e-9a7b-3f2e1d0c9b8a',
				'orderReference' => 'order-117',
				'percentage' => 30,
				'invoiceNumber' => '2026-0412',
				'invoiceDate' => '2026-06-01',
			],
			$overrides
		);

	}//end request()

	/**
	 * Validate a saved invoice against the merged register, uuid-format fields aside.
	 *
	 * @param array<string,mixed> $invoice The invoice as saved.
	 *
	 * @return void
	 */
	private static function assertValidInvoice(array $invoice): void {
		self::assertSame([], RegisterSchema::errors('ARInvoice', $invoice), 'the saved ARInvoice must validate against the merged register');

	}//end assertValidInvoice()

	/**
	 * The spec scenario: 30 percent of EUR 15,000 at 21 percent is EUR 4,500 plus EUR 945.
	 *
	 * @return void
	 */
	public function testThirtyPercentOfTheKitchenOrderIsOneLineAtTwentyOnePercent(): void {
		$invoice = $this->service()->raise($this->request());

		self::assertSame('386', $invoice['invoiceTypeCode']);
		self::assertSame('draft', $invoice['lifecycleState']);
		self::assertSame(4500.0, $invoice['netAmount']);
		self::assertSame(945.0, $invoice['vatAmount']);
		self::assertSame(5445.0, $invoice['grossAmount']);
		self::assertCount(1, $invoice['invoiceLines']);
		self::assertSame(0.21, $invoice['invoiceLines'][0]['vatRate']);
		self::assertSame('down-payment', $invoice['downPayment']['kind']);
		self::assertSame('order-117', $invoice['downPayment']['orderReference']);
		self::assertSame('Keuken Eiland 2026-117', $invoice['downPayment']['orderLabel'], 'the order is named on the invoice');
		self::assertSame(15000.0, $invoice['downPayment']['orderNetTotal'], 'the order total is stored');
		self::assertStringContainsString('Keuken Eiland 2026-117', $invoice['invoiceLines'][0]['itemName']);
		self::assertValidInvoice($invoice);

	}//end testThirtyPercentOfTheKitchenOrderIsOneLineAtTwentyOnePercent()

	/**
	 * A mixed-rate order the user enters splits the down payment in proportion.
	 *
	 * @return void
	 */
	public function testAMixedRateOrderIsSplitInProportion(): void {
		$invoice = $this->service()->raise(
			$this->request(
				[
					'orderReference' => 'pipelinq-quote-88',
					'orderLabel' => 'Offerte 88',
					'percentage' => null,
					'amount' => 1000,
					'orderVatBreakdown' => [['rate' => 0.21, 'net' => 3000], ['rate' => 0.09, 'net' => 1000]],
				]
			)
		);

		$byRate = [];
		foreach ($invoice['invoiceLines'] as $line) {
			$byRate[(string)$line['vatRate']] = $line['netAmount'];
		}

		self::assertSame(['0.21' => 750.0, '0.09' => 250.0], $byRate);
		self::assertSame(180.0, $invoice['vatAmount'], '157.50 + 22.50');
		self::assertSame(1180.0, $invoice['grossAmount']);
		self::assertSame([21.0, 9.0], array_column($invoice['vatBreakdown'], 'rate'), 'BG-23 in percent');
		self::assertValidInvoice($invoice);

	}//end testAMixedRateOrderIsSplitInProportion()

	/**
	 * An order shillinq cannot read, without totals, is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownOrderWithoutTotalsIsRefused(): void {
		$this->expectException(DownPaymentRefusedException::class);
		$this->expectExceptionMessage('Enter the order');
		$this->service()->raise($this->request(['orderReference' => 'pipelinq-quote-99']));

	}//end testAnUnknownOrderWithoutTotalsIsRefused()

	/**
	 * A down payment above the order total is refused.
	 *
	 * @return void
	 */
	public function testADownPaymentAboveTheOrderIsRefused(): void {
		$this->expectException(DownPaymentRefusedException::class);
		$this->service()->raise($this->request(['percentage' => 120]));

	}//end testADownPaymentAboveTheOrderIsRefused()

	/**
	 * Raise and issue the down payment, as the lifecycle would.
	 *
	 * @param string $state The state to leave it in.
	 *
	 * @return array<string,mixed>
	 */
	private function issuedDownPayment(string $state = 'paid'): array {
		$invoice = $this->service()->raise($this->request());
		$this->store->setSchema('ARInvoice')->patchObject($invoice['id'], ['lifecycleState' => $state]);
		return array_merge($invoice, ['lifecycleState' => $state]);

	}//end issuedDownPayment()

	/**
	 * The spec scenario: the kitchen invoice deducts EUR 4,500 and EUR 945 and asks EUR 12,705.
	 *
	 * @return void
	 */
	public function testTheKitchenInvoiceDeductsTheDownPayment(): void {
		$downPayment = $this->issuedDownPayment();

		$final = $this->service()->deductOnto($this->kitchenInvoice(), 'order-117');

		$deduction = array_values(array_filter($final['invoiceLines'], static fn (array $line): bool => ($line['downPaymentInvoiceId'] ?? '') !== ''));
		self::assertCount(1, $deduction);
		self::assertSame(-4500.0, $deduction[0]['netAmount']);
		self::assertSame($downPayment['id'], $deduction[0]['downPaymentInvoiceId']);
		self::assertSame(10500.0, $final['netAmount']);
		self::assertSame(2205.0, $final['vatAmount']);
		self::assertSame(12705.0, $final['grossAmount']);
		self::assertSame(12705.0, $final['amountDue']);
		self::assertSame('final', $final['downPayment']['kind']);
		self::assertSame(
			[['invoiceId' => $downPayment['id'], 'invoiceNumber' => '2026-0412', 'rate' => 0.21, 'net' => 4500.0, 'vat' => 945.0]],
			$final['downPayment']['deductions']
		);
		self::assertSame('2026-0412', $final['precedingInvoiceReferences'][0]['reference']);
		self::assertSame(
			[['category' => 'S', 'rate' => 21, 'taxableAmount' => 10500.0, 'taxAmount' => 2205.0]],
			$final['vatBreakdown'],
			'BG-23 in percent, as ArInvoiceUblMapper renders it'
		);
		self::assertValidInvoice($final);

		$stored = $this->store->setSchema('ARInvoice')->find('ar-kitchen')->getObject();
		self::assertSame(12705.0, $stored['grossAmount'], 'the deduction is saved on the draft');

	}//end testTheKitchenInvoiceDeductsTheDownPayment()

	/**
	 * Deducting twice onto the same draft does not take the down payment off twice.
	 *
	 * @return void
	 */
	public function testDeductingAgainOntoTheSameDraftChangesNothing(): void {
		$this->issuedDownPayment();
		$service = $this->service();
		$once = $service->deductOnto($this->kitchenInvoice(), 'order-117');

		$this->expectException(DownPaymentRefusedException::class);
		$service->deductOnto($once, 'order-117');

	}//end testDeductingAgainOntoTheSameDraftChangesNothing()

	/**
	 * A draft down payment is not deducted: only issued ones are.
	 *
	 * @return void
	 */
	public function testADraftDownPaymentIsNotOffered(): void {
		$this->service()->raise($this->request());

		self::assertSame([], $this->service()->openDownPayments($this->kitchenInvoice()));

	}//end testADraftDownPaymentIsNotOffered()

	/**
	 * The check on issue refuses a down payment already deducted elsewhere, naming that invoice.
	 *
	 * @return void
	 */
	public function testADownPaymentDeductedElsewhereIsRefusedOnIssue(): void {
		$downPayment = $this->issuedDownPayment();
		$final = $this->service()->deductOnto($this->kitchenInvoice(), 'order-117');
		$this->service()->stampDeductions(array_merge($final, ['lifecycleState' => 'issued']));

		$second = array_merge($final, ['id' => 'ar-second', 'invoiceNumber' => '2026-0601']);

		try {
			$this->service()->requireOpenDeductions($second);
			self::fail('a second deduction of the same down payment must be refused');
		} catch (DownPaymentRefusedException $e) {
			self::assertStringContainsString('2026-0587', $e->getMessage());
		}

		$stamped = $this->store->setSchema('ARInvoice')->find($downPayment['id'])->getObject();
		self::assertSame('ar-kitchen', $stamped['downPayment']['deductedOnInvoiceId']);
		self::assertSame('2026-0587', $stamped['downPayment']['deductedOnInvoiceNumber']);
		self::assertSame('down-payment', $stamped['downPayment']['kind'], 'the stamp keeps the rest of the group');

		// The invoice that did deduct it passes its own check again.
		$this->service()->requireOpenDeductions($final);

	}//end testADownPaymentDeductedElsewhereIsRefusedOnIssue()

	/**
	 * The check on issue refuses deductions above the invoice total.
	 *
	 * @return void
	 */
	public function testDeductionsAboveTheInvoiceAreRefusedOnIssue(): void {
		$this->issuedDownPayment();
		$final = $this->service()->deductOnto($this->kitchenInvoice(), 'order-117');
		$final['grossAmount'] = -10.0;

		$this->expectException(DownPaymentRefusedException::class);
		$this->expectExceptionMessage('exceed');
		$this->service()->requireOpenDeductions($final);

	}//end testDeductionsAboveTheInvoiceAreRefusedOnIssue()

	/**
	 * An ordinary invoice passes the check without a read.
	 *
	 * @return void
	 */
	public function testAnOrdinaryInvoicePassesTheCheck(): void {
		$this->service()->requireOpenDeductions($this->kitchenInvoice());
		$this->service()->stampDeductions($this->kitchenInvoice());
		self::assertSame([], $this->saved);

	}//end testAnOrdinaryInvoicePassesTheCheck()

	/**
	 * The position lists every down payment of the order with its state and deducting invoice.
	 *
	 * @return void
	 */
	public function testThePositionListsEveryDownPaymentOfTheOrder(): void {
		$first = $this->issuedDownPayment();
		$final = $this->service()->deductOnto($this->kitchenInvoice(), 'order-117');
		$this->service()->stampDeductions($final);
		$second = $this->service()->raise($this->request(['invoiceNumber' => '2026-0450', 'percentage' => 10]));
		$this->store->setSchema('ARInvoice')->patchObject($second['id'], ['lifecycleState' => 'issued']);

		$position = $this->service()->position($first);

		self::assertSame(['2026-0412', '2026-0450'], array_column($position, 'invoiceNumber'));
		self::assertSame([true, false], array_column($position, 'paid'));
		self::assertSame(['2026-0587', ''], array_column($position, 'deductedOnInvoiceNumber'));

	}//end testThePositionListsEveryDownPaymentOfTheOrder()
}//end class
