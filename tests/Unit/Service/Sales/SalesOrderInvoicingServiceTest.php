<?php

/**
 * Unit tests for SalesOrderInvoicingService.
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
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Sales\SalesOrderInvoicingService;
use OCA\Shillinq\Service\Sales\SalesOrderRefusedException;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-QTI-001, REQ-QTI-002, REQ-QTI-003: Bakkerij De Korenaar's oven trays.
 *
 * @covers \OCA\Shillinq\Service\Sales\SalesOrderInvoicingService
 * @covers \OCA\Shillinq\Service\Sales\SalesOrderRefusedException
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Service\SettingsService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SalesOrderInvoicingServiceTest extends TestCase {
	/**
	 * The accepted quote's uuid.
	 *
	 * @var string
	 */
	private const QUOTE_ID = '0b6f1c2e-3a4d-4e5f-8a9b-0c1d2e3f4a31';

	/**
	 * The confirmed sales order's uuid.
	 *
	 * @var string
	 */
	private const ORDER_ID = '7d2e9f10-1b2c-4d3e-9f4a-5b6c7d8e9f01';

	/**
	 * The customer's uuid.
	 *
	 * @var string
	 */
	private const CUSTOMER_ID = '5b0c1a6e-2d4f-4c1e-9a7b-3f2e1d0c9b8a';

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
	 * Seed the accepted quote Q-2026-031 and a confirmed sales order of oven trays.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(
			[
				'Quote' => [
					[
						'id' => self::QUOTE_ID, 'quoteNumber' => 'Q-2026-031', 'customerReference' => self::CUSTOMER_ID,
						'administrationId' => 'adm-korenaar', 'status' => 'accepted', 'currency' => 'EUR', 'paymentTerms' => 'net14',
					],
				],
				'QuoteLine' => [
					[
						'id' => 'ql-2', 'quote' => self::QUOTE_ID, 'lineNumber' => 2, 'description' => 'Installation',
						'quantity' => 1, 'unitPrice' => 300.0, 'vatRate' => 21, 'glAccount' => '8010',
					],
					[
						'id' => 'ql-1', 'quote' => self::QUOTE_ID, 'lineNumber' => 1, 'description' => 'Oven tray',
						'quantity' => 10, 'unitPrice' => 45.0, 'vatRate' => 21, 'glAccount' => '8000', 'productReference' => 'TRAY-60',
					],
				],
				'OrderPrimitive' => [
					[
						'id' => self::ORDER_ID, 'orderNumber' => 'SO-Q-2026-020', 'administrationId' => 'adm-korenaar', 'orderType' => 'sales',
						'direction' => 'incoming', 'state' => 'confirmed', 'counterpartyId' => self::CUSTOMER_ID, 'currency' => 'EUR',
					],
				],
				'OrderLine' => [
					[
						'id' => 'ol-1', 'orderId' => self::ORDER_ID, 'lineNumber' => 1, 'description' => 'Oven tray',
						'quantity' => 10, 'unitPrice' => 45.0, 'vatRate' => 21, 'invoicedQuantity' => 4, 'lineAmount' => 450.0,
					],
				],
			],
			$this->saved
		);

	}//end setUp()

	/**
	 * The service over the store.
	 *
	 * @return SalesOrderInvoicingService
	 */
	private function service(): SalesOrderInvoicingService {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new SalesOrderInvoicingService($this->store, $location, $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * The saved objects of one schema, in save order.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function savedOf(string $schema): array {
		$saves = array_filter($this->saved, static fn (array $save): bool => $save['schema'] === $schema);
		return array_values(array_map(static fn (array $save): array => $save['object'], $saves));

	}//end savedOf()

	/**
	 * One stored row, after every save and patch.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The row id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $schema, string $id): array {
		return $this->store->setSchema($schema)->find($id)->getObject();

	}//end stored()

	/**
	 * An accepted quote becomes a draft sales order with both lines at the same quantities and prices.
	 *
	 * @return void
	 */
	public function testAnAcceptedQuoteBecomesADraftSalesOrderWithItsLines(): void {
		$order = $this->service()->orderFromQuote('Q-2026-031', 'adm-korenaar');

		$this->assertSame('sales', $order['orderType']);
		$this->assertSame('draft', $order['state']);
		$this->assertSame(self::QUOTE_ID, $order['sourceQuote']);
		$this->assertSame(self::CUSTOMER_ID, $order['counterpartyId']);
		$this->assertSame('SO-Q-2026-031', $order['orderNumber']);
		$this->assertSame(750.0, $order['totalAmount']);
		$this->assertSame('net14', $order['paymentTerms']);

		$lines = $this->savedOf('OrderLine');
		$this->assertSame(
			[
				[1, 'Oven tray', 10.0, 45.0, 450.0, '8000', 'TRAY-60'],
				[2, 'Installation', 1.0, 300.0, 300.0, '8010', null],
			],
			array_map(
				static fn (array $line): array => [
					$line['lineNumber'], $line['description'], $line['quantity'], $line['unitPrice'],
					$line['lineAmount'], $line['glAccount'], $line['productReference'] ?? null,
				],
				$lines
			)
		);
		foreach ($lines as $line) {
			$this->assertSame($order['id'], $line['orderId']);
			$this->assertSame(0, $line['invoicedQuantity']);
		}

	}//end testAnAcceptedQuoteBecomesADraftSalesOrderWithItsLines()

	/**
	 * The order and its lines are what the real merged register accepts.
	 *
	 * @return void
	 */
	public function testTheOrderAndItsLinesValidateAgainstTheRegister(): void {
		$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');

		$this->assertSame([], RegisterSchema::errors('OrderPrimitive', $this->savedOf('OrderPrimitive')[0]));
		foreach ($this->savedOf('OrderLine') as $line) {
			$this->assertSame([], RegisterSchema::errors('OrderLine', $line));
		}

	}//end testTheOrderAndItsLinesValidateAgainstTheRegister()

	/**
	 * A quote line's percentage discount lowers the order line's amount.
	 *
	 * @return void
	 */
	public function testALineDiscountLowersTheLineAmount(): void {
		$this->store->saveObject(
			[
				'id' => 'ql-3', 'quote' => self::QUOTE_ID, 'lineNumber' => 3, 'description' => 'Baking mat',
				'quantity' => 3, 'unitPrice' => 19.99, 'discount' => 10, 'vatRate' => 9,
			],
			[],
			null,
			'QuoteLine'
		);

		$order = $this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');

		$this->assertSame(53.97, $this->savedOf('OrderLine')[2]['lineAmount']);
		$this->assertSame(803.97, $order['totalAmount']);

	}//end testALineDiscountLowersTheLineAmount()

	/**
	 * A quote that is still being negotiated is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAQuoteThatIsNotAcceptedIsRefused(): void {
		$this->store->saveObject(array_merge($this->stored('Quote', self::QUOTE_ID), ['status' => 'sent']), [], null, 'Quote');
		$this->saved = [];

		try {
			$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');
			$this->fail('A sent quote must be refused.');
		} catch (SalesOrderRefusedException $e) {
			$this->assertSame('Quote %1$s must be accepted before it becomes a sales order.', $e->getTemplate());
			$this->assertSame(['Q-2026-031'], $e->getParameters());
			$this->assertSame('Quote Q-2026-031 must be accepted before it becomes a sales order.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);

	}//end testAQuoteThatIsNotAcceptedIsRefused()

	/**
	 * A quote that already has a sales order gets no second one.
	 *
	 * @return void
	 */
	public function testAQuoteWithASalesOrderIsRefused(): void {
		$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');
		$this->saved = [];

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Quote Q-2026-031 already has sales order SO-Q-2026-031.');
		try {
			$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');
		} finally {
			$this->assertSame([], $this->saved);
		}

	}//end testAQuoteWithASalesOrderIsRefused()

	/**
	 * A quote without lines is refused.
	 *
	 * @return void
	 */
	public function testAQuoteWithoutLinesIsRefused(): void {
		$this->store = new InMemoryObjectServiceStub(['Quote' => [$this->stored('Quote', self::QUOTE_ID)]], $this->saved);

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Quote Q-2026-031 has no lines to order.');
		$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-korenaar');

	}//end testAQuoteWithoutLinesIsRefused()

	/**
	 * A quote of another administration does not exist for this caller.
	 *
	 * @return void
	 */
	public function testAQuoteOfAnotherAdministrationIsNotFound(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->service()->orderFromQuote(self::QUOTE_ID, 'adm-other');

	}//end testAQuoteOfAnotherAdministrationIsNotFound()

	/**
	 * An unknown quote is not found.
	 *
	 * @return void
	 */
	public function testAnUnknownQuoteIsNotFound(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->service()->findQuote('Q-0000-000');

	}//end testAnUnknownQuoteIsNotFound()

	/**
	 * Of 10 oven trays with 4 invoiced, the draft invoice takes the other 6 at the order price, and the order is invoiced.
	 *
	 * @return void
	 */
	public function testAConfirmedOrderIsInvoicedForWhatIsLeft(): void {
		$invoice = $this->service()->invoiceFromOrder('SO-Q-2026-020', 'adm-korenaar');

		$this->assertSame('draft', $invoice['lifecycleState']);
		$this->assertSame(self::ORDER_ID, $invoice['salesOrder']);
		$this->assertSame(self::CUSTOMER_ID, $invoice['customerId']);
		$this->assertSame('DRAFT-SO-Q-2026-020-1', $invoice['invoiceNumber']);
		$this->assertCount(1, $invoice['invoiceLines']);
		$line = $invoice['invoiceLines'][0];
		$this->assertSame(6.0, $line['quantity']);
		$this->assertSame(45.0, $line['netPrice']);
		$this->assertSame(270.0, $line['netAmount']);
		$this->assertSame(0.21, $line['vatRate']);
		$this->assertSame('S', $line['vatCategory']);
		$this->assertSame(270.0, $invoice['netAmount']);
		$this->assertSame(56.7, $invoice['vatAmount']);
		$this->assertSame(326.7, $invoice['grossAmount']);

		$this->assertSame('invoiced', $invoice['orderState']);
		$this->assertSame('invoiced', $this->stored('OrderPrimitive', self::ORDER_ID)['state']);
		$this->assertSame(10.0, $this->stored('OrderLine', 'ol-1')['invoicedQuantity']);

	}//end testAConfirmedOrderIsInvoicedForWhatIsLeft()

	/**
	 * The draft invoice is what the real merged register accepts.
	 *
	 * @return void
	 */
	public function testTheInvoiceValidatesAgainstTheRegister(): void {
		$invoice = $this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

		$this->assertSame([], RegisterSchema::errors('ARInvoice', $this->savedOf('ARInvoice')[0]));
		$this->assertArrayNotHasKey('orderState', $this->savedOf('ARInvoice')[0]);
		$this->assertSame('DRAFT-SO-Q-2026-020-1', $invoice['invoiceNumber']);

	}//end testTheInvoiceValidatesAgainstTheRegister()

	/**
	 * A second invoice takes only what the first left, and is numbered after it.
	 *
	 * @return void
	 */
	public function testASecondInvoiceTakesOnlyTheRest(): void {
		$this->store->saveObject(
			[
				'id' => 'ol-2', 'orderId' => self::ORDER_ID, 'lineNumber' => 2, 'description' => 'Installation',
				'quantity' => 1, 'unitPrice' => 300.0, 'vatRate' => 21,
			],
			[],
			null,
			'OrderLine'
		);
		$first = $this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');
		$this->assertSame([6.0, 1.0], array_column($first['invoiceLines'], 'quantity'));

		// A line added later is all that is left; the order was invoiced, so it is reopened as partly invoiced.
		$this->store->saveObject(
			[
				'id' => 'ol-3', 'orderId' => self::ORDER_ID, 'lineNumber' => 3, 'description' => 'Delivery',
				'quantity' => 1, 'unitPrice' => 25.0, 'vatRate' => 21,
			],
			[],
			null,
			'OrderLine'
		);
		$this->store->patchObject(self::ORDER_ID, ['state' => 'partly-invoiced'], null, 'OrderPrimitive');

		$second = $this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

		$this->assertSame(['Delivery'], array_column($second['invoiceLines'], 'itemName'));
		$this->assertSame('DRAFT-SO-Q-2026-020-2', $second['invoiceNumber']);
		$this->assertSame('invoiced', $second['orderState']);

	}//end testASecondInvoiceTakesOnlyTheRest()

	/**
	 * Lines left out of the invoice keep the order partly invoiced.
	 *
	 * @return void
	 */
	public function testAnOrderWithALineWithoutQuantityStaysOpenOnlyForRealRemainders(): void {
		$this->store->saveObject(
			['id' => 'ol-0', 'orderId' => self::ORDER_ID, 'lineNumber' => 0, 'description' => 'Note', 'quantity' => null],
			[],
			null,
			'OrderLine'
		);

		$invoice = $this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

		$this->assertSame(['Oven tray'], array_column($invoice['invoiceLines'], 'itemName'));
		$this->assertSame('invoiced', $invoice['orderState']);

	}//end testAnOrderWithALineWithoutQuantityStaysOpenOnlyForRealRemainders()

	/**
	 * A fully invoiced order is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnInvoicedOrderIsRefused(): void {
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');
		$this->saved = [];

		try {
			$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');
			$this->fail('An invoiced order must be refused.');
		} catch (SalesOrderRefusedException $e) {
			$this->assertSame('Every line of order SO-Q-2026-020 is already invoiced.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);

	}//end testAnInvoicedOrderIsRefused()

	/**
	 * A confirmed order whose lines are all invoiced is refused with the same words.
	 *
	 * @return void
	 */
	public function testAConfirmedOrderWithNothingLeftIsRefused(): void {
		$this->store->patchObject('ol-1', ['invoicedQuantity' => 10], null, 'OrderLine');

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Every line of order SO-Q-2026-020 is already invoiced.');
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

	}//end testAConfirmedOrderWithNothingLeftIsRefused()

	/**
	 * A draft sales order must be confirmed first.
	 *
	 * @return void
	 */
	public function testADraftOrderIsRefused(): void {
		$this->store->patchObject(self::ORDER_ID, ['state' => 'draft'], null, 'OrderPrimitive');

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Confirm order SO-Q-2026-020 before you invoice it.');
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

	}//end testADraftOrderIsRefused()

	/**
	 * A purchase order is not invoiced here.
	 *
	 * @return void
	 */
	public function testAPurchaseOrderIsRefused(): void {
		$this->store->patchObject(self::ORDER_ID, ['orderType' => 'purchase'], null, 'OrderPrimitive');

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Order SO-Q-2026-020 is not a sales order.');
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

	}//end testAPurchaseOrderIsRefused()

	/**
	 * An order without a customer cannot be invoiced.
	 *
	 * @return void
	 */
	public function testAnOrderWithoutACustomerIsRefused(): void {
		$this->store->patchObject(self::ORDER_ID, ['counterpartyId' => ''], null, 'OrderPrimitive');

		$this->expectException(SalesOrderRefusedException::class);
		$this->expectExceptionMessage('Order SO-Q-2026-020 has no customer to invoice.');
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-korenaar');

	}//end testAnOrderWithoutACustomerIsRefused()

	/**
	 * An order of another administration does not exist for this caller.
	 *
	 * @return void
	 */
	public function testAnOrderOfAnotherAdministrationIsNotFound(): void {
		$this->expectException(OutOfBoundsException::class);
		$this->service()->invoiceFromOrder(self::ORDER_ID, 'adm-other');

	}//end testAnOrderOfAnotherAdministrationIsNotFound()
}//end class
