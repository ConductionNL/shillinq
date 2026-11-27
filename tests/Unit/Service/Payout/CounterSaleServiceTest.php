<?php

/**
 * Unit tests for CounterSaleService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Payout
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Payout;

use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\Payout\CounterSaleService;
use OCA\Shillinq\Service\Payout\PointOfSalePaymentService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RPPO-006: a counter sale is a draft simplified invoice paid on the phone, cancelled when abandoned.
 *
 * @covers \OCA\Shillinq\Service\Payout\CounterSaleService
 * @uses   \OCA\Shillinq\Kernel\Exception\FinancialDocumentActionRefusedException
 * @uses   \OCA\Shillinq\Service\Payout\CounterSaleSettlement
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
final class CounterSaleServiceTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The guard.
	 *
	 * @var FinancialDocumentGuard&MockObject
	 */
	private FinancialDocumentGuard $guard;

	/**
	 * The point-of-sale payment.
	 *
	 * @var PointOfSalePaymentService&MockObject
	 */
	private PointOfSalePaymentService $payments;

	/**
	 * The transition runner.
	 *
	 * @var ObjectTransitionRunnerInterface&MockObject
	 */
	private ObjectTransitionRunnerInterface $transitions;

	/**
	 * The tea tin of the scenario: EUR 12.50 at 21 percent VAT.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const TEA_TIN = [['description' => 'Theeblik groot', 'quantity' => 1, 'unitPrice' => 12.5, 'vatRate' => 21]];

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->guard = $this->createMock(FinancialDocumentGuard::class);
		$this->guard->method('callerId')->willReturn('anna');
		$this->payments = $this->createMock(PointOfSalePaymentService::class);
		$this->transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
	}//end setUp()

	/**
	 * The service over a store holding these rows.
	 *
	 * @param array<string, list<array<string, mixed>>> $data Rows by schema.
	 *
	 * @return CounterSaleService
	 */
	private function service(array $data=[]): CounterSaleService {
		$this->saved = [];
		return new CounterSaleService(
			objectService: new InMemoryObjectServiceStub(data: $data, saveSink: $this->saved),
			registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			guard: $this->guard,
			payments: $this->payments,
			transitions: $this->transitions,
		);
	}//end service()

	/**
	 * The rows saved to one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedTo(string $schema): array {
		return array_values(array_map(
			static fn (array $write): array => $write['object'],
			array_filter($this->saved, static fn (array $write): bool => $write['schema'] === $schema)
		));
	}//end savedTo()

	/**
	 * The tea tin sale: a draft simplified invoice of EUR 12.50 on a new counter-sales debtor, paid on the phone.
	 *
	 * @return void
	 */
	public function testTheTeaTinBecomesADraftSimplifiedInvoicePaidOnThePhone(): void {
		$this->guard->expects($this->once())->method('assertMay')->with('payment.request');
		$this->guard->expects($this->once())->method('assertAdministration')->with('THV');
		$this->payments->expects($this->once())->method('payInvoice')
			->with(
				$this->callback(static fn (array $invoice): bool => $invoice['lifecycleState'] === 'draft' && $invoice['grossAmount'] === 12.5
					&& str_starts_with((string)$invoice['id'], 'obj-')),
				'anna'
			)
			->willReturn(['mode' => 'device', 'request' => ['id' => 'pr-1'], 'checkoutUrl' => '', 'device' => ['deviceId' => 'term_thv']]);
		$this->transitions->expects($this->never())->method('run');

		$sale = $this->service()->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: null, today: '2026-11-17');

		$debtor = $this->savedTo(schema: 'CustomerMaster');
		$this->assertCount(1, $debtor);
		$this->assertSame('COUNTER-SALES', $debtor[0]['customerId']);
		$this->assertSame('THV', $debtor[0]['administrationId']);

		$invoice = $this->savedTo(schema: 'ARInvoice')[0];
		$this->assertSame('draft', $invoice['lifecycleState']);
		$this->assertTrue($invoice['simplifiedInvoice']);
		$this->assertTrue($invoice['counterSale']);
		$this->assertSame($debtor[0]['id'], $invoice['customerId']);
		$this->assertSame(12.5, $invoice['grossAmount']);
		$this->assertSame(10.33, $invoice['netAmount']);
		$this->assertSame(2.17, $invoice['vatAmount']);
		$this->assertSame('2026-11-17', $invoice['invoiceDate']);
		$this->assertMatchesRegularExpression('/^CS-20261117-[0-9A-F]{8}$/', $invoice['invoiceNumber']);
		$this->assertSame(
			[[
				'lineId' => '1', 'quantity' => 1.0, 'unitCode' => 'C62', 'itemName' => 'Theeblik groot',
				'netPrice' => 10.33, 'netAmount' => 10.33, 'vatCategory' => 'S', 'vatRate' => 21.0,
			]],
			$invoice['invoiceLines']
		);

		$this->assertSame('device', $sale['mode']);
		$this->assertSame(['id' => 'pr-1'], $sale['request']);
		$this->assertSame($invoice['id'], $sale['invoice']['id']);
	}//end testTheTeaTinBecomesADraftSimplifiedInvoicePaidOnThePhone()

	/**
	 * A second sale reuses the administration's counter-sales debtor; another administration's is not used.
	 *
	 * @return void
	 */
	public function testTheCounterSalesDebtorIsReusedPerAdministration(): void {
		$this->payments->method('payInvoice')
			->willReturn(['mode' => 'qr', 'request' => ['id' => 'pr-2'], 'checkoutUrl' => 'https://pay.example/2', 'device' => []]);
		$service = $this->service(data: ['CustomerMaster' => [
			['id' => 'cm-other', 'customerId' => 'COUNTER-SALES', 'administrationId' => 'OTHER'],
			['id' => 'cm-thv', 'customerId' => 'COUNTER-SALES', 'administrationId' => 'THV'],
		]]);

		$service->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: null, today: '2026-11-17');

		$this->assertSame([], $this->savedTo(schema: 'CustomerMaster'));
		$this->assertSame('cm-thv', $this->savedTo(schema: 'ARInvoice')[0]['customerId']);
	}//end testTheCounterSalesDebtorIsReusedPerAdministration()

	/**
	 * A named customer must be one of the selling administration.
	 *
	 * @return void
	 */
	public function testANamedCustomerOfAnotherAdministrationIsRefused(): void {
		$this->payments->expects($this->never())->method('payInvoice');
		$service = $this->service(data: ['CustomerMaster' => [['id' => 'cm-9', 'customerId' => 'C-9', 'administrationId' => 'OTHER']]]);

		try {
			$service->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: 'cm-9', today: '2026-11-17');
			$this->fail('A customer of another administration was billed.');
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->assertSame(400, $e->getStatus());
			$this->assertSame('This customer is not one of this administration.', $e->getMessage());
		}

		$this->assertSame([], $this->savedTo(schema: 'ARInvoice'));
	}//end testANamedCustomerOfAnotherAdministrationIsRefused()

	/**
	 * A named customer of the administration is billed.
	 *
	 * @return void
	 */
	public function testANamedCustomerIsBilled(): void {
		$this->payments->method('payInvoice')->willReturn(['mode' => 'device', 'request' => ['id' => 'pr-3'], 'checkoutUrl' => '', 'device' => []]);
		$service = $this->service(data: ['CustomerMaster' => [['id' => 'cm-7', 'customerId' => 'C-7', 'administrationId' => 'THV']]]);

		$service->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: 'cm-7', today: '2026-11-17');

		$this->assertSame('cm-7', $this->savedTo(schema: 'ARInvoice')[0]['customerId']);
		$this->assertSame([], $this->savedTo(schema: 'CustomerMaster'));
	}//end testANamedCustomerIsBilled()

	/**
	 * When the payment cannot start, the draft is cancelled and the refusal passed on.
	 *
	 * @return void
	 */
	public function testAPaymentThatCannotStartCancelsTheDraft(): void {
		$this->payments->method('payInvoice')
			->willThrowException(new FinancialDocumentActionRefusedException(message: 'Register your phone first.', status: 409));
		$this->transitions->expects($this->once())->method('run')->with('obj-2', 'cancel');

		$this->expectException(FinancialDocumentActionRefusedException::class);
		$this->expectExceptionMessage('Register your phone first.');

		$this->service()->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: null, today: '2026-11-17');
	}//end testAPaymentThatCannotStartCancelsTheDraft()

	/**
	 * Lines: totals over several lines and quantities, zero-rated lines, and malformed lines refused.
	 *
	 * @return void
	 */
	public function testLinesAreTotalledAndMalformedOnesRefused(): void {
		$lines = CounterSaleService::invoiceLines(lines: [
			['description' => 'Thee los 100 g', 'quantity' => 3, 'unitPrice' => 4.95, 'vatRate' => 9],
			['description' => 'Statiegeld', 'unitPrice' => 0.15, 'vatRate' => 0],
		]);
		$draft = CounterSaleService::draft(lines: $lines, customerId: 'cm-1', administrationId: 'THV', today: '2026-11-17');

		$this->assertSame(15.0, $draft['grossAmount']);
		$this->assertSame(1.23, $draft['vatAmount']);
		$this->assertSame(13.77, $draft['netAmount']);
		$this->assertSame('Z', $draft['invoiceLines'][1]['vatCategory']);
		$this->assertSame(1.0, $draft['invoiceLines'][1]['quantity']);

		foreach ([[], [['description' => '', 'unitPrice' => 1, 'vatRate' => 21]], [['description' => 'x', 'unitPrice' => 1, 'vatRate' => 121]],
			[['description' => 'x', 'quantity' => 0, 'unitPrice' => 1, 'vatRate' => 21]], [['description' => 'x', 'vatRate' => 21]], ['not a line'],
		] as $bad) {
			try {
				CounterSaleService::invoiceLines(lines: $bad);
				$this->fail('A malformed sale was accepted: ' . json_encode($bad));
			} catch (FinancialDocumentActionRefusedException $e) {
				$this->assertSame(400, $e->getStatus());
			}
		}
	}//end testLinesAreTotalledAndMalformedOnesRefused()

	/**
	 * A caller without the payment action is refused before anything is written.
	 *
	 * @return void
	 */
	public function testACallerWithoutThePaymentActionWritesNothing(): void {
		$this->guard->method('assertMay')
			->willThrowException(new FinancialDocumentActionRefusedException(message: 'This needs the payment.request action.', status: 403));
		$this->payments->expects($this->never())->method('payInvoice');

		try {
			$this->service()->start(administrationId: 'THV', lines: self::TEA_TIN, customerId: null, today: '2026-11-17');
			$this->fail('The sale was recorded without the payment action.');
		} catch (FinancialDocumentActionRefusedException $e) {
			$this->assertSame(403, $e->getStatus());
		}

		$this->assertSame([], $this->saved);
	}//end testACallerWithoutThePaymentActionWritesNothing()

	/**
	 * The customer walks away: the draft sale is cancelled.
	 *
	 * @return void
	 */
	public function testAnAbandonedSaleIsCancelled(): void {
		$this->guard->method('readableInvoice')
			->willReturn(['id' => 'inv-cs-1', 'lifecycleState' => 'draft', 'counterSale' => true, 'administrationId' => 'THV']);
		$this->guard->expects($this->once())->method('assertAdministration')->with('THV');
		$this->transitions->expects($this->once())->method('run')->with('inv-cs-1', 'cancel');

		$invoice = $this->service()->abandon(invoiceId: 'inv-cs-1');

		$this->assertSame('cancelled', $invoice['lifecycleState']);
	}//end testAnAbandonedSaleIsCancelled()

	/**
	 * A sale already issued (its payment was captured), or an ordinary invoice, is not cancelled.
	 *
	 * @return void
	 */
	public function testOnlyADraftSaleCanBeAbandoned(): void {
		$this->guard->method('readableInvoice')->willReturnOnConsecutiveCalls(
			['id' => 'inv-cs-1', 'lifecycleState' => 'issued', 'counterSale' => true, 'administrationId' => 'THV'],
			['id' => 'inv-0601', 'lifecycleState' => 'draft', 'administrationId' => 'THV']
		);
		$this->transitions->expects($this->never())->method('run');
		$service = $this->service();

		foreach (['inv-cs-1', 'inv-0601'] as $id) {
			try {
				$service->abandon(invoiceId: $id);
				$this->fail('Invoice ' . $id . ' was cancelled.');
			} catch (FinancialDocumentActionRefusedException $e) {
				$this->assertSame(409, $e->getStatus());
			}
		}
	}//end testOnlyADraftSaleCanBeAbandoned()
}//end class
