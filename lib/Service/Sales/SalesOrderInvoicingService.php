<?php

/**
 * Shillinq SalesOrderInvoicingService
 *
 * Turns an accepted quote into a sales order and a confirmed sales order into
 * a draft sales invoice, without retyping a line (sales-quote-and-order-to-invoice
 * REQ-QTI-001, REQ-QTI-002, REQ-QTI-003). A sales order is an OrderPrimitive
 * with orderType `sales`, so the Orders page and DownPaymentService read it
 * like any other order; the invoice is a draft ARInvoice that names the order
 * in `salesOrder`.
 *
 * Each order line records how much of its quantity an invoice already took
 * (`invoicedQuantity`), so a second invoice takes only what is left. Amounts
 * are computed in whole cents.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales
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

namespace OCA\Shillinq\Service\Sales;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;

/**
 * Quote to sales order, sales order to draft invoice.
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 */
class SalesOrderInvoicingService {
	/**
	 * The orderType of a sales order.
	 *
	 * @var string
	 */
	public const ORDER_TYPE = 'sales';

	/**
	 * Sales order states an invoice may be written from.
	 *
	 * @var list<string>
	 */
	private const INVOICEABLE_STATES = ['confirmed', 'partly-invoiced'];

	/**
	 * UNTDID 1001 code of a commercial invoice.
	 *
	 * @var string
	 */
	private const TYPE_CODE = '380';

	/**
	 * Days between the invoice date and the due date of the draft.
	 *
	 * @var int
	 */
	private const TERM_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object surface.
	 * @param RegisterLocation       $registerLocation Where the register lives.
	 * @param LoggerInterface        $logger           Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read a quote by uuid or quote number.
	 *
	 * @param string $quoteId The uuid or number.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When there is no such quote.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-an-accepted-quote-becomes-a-sales-order-req-qti-001
	 */
	public function findQuote(string $quoteId): array {
		return $this->findRecord(schema: 'Quote', id: $quoteId, fallbackProperty: 'quoteNumber');

	}//end findQuote()

	/**
	 * Read an order by uuid or order number.
	 *
	 * @param string $orderId The uuid or number.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When there is no such order.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-a-confirmed-sales-order-becomes-a-draft-invoice-req-qti-003
	 */
	public function findOrder(string $orderId): array {
		return $this->findRecord(schema: 'OrderPrimitive', id: $orderId, fallbackProperty: 'orderNumber');

	}//end findOrder()

	/**
	 * Write a draft sales order with every line of an accepted quote (REQ-QTI-001).
	 *
	 * @param string $quoteId          The quote uuid or number.
	 * @param string $administrationId The administration the quote must belong to.
	 *
	 * @return array<string,mixed> The saved order, with its `lines`.
	 *
	 * @throws OutOfBoundsException       When there is no such quote in the administration.
	 * @throws SalesOrderRefusedException When the quote is not accepted, already ordered or has no lines.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-an-accepted-quote-becomes-a-sales-order-req-qti-001
	 */
	public function orderFromQuote(string $quoteId, string $administrationId): array {
		$quote = $this->inAdministration(record: $this->findQuote(quoteId: $quoteId), administrationId: $administrationId);
		$quoteNumber = (string)($quote['quoteNumber'] ?? $quote['id']);
		if ((string)($quote['status'] ?? '') !== 'accepted') {
			throw new SalesOrderRefusedException(template: 'Quote %1$s must be accepted before it becomes a sales order.', parameters: [$quoteNumber]);
		}

		$existing = $this->scoped(schema: 'OrderPrimitive')->findAll(['filters' => ['sourceQuote' => (string)$quote['id']], 'limit' => 1]);
		if ($existing !== []) {
			$order = (ObjectIdentifier::recordWithId(candidate: $existing[0]) ?? []);
			throw new SalesOrderRefusedException(
				template: 'Quote %1$s already has sales order %2$s.',
				parameters: [$quoteNumber, (string)($order['orderNumber'] ?? $order['id'] ?? '')]
			);
		}

		$quoteLines = $this->records(schema: 'QuoteLine', filters: ['quote' => (string)$quote['id']], sortBy: 'lineNumber');
		if ($quoteLines === []) {
			throw new SalesOrderRefusedException(template: 'Quote %1$s has no lines to order.', parameters: [$quoteNumber]);
		}

		$lines = array_map(static fn (array $line): array => self::orderLine(quoteLine: $line), $quoteLines);
		$order = [
			'administrationId' => $administrationId,
			'orderType' => self::ORDER_TYPE,
			'direction' => 'incoming',
			'orderNumber' => 'SO-'.$quoteNumber,
			'counterpartyId' => (string)($quote['customerReference'] ?? ''),
			'currency' => (string)($quote['currency'] ?? 'EUR'),
			'orderDate' => gmdate('Y-m-d\TH:i:s\Z'),
			'totalAmount' => self::euros(cents: array_sum(array_map(static fn (array $line): int => self::cents(amount: $line['lineAmount']), $lines))),
			'paymentTerms' => self::textOrNull(value: $quote['paymentTerms'] ?? null),
			'state' => 'draft',
			'sourceQuote' => (string)$quote['id'],
		];

		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'OrderPrimitive')->saveObject($order));
		$order['id'] = (string)($saved['id'] ?? '');

		$order['lines'] = [];
		foreach ($lines as $line) {
			$line['orderId'] = $order['id'];
			$savedLine = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'OrderLine')->saveObject($line));
			$line['id'] = (string)($savedLine['id'] ?? '');
			$order['lines'][] = $line;
		}

		$this->logger->info(
			'SalesOrderInvoicingService: sales order made from quote',
			['orderId' => $order['id'], 'quoteId' => $quote['id'], 'lines' => count($lines)]
		);

		return $order;

	}//end orderFromQuote()

	/**
	 * Write a draft sales invoice for what a sales order has not invoiced yet (REQ-QTI-003).
	 *
	 * @param string $orderId          The order uuid or number.
	 * @param string $administrationId The administration the order must belong to.
	 *
	 * @return array<string,mixed> The saved draft invoice, with the order's new `orderState`.
	 *
	 * @throws OutOfBoundsException       When there is no such order in the administration.
	 * @throws SalesOrderRefusedException When it is not a confirmed sales order or nothing is left to invoice.
	 *
	 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-a-confirmed-sales-order-becomes-a-draft-invoice-req-qti-003
	 */
	public function invoiceFromOrder(string $orderId, string $administrationId): array {
		$order = $this->inAdministration(record: $this->findOrder(orderId: $orderId), administrationId: $administrationId);
		$orderNumber = (string)($order['orderNumber'] ?? $order['id']);
		if ((string)($order['orderType'] ?? '') !== self::ORDER_TYPE) {
			throw new SalesOrderRefusedException(template: 'Order %1$s is not a sales order.', parameters: [$orderNumber]);
		}

		$state = (string)($order['state'] ?? '');
		if ($state === 'invoiced') {
			throw new SalesOrderRefusedException(template: 'Every line of order %1$s is already invoiced.', parameters: [$orderNumber]);
		}

		if (in_array($state, self::INVOICEABLE_STATES, true) === false) {
			throw new SalesOrderRefusedException(template: 'Confirm order %1$s before you invoice it.', parameters: [$orderNumber]);
		}

		$customerId = trim((string)($order['counterpartyId'] ?? ''));
		if ($customerId === '') {
			throw new SalesOrderRefusedException(template: 'Order %1$s has no customer to invoice.', parameters: [$orderNumber]);
		}

		$orderLines = $this->records(schema: 'OrderLine', filters: ['orderId' => (string)$order['id']], sortBy: 'lineNumber');
		$open = array_values(array_filter($orderLines, static fn (array $line): bool => self::remainingOf(line: $line) > 0));
		if ($open === []) {
			throw new SalesOrderRefusedException(template: 'Every line of order %1$s is already invoiced.', parameters: [$orderNumber]);
		}

		$invoiceLines = [];
		foreach ($open as $index => $line) {
			$invoiceLines[] = self::invoiceLine(orderLine: $line, position: ($index + 1));
		}

		$invoiceDate = gmdate('Y-m-d');
		$invoice = array_merge(
			[
				'invoiceNumber' => $this->draftNumber(order: $order),
				'administrationId' => $administrationId,
				'customerId' => $customerId,
				'invoiceDate' => $invoiceDate,
				'dueDate' => gmdate('Y-m-d', ((int)strtotime($invoiceDate) + (self::TERM_DAYS * 86400))),
				'periodId' => substr($invoiceDate, 0, 7),
				'currency' => (string)($order['currency'] ?? 'EUR'),
				'lifecycleState' => 'draft',
				'invoiceType' => 'standard',
				'invoiceTypeCode' => self::TYPE_CODE,
				'salesOrder' => (string)$order['id'],
				'invoiceLines' => $invoiceLines,
			],
			self::totalsOf(lines: $invoiceLines)
		);

		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ARInvoice')->saveObject($invoice));
		$invoice['id'] = (string)($saved['id'] ?? '');

		// Mark what this invoice took, then move the order on (patch, never update).
		foreach ($open as $line) {
			$this->objectService->patchObject(
				(string)$line['id'],
				['invoicedQuantity' => (float)$line['quantity']],
				$this->registerLocation->getRegisterSlug(),
				'OrderLine'
			);
		}

		$invoice['orderState'] = self::stateAfter(orderLines: $orderLines, invoiced: array_column($open, 'id'));
		$this->objectService->patchObject(
			(string)$order['id'],
			['state' => $invoice['orderState']],
			$this->registerLocation->getRegisterSlug(),
			'OrderPrimitive'
		);

		$this->logger->info(
			'SalesOrderInvoicingService: sales order invoiced',
			['orderId' => $order['id'], 'invoiceId' => $invoice['id'], 'lines' => count($invoiceLines), 'state' => $invoice['orderState']]
		);

		return $invoice;

	}//end invoiceFromOrder()

	/**
	 * The record, unless it belongs to another administration: then it does not exist for this caller.
	 *
	 * @param array<string,mixed> $record           The record.
	 * @param string              $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When it belongs to another administration.
	 */
	private function inAdministration(array $record, string $administrationId): array {
		if ($administrationId === '' || (string)($record['administrationId'] ?? '') !== $administrationId) {
			throw new OutOfBoundsException('not in administration '.$administrationId);
		}

		return $record;

	}//end inAdministration()

	/**
	 * One record of a schema by uuid or business key.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $id               The uuid or business key.
	 * @param string $fallbackProperty The business key property.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When there is no such record.
	 */
	private function findRecord(string $schema, string $id, string $fallbackProperty): array {
		$found = ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: $id, fallbackProperty: $fallbackProperty);
		$record = ObjectIdentifier::recordWithId(candidate: $found);
		if ($record === null) {
			throw new OutOfBoundsException($schema.' '.$id.' not found');
		}

		return $record;

	}//end findRecord()

	/**
	 * The records of a schema matching the filters, in the order of a numeric property.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string,string> $filters The filters.
	 * @param string               $sortBy  The property to sort by.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function records(string $schema, array $filters, string $sortBy): array {
		$records = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		usort($records, static fn (array $left, array $right): int => ((int)($left[$sortBy] ?? 0) <=> (int)($right[$sortBy] ?? 0)));
		return $records;

	}//end records()

	/**
	 * The order line a quote line becomes: same description, quantity, price, VAT and revenue account.
	 *
	 * @param array<string,mixed> $quoteLine The quote line.
	 *
	 * @return array<string,mixed>
	 */
	private static function orderLine(array $quoteLine): array {
		$quantity = (float)($quoteLine['quantity'] ?? 0);
		$unitPrice = (float)($quoteLine['unitPrice'] ?? 0);
		$discount = (float)($quoteLine['discount'] ?? 0);

		return array_filter(
			[
				'lineNumber' => (int)($quoteLine['lineNumber'] ?? 0),
				'productReference' => self::textOrNull(value: $quoteLine['productReference'] ?? null),
				'description' => (string)($quoteLine['description'] ?? ''),
				'quantity' => $quantity,
				'unitPrice' => $unitPrice,
				'discount' => $discount,
				'vatRate' => (float)($quoteLine['vatRate'] ?? 0),
				'glAccount' => self::textOrNull(value: $quoteLine['glAccount'] ?? null),
				'projectReference' => self::textOrNull(value: $quoteLine['projectReference'] ?? null),
				'lineAmount' => self::euros(cents: self::netCents(quantity: $quantity, unitPrice: $unitPrice, discount: $discount)),
				'invoicedQuantity' => 0,
			],
			static fn (mixed $value): bool => $value !== null
		);

	}//end orderLine()

	/**
	 * The invoice line for what an order line has not invoiced yet.
	 *
	 * @param array<string,mixed> $orderLine The order line.
	 * @param int                 $position  The line's position on the invoice.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoiceLine(array $orderLine, int $position): array {
		$quantity = self::remainingOf(line: $orderLine);
		$unitPrice = (float)($orderLine['unitPrice'] ?? 0);
		$discount = (float)($orderLine['discount'] ?? 0);
		$netPrice = self::euros(cents: self::netCents(quantity: 1, unitPrice: $unitPrice, discount: $discount));
		$rate = self::rateOf(value: $orderLine['vatRate'] ?? 0);

		$line = [
			'lineId' => (string)$position,
			'quantity' => $quantity,
			'unitCode' => 'C62',
			'itemName' => (string)($orderLine['description'] ?? ''),
			'netPrice' => $netPrice,
			'netAmount' => self::euros(cents: self::netCents(quantity: $quantity, unitPrice: $unitPrice, discount: $discount)),
			'vatCategory' => self::categoryOf(rate: $rate),
			'vatRate' => $rate,
		];
		$glAccount = self::textOrNull(value: $orderLine['glAccount'] ?? null);
		if ($glAccount !== null) {
			$line['glAccount'] = $glAccount;
		}

		return $line;

	}//end invoiceLine()

	/**
	 * The order's state once the given lines are fully invoiced.
	 *
	 * @param list<array<string,mixed>> $orderLines Every line of the order.
	 * @param list<mixed>               $invoiced   The ids of the lines this invoice took in full.
	 *
	 * @return string `invoiced` when nothing is left, else `partly-invoiced`.
	 */
	private static function stateAfter(array $orderLines, array $invoiced): string {
		foreach ($orderLines as $line) {
			if (in_array($line['id'] ?? null, $invoiced, true) === false && self::remainingOf(line: $line) > 0) {
				return 'partly-invoiced';
			}
		}

		return 'invoiced';

	}//end stateAfter()

	/**
	 * The quantity of an order line not yet invoiced.
	 *
	 * @param array<string,mixed> $line The order line.
	 *
	 * @return float
	 */
	private static function remainingOf(array $line): float {
		return round(((float)($line['quantity'] ?? 0) - (float)($line['invoicedQuantity'] ?? 0)), 4);

	}//end remainingOf()

	/**
	 * A draft invoice number for the order: unique per order and invoice.
	 *
	 * @param array<string,mixed> $order The order.
	 *
	 * @return string
	 */
	private function draftNumber(array $order): string {
		$earlier = count($this->scoped(schema: 'ARInvoice')->findAll(['filters' => ['salesOrder' => (string)$order['id']]]));
		return sprintf('DRAFT-%s-%d', (string)($order['orderNumber'] ?? $order['id']), ($earlier + 1));

	}//end draftNumber()

	/**
	 * Net, VAT and gross of a set of lines, with the VAT breakdown per rate.
	 *
	 * @param list<array<string,mixed>> $lines The invoice lines.
	 *
	 * @return array<string,mixed>
	 */
	private static function totalsOf(array $lines): array {
		$perRate = [];
		foreach ($lines as $line) {
			$key = (string)$line['vatRate'];
			$perRate[$key] = ['rate' => (float)$line['vatRate'], 'net' => (($perRate[$key]['net'] ?? 0) + self::cents(amount: $line['netAmount']))];
		}

		$netCents = 0;
		$vatCents = 0;
		$breakdown = [];
		foreach ($perRate as $rate) {
			$vat = (int)round($rate['net'] * $rate['rate']);
			$netCents += $rate['net'];
			$vatCents += $vat;
			// BG-23 carries the rate in percent (BT-119), the lines as a fraction (ArInvoiceUblMapper).
			$breakdown[] = [
				'category' => self::categoryOf(rate: $rate['rate']),
				'rate' => round(($rate['rate'] * 100), 2),
				'taxableAmount' => self::euros(cents: $rate['net']),
				'taxAmount' => self::euros(cents: $vat),
			];
		}

		return [
			'netAmount' => self::euros(cents: $netCents),
			'lineNetTotal' => self::euros(cents: $netCents),
			'vatAmount' => self::euros(cents: $vatCents),
			'grossAmount' => self::euros(cents: ($netCents + $vatCents)),
			'amountDue' => self::euros(cents: ($netCents + $vatCents)),
			'vatBreakdown' => $breakdown,
		];

	}//end totalsOf()

	/**
	 * Net cents of a quantity at a unit price less a percentage discount.
	 *
	 * @param float $quantity  The quantity.
	 * @param float $unitPrice The unit price.
	 * @param float $discount  The discount in percent (0-100).
	 *
	 * @return int
	 */
	private static function netCents(float $quantity, float $unitPrice, float $discount): int {
		return (int)round($quantity * $unitPrice * (100 - $discount));

	}//end netCents()

	/**
	 * A VAT rate as a fraction: 21 and 0.21 both mean 21 percent.
	 *
	 * @param mixed $value The rate.
	 *
	 * @return float
	 */
	private static function rateOf(mixed $value): float {
		$rate = (float)$value;
		if ($rate >= 1) {
			$rate /= 100;
		}

		return round($rate, 4);

	}//end rateOf()

	/**
	 * UNTDID 5305 category of a rate: standard above zero, zero rated at zero.
	 *
	 * @param float $rate The rate.
	 *
	 * @return string
	 */
	private static function categoryOf(float $rate): string {
		if ($rate > 0) {
			return 'S';
		}

		return 'Z';

	}//end categoryOf()

	/**
	 * A trimmed text, or null when absent or empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function textOrNull(mixed $value): ?string {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;

	}//end textOrNull()

	/**
	 * Euros as whole cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 */
	private static function cents(mixed $amount): int {
		if (is_numeric($amount) === false) {
			return 0;
		}

		return (int)round((float)$amount * 100);

	}//end cents()

	/**
	 * Whole cents as euros.
	 *
	 * @param int $cents The cents.
	 *
	 * @return float
	 */
	private static function euros(int $cents): float {
		return round(($cents / 100), 2);

	}//end euros()

	/**
	 * The object service on one schema of the register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
