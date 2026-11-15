<?php

/**
 * Webshop Order Invoicer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Turns a received WebshopOrder into one sales invoice (REQ-SWO-001): an
 * ARInvoice with a line per order line and one for shipping, issued when the
 * channel's issue mode is auto-issue, its id recorded on the order, and the
 * order moved to invoiced. A second order with the same channel and shop
 * order number is marked duplicate and writes nothing; an order that cannot
 * be invoiced is refused with the reason. A business buyer is matched to a
 * CustomerMaster of the channel's administration by VAT id, KvK number or
 * email, or created; a consumer is booked on the channel's collective debtor
 * with the buyer's name on the invoice (REQ-SWO-002).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Webshop
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Webshop;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Writes the invoice of a received web shop order, once per channel and shop order number.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The intake checks, the debtor and the invoice belong to one decision per order.
 */
class WebshopOrderInvoicer {

	/**
	 * Days between the invoice date and its due date.
	 *
	 * @var int
	 */
	public const TERM_DAYS = 14;

	/**
	 * The most the order's own gross total may differ from the computed one, in cents.
	 *
	 * @var int
	 */
	public const TOTAL_TOLERANCE_CENTS = 2;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService OpenRegister's object service.
	 * @param ObjectTransitionRunnerInterface $transitions   Runs the order's declared transitions.
	 * @param IAppConfig                      $appConfig     App config for the register slug.
	 * @param LoggerInterface                 $logger        Logger.
	 * @param WebshopVatRoute                 $vatRoute      Decides the order's VAT route (REQ-SWO-003).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly WebshopVatRoute $vatRoute,
	) {
	}//end __construct()

	/**
	 * Handle one saved order: invoice it, mark it duplicate or refuse it.
	 *
	 * @param array<string,mixed> $order   The WebshopOrder as saved.
	 * @param string              $orderId Its id.
	 * @param string              $today   The invoice date (Y-m-d).
	 *
	 * @return string invoiced, duplicate, refused, or skipped for an order that is not waiting for its invoice.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public function handle(array $order, string $orderId, string $today): string {
		if (self::isWaiting(order: $order) === false || $orderId === '') {
			return 'skipped';
		}

		$channel = $this->record(schema: 'WebshopChannel', id: (string)($order['channelId'] ?? ''));
		if ($channel === null) {
			return $this->refuse(order: ['refusalReason' => 'The order names a web shop channel shillinq does not know.'] + $order, orderId: $orderId);
		}

		if ($this->alreadyInvoiced(order: $order, orderId: $orderId) === true) {
			$this->transitions->run(objectId: $orderId, action: 'markDuplicate');
			return 'duplicate';
		}

		$refusal = self::refusalOf(order: $order, channel: $channel);
		if ($refusal !== null) {
			return $this->refuse(order: ['refusalReason' => $refusal] + $order, orderId: $orderId);
		}

		$route = $this->vatRoute->routeFor(order: $order, invoiceDate: $today, adminId: (string)($channel['administrationId'] ?? ''));
		if ($route['refusal'] !== null) {
			return $this->refuse(order: ['refusalReason' => $route['refusal']] + $order, orderId: $orderId);
		}

		$built = self::invoiceLines(order: $order, channel: $channel, route: $route);
		$computed = $built['grossCents'];
		$stated = (int)round(((float)($order['totals']['gross'] ?? ($computed / 100))) * 100);
		if (abs($computed - $stated) > self::TOTAL_TOLERANCE_CENTS) {
			$reason = sprintf('The order lines add up to %.2f but the order total is %.2f.', ($computed / 100), ($stated / 100));
			return $this->refuse(order: ['refusalReason' => $reason] + $order, orderId: $orderId);
		}

		$debtorId = $this->debtorId(order: $order, channel: $channel);
		if ($debtorId === '') {
			return $this->refuse(order: ['refusalReason' => 'The web shop channel has no collective debtor for consumer orders.'] + $order, orderId: $orderId);
		}

		$invoiceId = $this->writeInvoice(
			invoice: self::invoice(
				order: $order,
				context: ['orderId' => $orderId, 'channel' => $channel, 'debtorId' => $debtorId, 'built' => $built, 'today' => $today, 'route' => $route]
			)
		);

		$invoiced = ['arInvoiceId' => $invoiceId] + $order;
		$this->saveOrder(order: $invoiced, orderId: $orderId);
		$this->transitions->run(objectId: $orderId, action: 'invoice');
		$this->settle(order: ['intakeState' => 'invoiced'] + $invoiced, orderId: $orderId);

		return 'invoiced';
	}//end handle()

	/**
	 * Settle the invoice of a paid order on the channel's clearing account
	 * (REQ-SWO-004).
	 *
	 * A paid order whose invoice is issued gets one receipt journal entry,
	 * debit the channel's clearing account and credit receivables, posted
	 * through its `postDirect` transition; the invoice keeps the provider's
	 * payment id as its payment reference for the payout match and is marked
	 * paid, and the order keeps the journal entry so it is settled once. A
	 * pending order only passes its payment id on to the invoice.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param string              $orderId Its id.
	 *
	 * @return string settled, pending, or skipped (no invoice, already settled, invoice not issued, no clearing account).
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function settle(array $order, string $orderId): string {
		$invoiceId = (string)($order['arInvoiceId'] ?? '');
		if ($orderId === '' || $invoiceId === '' || ($order['intakeState'] ?? '') !== 'invoiced'
			|| (string)($order['settlementJournalId'] ?? '') !== ''
		) {
			return 'skipped';
		}

		$invoice = $this->record(schema: 'ARInvoice', id: $invoiceId);
		if ($invoice === null) {
			return 'skipped';
		}

		$payment   = (array)($order['payment'] ?? []);
		$reference = trim((string)($payment['providerPaymentId'] ?? ''));
		$webshop   = (array)($invoice['webshop'] ?? []);
		if (($payment['status'] ?? '') !== 'paid') {
			if ($reference !== '' && (string)($webshop['paymentReference'] ?? '') !== $reference) {
				$this->saveInvoice(invoice: ['webshop' => ['paymentReference' => $reference] + $webshop] + $invoice, invoiceId: $invoiceId);
			}

			return 'pending';
		}

		$channel  = $this->record(schema: 'WebshopChannel', id: (string)($order['channelId'] ?? ''));
		$clearing = trim((string)($channel['clearingAccount'] ?? ''));
		if (($invoice['lifecycleState'] ?? '') !== 'issued' || $clearing === '') {
			return 'skipped';
		}

		$journalId = $this->postReceipt(
			journal: self::receiptJournal(
				order: $order,
				invoice: $invoice,
				accounts: ['clearing' => $clearing, 'receivables' => $this->receivablesAccount()],
				reference: $reference
			)
		);

		$this->saveInvoice(invoice: ['webshop' => ['paymentReference' => $reference] + $webshop] + $invoice, invoiceId: $invoiceId);
		$this->transitions->run(objectId: $invoiceId, action: 'mark-paid');
		$this->saveOrder(order: ['settlementJournalId' => $journalId] + $order, orderId: $orderId);

		return 'settled';
	}//end settle()

	/**
	 * Credit a refunded or cancelled order (REQ-SWO-005).
	 *
	 * An invoiced order whose payment status becomes refunded or cancelled
	 * gets one ARInvoice of type credit-note referencing the original invoice:
	 * for the whole invoice, or, when the shop names the refunded lines in
	 * `payment.refundedLines` (and `payment.refundedShipping`), for those
	 * lines and quantities only. The order keeps the credit note's id and
	 * moves to credited, so it is credited once.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param string              $orderId Its id.
	 * @param string              $today   The credit note date (Y-m-d).
	 *
	 * @return string credited, or skipped (not invoiced, not refunded or cancelled, already credited, invoice gone, nothing to credit).
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function credit(array $order, string $orderId, string $today): string {
		$invoiceId = (string)($order['arInvoiceId'] ?? '');
		$status    = (string)($order['payment']['status'] ?? '');
		if ($orderId === '' || $invoiceId === '' || ($order['intakeState'] ?? '') !== 'invoiced'
			|| in_array($status, ['refunded', 'cancelled'], true) === false
			|| trim((string)($order['creditNoteId'] ?? '')) !== ''
		) {
			return 'skipped';
		}

		$invoice = $this->record(schema: 'ARInvoice', id: $invoiceId);
		if ($invoice === null) {
			return 'skipped';
		}

		$credit = self::creditNote(order: $order, invoice: $invoice, today: $today);
		if ($credit === null) {
			return 'skipped';
		}

		$creditId = $this->writeInvoice(invoice: $credit);
		$this->saveOrder(order: ['creditNoteId' => $creditId] + $order, orderId: $orderId);
		$this->transitions->run(objectId: $orderId, action: 'credit');

		return 'credited';
	}//end credit()

	/**
	 * The credit note of a refunded order, or null when no line is refunded.
	 *
	 * A refund that names no lines credits the whole invoice, its totals
	 * negated. A partial refund credits the named order lines (`line`, the
	 * 1-based position in the order, with an optional `quantity`) and the
	 * shipping line when `refundedShipping` is true, each at its invoiced net
	 * price and VAT rate.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $invoice Its invoice.
	 * @param string              $today   The credit note date (Y-m-d).
	 *
	 * @return array<string,mixed>|null The ARInvoice payload of the credit note.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public static function creditNote(array $order, array $invoice, string $today): ?array {
		$credited = self::creditedLines(order: $order, invoice: $invoice);
		if ($credited['lines'] === []) {
			return null;
		}

		$number = (string)($invoice['invoiceNumber'] ?? '');
		$state  = 'issued';
		if ((string)($invoice['lifecycleState'] ?? 'draft') === 'draft') {
			$state = 'draft';
		}

		$credit = [
			'invoiceNumber' => $number . '-C',
			'invoiceType' => 'credit-note',
			'lifecycleState' => $state,
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'periodId' => substr($today, 0, 7),
			'customerId' => (string)($invoice['customerId'] ?? ''),
			'invoiceDate' => $today,
			'dueDate' => $today,
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'netAmount' => (0 - $credited['netCents']) / 100.0,
			'vatAmount' => (0 - $credited['vatCents']) / 100.0,
			'grossAmount' => (0 - ($credited['netCents'] + $credited['vatCents'])) / 100.0,
			'precedingInvoiceReferences' => [['reference' => $number, 'issueDate' => (string)($invoice['invoiceDate'] ?? '')]],
			'invoiceLines' => $credited['lines'],
		];
		foreach (['buyerName', 'ossContext'] as $key) {
			if (($invoice[$key] ?? null) !== null) {
				$credit[$key] = $invoice[$key];
			}
		}

		if (is_array($invoice['vatBreakdown'] ?? null) === true) {
			$credit['vatBreakdown'] = array_map(
				static fn (array $group): array => ['taxableAmount' => (0 - $credited['netCents']) / 100.0, 'taxAmount' => 0.0] + $group,
				array_values(array_filter((array)$invoice['vatBreakdown'], 'is_array'))
			);
		}

		$webshop = (array)($invoice['webshop'] ?? []);
		$webshop['refundStatus'] = (string)($order['payment']['status'] ?? '');
		$credit['webshop'] = $webshop;

		return $credit;
	}//end creditNote()

	/**
	 * The negated invoice lines a refund credits, with their net and VAT cents.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $invoice Its invoice.
	 *
	 * @return array{lines: array<int,array<string,mixed>>, netCents: int, vatCents: int}
	 */
	private static function creditedLines(array $order, array $invoice): array {
		$invoiceLines = array_values(array_filter((array)($invoice['invoiceLines'] ?? []), 'is_array'));
		$payment      = (array)($order['payment'] ?? []);
		$named        = array_values(array_filter((array)($payment['refundedLines'] ?? []), 'is_array'));
		$wanted       = [];
		if ($named === [] && ($payment['refundedShipping'] ?? false) !== true) {
			foreach ($invoiceLines as $line) {
				$wanted[] = [$line, (float)($line['quantity'] ?? 1)];
			}

			$whole = [
				'netCents' => (int)round((float)($invoice['netAmount'] ?? 0) * 100),
				'vatCents' => (int)round((float)($invoice['vatAmount'] ?? 0) * 100),
			];
			return ['lines' => self::negatedLines(wanted: $wanted)['lines']] + $whole;
		}

		$orderLineCount = count((array)($order['lines'] ?? []));
		foreach ($named as $refund) {
			$position = (int)($refund['line'] ?? 0);
			$line     = $invoiceLines[$position - 1] ?? null;
			if ($position < 1 || $position > $orderLineCount || $line === null) {
				continue;
			}

			$quantity = min((float)($line['quantity'] ?? 1), (float)($refund['quantity'] ?? ($line['quantity'] ?? 1)));
			if ($quantity > 0.0) {
				$wanted[] = [$line, $quantity];
			}
		}

		$shipping = $invoiceLines[$orderLineCount] ?? null;
		if (($payment['refundedShipping'] ?? false) === true && $shipping !== null) {
			$wanted[] = [$shipping, (float)($shipping['quantity'] ?? 1)];
		}

		return self::negatedLines(wanted: $wanted);
	}//end creditedLines()

	/**
	 * Negated credit note lines for [invoice line, credited quantity] pairs.
	 *
	 * @param array<int,array{0: array<string,mixed>, 1: float}> $wanted The lines and quantities to credit.
	 *
	 * @return array{lines: array<int,array<string,mixed>>, netCents: int, vatCents: int}
	 */
	private static function negatedLines(array $wanted): array {
		$lines = [];
		$net   = 0;
		$vat   = 0;
		foreach ($wanted as [$line, $quantity]) {
			$invoiced = (float)($line['quantity'] ?? 1);
			$netCents = (int)round((float)($line['netAmount'] ?? 0) * 100);
			if ($invoiced > 0.0 && $quantity < $invoiced) {
				$netCents = (int)round($netCents * $quantity / $invoiced);
			}

			$net += $netCents;
			$vat += (int)round($netCents * (float)($line['vatRate'] ?? 0) / 100);
			$lines[] = [
				'lineId' => (string)(count($lines) + 1),
				'quantity' => $quantity,
				'netAmount' => (0 - $netCents) / 100.0,
				'netPrice' => (0 - (float)($line['netPrice'] ?? 0)),
			] + $line;
		}

		return ['lines' => $lines, 'netCents' => $net, 'vatCents' => $vat];
	}//end negatedLines()

	/**
	 * The receipt journal entry of a paid order: debit the clearing account,
	 * credit receivables, for the invoice's gross amount.
	 *
	 * @param array<string,mixed>                          $order     The order.
	 * @param array<string,mixed>                          $invoice   Its issued invoice.
	 * @param array{clearing: string, receivables: string} $accounts  The two accounts.
	 * @param string                                       $reference The provider's payment id.
	 *
	 * @return array<string,mixed> The draft JournalEntry.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public static function receiptJournal(array $order, array $invoice, array $accounts, string $reference): array {
		$amount = round((float)($invoice['grossAmount'] ?? 0), 2);
		$date   = substr((string)($order['payment']['paidAt'] ?? ($invoice['invoiceDate'] ?? gmdate('Y-m-d'))), 0, 10);
		$memo   = sprintf('Web shop order %s paid, invoice %s', (string)($order['shopOrderId'] ?? ''), (string)($invoice['invoiceNumber'] ?? ''));
		if ($reference !== '') {
			$memo .= ', reference ' . $reference;
		}

		return [
			'journalNumber' => 'WEB-PAY-' . (string)($order['shopOrderId'] ?? ''),
			'entryDate' => $date,
			'description' => $memo,
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => (string)($invoice['administrationId'] ?? ($order['administrationId'] ?? '')),
			'state' => 'draft',
			'lines' => [
				['accountNumber' => $accounts['clearing'], 'side' => 'debit', 'amount' => $amount, 'description' => $memo],
				['accountNumber' => $accounts['receivables'], 'side' => 'credit', 'amount' => $amount, 'description' => $memo],
			],
		];
	}//end receiptJournal()

	/**
	 * Save the receipt journal entry and post it.
	 *
	 * @param array<string,mixed> $journal The draft JournalEntry.
	 *
	 * @return string Its id.
	 *
	 * @throws RuntimeException When the save answers without an id.
	 */
	private function postReceipt(array $journal): string {
		$saved = $this->objectService->saveObject(object: $journal, register: $this->register(), schema: 'JournalEntry');
		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new RuntimeException('The web shop receipt was saved but OpenRegister answered without an id.');
		}

		$this->transitions->run(objectId: $id, action: 'postDirect');
		return $id;
	}//end postReceipt()

	/**
	 * The receivables control account, as the ledger posting is configured.
	 *
	 * @return string
	 */
	private function receivablesAccount(): string {
		$configured = trim($this->appConfig->getValueString(AppIdentity::APP_ID, MaterialiseGlTransactionAction::CFG_AR_CONTROL_ACCOUNT, ''));
		if ($configured !== '') {
			return $configured;
		}

		return '1100';
	}//end receivablesAccount()

	/**
	 * Save an invoice back under its own id.
	 *
	 * @param array<string,mixed> $invoice   The invoice with the change.
	 * @param string              $invoiceId Its id.
	 *
	 * @return void
	 */
	private function saveInvoice(array $invoice, string $invoiceId): void {
		unset($invoice['@self']);
		$this->objectService->saveObject(object: $invoice, register: $this->register(), schema: 'ARInvoice', uuid: $invoiceId);
	}//end saveInvoice()

	/**
	 * Whether an order still waits for its invoice: received, with no invoice and no refusal.
	 *
	 * @param array<string,mixed> $order The order.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public static function isWaiting(array $order): bool {
		return (($order['intakeState'] ?? 'received') === 'received')
			&& trim((string)($order['arInvoiceId'] ?? '')) === ''
			&& trim((string)($order['refusalReason'] ?? '')) === '';
	}//end isWaiting()

	/**
	 * Why an order cannot be invoiced, or null when it can.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $channel Its channel.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public static function refusalOf(array $order, array $channel): ?string {
		$lines = $order['lines'] ?? [];
		if (is_array($lines) === false || $lines === []) {
			return 'The order has no lines.';
		}

		$administrationId = (string)($order['administrationId'] ?? '');
		if ($administrationId !== '' && $administrationId !== (string)($channel['administrationId'] ?? '')) {
			return 'The order names another administration than its web shop channel.';
		}

		return null;
	}//end refusalOf()

	/**
	 * The invoice lines of an order with their net, VAT and gross totals in cents.
	 *
	 * With prices including VAT the line's gross is kept and its net derived by
	 * dividing out the rate, so the invoice total equals what the buyer paid.
	 * Shipping is one more line at the highest rate of the order lines.
	 *
	 * The route (from WebshopVatRoute::routeFor()) maps each shop rate to the
	 * rate the invoice charges: the destination rate of an OSS sale, whose
	 * net is derived from what the buyer paid, or 0 under reverse charge,
	 * whose net is the shop's price without its own VAT. Without a route a
	 * line keeps the shop's rate.
	 *
	 * @param array<string,mixed>      $order   The order.
	 * @param array<string,mixed>      $channel Its channel.
	 * @param array<string,mixed>|null $route   The VAT route; null keeps the shop's rates.
	 *
	 * @return array{lines: array<int,array<string,mixed>>, netCents: int, vatCents: int, grossCents: int}
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	public static function invoiceLines(array $order, array $channel, ?array $route = null): array {
		$includesVat = (($order['pricesIncludeVat'] ?? false) === true);
		$lines = [];
		$totals = ['net' => 0, 'vat' => 0];
		$highestRate = 0.0;
		foreach ((array)$order['lines'] as $line) {
			$quantity = (float)($line['quantity'] ?? 1);
			$rate = max(0.0, (float)($line['vatRate'] ?? 0));
			$highestRate = max($highestRate, $rate);
			$name = trim((string)($line['description'] ?? ''));
			if ($name === '') {
				$name = (string)($line['sku'] ?? 'Item');
			}

			$lines[] = self::line(
				spec: [
					'number' => (count($lines) + 1),
					'name' => $name,
					'quantity' => $quantity,
					'unitPrice' => (float)($line['unitPrice'] ?? 0),
					'includesVat' => $includesVat,
					'account' => (string)($channel['revenueAccount'] ?? ''),
				] + self::routedRate(shopRate: $rate, route: $route),
				totals: $totals
			);
		}

		$shipping = (float)($order['shipping'] ?? 0);
		if ($shipping > 0.0) {
			$account = (string)($channel['shippingAccount'] ?? '');
			if ($account === '') {
				$account = (string)($channel['revenueAccount'] ?? '');
			}

			$lines[] = self::line(
				spec: [
					'number' => (count($lines) + 1),
					'name' => 'Shipping',
					'quantity' => 1.0,
					'unitPrice' => $shipping,
					'includesVat' => $includesVat,
					'account' => $account,
				] + self::routedRate(shopRate: $highestRate, route: $route),
				totals: $totals
			);
		}

		return ['lines' => $lines, 'netCents' => $totals['net'], 'vatCents' => $totals['vat'], 'grossCents' => ($totals['net'] + $totals['vat'])];
	}//end invoiceLines()

	/**
	 * The rate a line charges, the rate its net is derived with, and its VAT category.
	 *
	 * @param float                    $shopRate The rate the shop applied, in percent.
	 * @param array<string,mixed>|null $route    The VAT route; null keeps the shop's rate.
	 *
	 * @return array{rate: float, netRate: float, category: string}
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.3
	 */
	private static function routedRate(float $shopRate, ?array $route): array {
		$rate = $shopRate;
		$rates = (array)($route['rates'] ?? []);
		$key = WebshopVatRoute::rateKey(rate: $shopRate);
		if ($route !== null && array_key_exists($key, $rates) === true) {
			$rate = (float)$rates[$key];
		}

		if (($route['route'] ?? '') === 'icp') {
			return ['rate' => 0.0, 'netRate' => $shopRate, 'category' => 'K'];
		}

		$category = 'Z';
		if ($rate > 0.0) {
			$category = 'S';
		}

		return ['rate' => $rate, 'netRate' => $rate, 'category' => $category];
	}//end routedRate()

	/**
	 * One invoice line, adding its net and VAT cents to the running totals.
	 *
	 * The spec holds the line number, the line text (name), the quantity, the
	 * unit price as the shop sent it, the VAT rate in percent the line charges
	 * (rate), the rate its net is derived with when the price includes VAT
	 * (netRate), its VAT category, whether the unit price includes VAT
	 * (includesVat) and the revenue account.
	 *
	 * @param array<string,mixed>       $spec   The line: number, name, quantity, unitPrice, rate, netRate, category, includesVat, account.
	 * @param array{net: int, vat: int} $totals The running totals.
	 *
	 * @return array<string,mixed>
	 */
	private static function line(array $spec, array &$totals): array {
		$quantity = (float)$spec['quantity'];
		$rate = (float)$spec['rate'];
		$account = (string)$spec['account'];
		$amountCents = (int)round($quantity * (float)$spec['unitPrice'] * 100);
		$netCents = $amountCents;
		if ($spec['includesVat'] === true) {
			$netCents = (int)round($amountCents / (1 + ((float)$spec['netRate'] / 100)));
		}

		$vatCents = (int)round($netCents * $rate / 100);
		if ($spec['includesVat'] === true && $rate === (float)$spec['netRate']) {
			$vatCents = ($amountCents - $netCents);
		}

		$totals['net'] += $netCents;
		$totals['vat'] += $vatCents;

		$netPrice = round(($netCents / 100), 2);
		if ($quantity > 0.0) {
			$netPrice = round(($netCents / 100 / $quantity), 4);
		}

		$line = [
			'lineId' => (string)$spec['number'],
			'quantity' => $quantity,
			'unitCode' => 'C62',
			'itemName' => (string)$spec['name'],
			'netPrice' => $netPrice,
			'netAmount' => ($netCents / 100.0),
			'vatCategory' => (string)$spec['category'],
			'vatRate' => $rate,
		];

		if ($account !== '') {
			$line['glAccount'] = $account;
		}

		return $line;
	}//end line()

	/**
	 * The ARInvoice payload of an order.
	 *
	 * The context holds the order's id (orderId), its channel, the
	 * CustomerMaster to book on (debtorId), the lines and totals from
	 * invoiceLines() (built), the invoice date as Y-m-d (today) and the VAT
	 * route (route): an OSS sale carries its ossContext for the OSS return, a
	 * reverse-charged one the mention "Btw verlegd" in its VAT breakdown.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $context The invoice context: orderId, channel, debtorId, built, today, route.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public static function invoice(array $order, array $context): array {
		$orderId = (string)$context['orderId'];
		$channel = (array)$context['channel'];
		$built = (array)$context['built'];
		$today = (string)$context['today'];
		$channelId = (string)($order['channelId'] ?? '');
		$shopOrderId = (string)($order['shopOrderId'] ?? '');
		$state = 'draft';
		if (($channel['issueMode'] ?? '') === 'auto-issue') {
			$state = 'issued';
		}

		$webshop = ['channelId' => $channelId, 'shopOrderId' => $shopOrderId, 'orderId' => $orderId];
		$invoice = [
			'invoiceNumber' => sprintf('WEB-%s-%s', strtoupper(substr(hash('sha256', $channelId), 0, 6)), $shopOrderId),
			'administrationId' => (string)($channel['administrationId'] ?? ''),
			'periodId' => substr($today, 0, 7),
			'customerId' => (string)$context['debtorId'],
			'invoiceDate' => $today,
			'dueDate' => gmdate('Y-m-d', ((int)strtotime($today . ' 00:00:00 UTC') + (self::TERM_DAYS * 86400))),
			'currency' => (string)($order['currency'] ?? 'EUR'),
			'netAmount' => ($built['netCents'] / 100.0),
			'vatAmount' => ($built['vatCents'] / 100.0),
			'grossAmount' => ($built['grossCents'] / 100.0),
			'lifecycleState' => $state,
			'invoiceType' => 'standard',
			'invoiceLines' => $built['lines'],
		];

		if (($order['buyer']['type'] ?? 'consumer') !== 'business') {
			$buyerName = trim((string)($order['buyer']['name'] ?? ''));
			if ($buyerName !== '') {
				$invoice['buyerName'] = $buyerName;
				$webshop['buyerName'] = $buyerName;
			}
		}

		$reference = trim((string)($order['payment']['providerPaymentId'] ?? ''));
		if ($reference !== '') {
			$webshop['paymentReference'] = $reference;
		}

		$route = (array)($context['route'] ?? []);
		if (($route['route'] ?? '') !== '') {
			$webshop['vatRoute'] = (string)$route['route'];
		}

		if (is_array($route['ossContext'] ?? null) === true) {
			$invoice['ossContext'] = $route['ossContext'];
		}

		if (($route['route'] ?? '') === 'icp') {
			$mention = (string)($route['mention'] ?? WebshopVatRoute::REVERSE_CHARGE_MENTION);
			$webshop['vatMention'] = $mention;
			$invoice['vatBreakdown'] = [[
				'category' => 'K',
				'taxableAmount' => ($built['netCents'] / 100.0),
				'taxAmount' => 0.0,
				'rate' => 0.0,
				'exemptionReasonCode' => 'VATEX-EU-IC',
				'exemptionReasonText' => $mention,
			]];
		}

		$invoice['webshop'] = $webshop;

		return $invoice;
	}//end invoice()

	/**
	 * The CustomerMaster the order is booked on.
	 *
	 * A consumer goes to the channel's collective debtor. A business is matched
	 * within the channel's administration by VAT id, then KvK number, then
	 * email; without a match one is created.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $channel Its channel.
	 *
	 * @return string The CustomerMaster id; '' when a consumer order has no collective debtor.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.2
	 */
	private function debtorId(array $order, array $channel): string {
		$buyer = (array)($order['buyer'] ?? []);
		if (($buyer['type'] ?? 'consumer') !== 'business') {
			return trim((string)($channel['consumerDebtorId'] ?? ''));
		}

		$administrationId = (string)($channel['administrationId'] ?? '');
		$customers = $this->rows(schema: 'CustomerMaster', filters: ['administrationId' => $administrationId]);
		foreach (['vatId' => 'vatId', 'kvkNumber' => 'kvkNumber', 'email' => 'email'] as $buyerKey => $customerKey) {
			$wanted = self::normalised(value: (string)($buyer[$buyerKey] ?? ''));
			if ($wanted === '') {
				continue;
			}

			foreach ($customers as $customer) {
				if (($customer['administrationId'] ?? '') === $administrationId
					&& self::normalised(value: (string)($customer[$customerKey] ?? '')) === $wanted
				) {
					return ObjectIdentifier::resolve(saved: $customer);
				}
			}
		}

		return $this->createCustomer(buyer: $buyer, administrationId: $administrationId, channelId: ObjectIdentifier::resolve(saved: $channel));
	}//end debtorId()

	/**
	 * A matching key without case, spaces or dots.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private static function normalised(string $value): string {
		return strtolower(str_replace([' ', '.'], '', trim($value)));
	}//end normalised()

	/**
	 * Create the CustomerMaster of a business buyer, marked as created by the
	 * web shop channel so a bookkeeper can find and review it (REQ-SWO-002).
	 *
	 * @param array<string,mixed> $buyer            The buyer.
	 * @param string              $administrationId The administration.
	 * @param string              $channelId        The web shop channel the order came from.
	 *
	 * @return string The new customer's id.
	 *
	 * @throws RuntimeException When the save answers without an id.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.2
	 */
	private function createCustomer(array $buyer, string $administrationId, string $channelId): string {
		$legalName = trim((string)($buyer['companyName'] ?? ''));
		if ($legalName === '') {
			$legalName = trim((string)($buyer['name'] ?? ''));
		}

		$customer = [
			'customerId' => self::customerNumber(administrationId: $administrationId, buyer: ['legalName' => $legalName] + $buyer),
			'legalName' => $legalName,
			'email' => (string)($buyer['email'] ?? ''),
			'administrationId' => $administrationId,
			'lifecycleState' => 'active',
		];
		if ($channelId !== '') {
			$customer['createdByWebshopChannelId'] = $channelId;
		}

		foreach (['vatId', 'kvkNumber'] as $key) {
			$value = trim((string)($buyer[$key] ?? ''));
			if ($value !== '') {
				$customer[$key] = $value;
			}
		}

		$saved = $this->objectService->saveObject(object: $customer, register: $this->register(), schema: 'CustomerMaster');
		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new RuntimeException('The web shop buyer was saved as a customer but OpenRegister answered without an id.');
		}

		return $id;
	}//end createCustomer()

	/**
	 * A stable customer number for a buyer created from a web shop order.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $buyer            The buyer with its resolved legalName and its email.
	 *
	 * @return string
	 */
	private static function customerNumber(string $administrationId, array $buyer): string {
		$seed = $administrationId . '|' . strtolower((string)($buyer['legalName'] ?? '')) . '|' . strtolower((string)($buyer['email'] ?? ''));

		return 'WEB-' . strtoupper(substr(hash('sha256', $seed), 0, 10));
	}//end customerNumber()

	/**
	 * Whether another order of the same channel and shop order number already has its invoice.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param string              $orderId Its id.
	 *
	 * @return bool
	 */
	private function alreadyInvoiced(array $order, string $orderId): bool {
		$channelId = (string)($order['channelId'] ?? '');
		$shopOrderId = (string)($order['shopOrderId'] ?? '');
		foreach ($this->rows(schema: 'WebshopOrder', filters: ['channelId' => $channelId, 'shopOrderId' => $shopOrderId]) as $other) {
			if (($other['channelId'] ?? '') !== $channelId || (string)($other['shopOrderId'] ?? '') !== $shopOrderId) {
				continue;
			}

			if (ObjectIdentifier::resolve(saved: $other) === $orderId) {
				continue;
			}

			if (trim((string)($other['arInvoiceId'] ?? '')) !== '' || in_array(($other['intakeState'] ?? ''), ['invoiced', 'credited'], true) === true) {
				return true;
			}
		}

		return false;
	}//end alreadyInvoiced()

	/**
	 * Record the refusal on the order and move it to refused.
	 *
	 * @param array<string,mixed> $order   The order, its refusalReason set to why it is refused.
	 * @param string              $orderId Its id.
	 *
	 * @return string refused
	 */
	private function refuse(array $order, string $orderId): string {
		$reason = (string)($order['refusalReason'] ?? '');
		$this->saveOrder(order: $order, orderId: $orderId);
		$this->transitions->run(objectId: $orderId, action: 'refuse');
		$this->logger->info('WebshopOrderInvoicer: order refused', ['orderId' => $orderId, 'reason' => $reason]);

		return 'refused';
	}//end refuse()

	/**
	 * Save the invoice and return its id.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice payload.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the save answers without an id.
	 */
	private function writeInvoice(array $invoice): string {
		$saved = $this->objectService->saveObject(object: $invoice, register: $this->register(), schema: 'ARInvoice');
		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new RuntimeException('The web shop invoice was saved but OpenRegister answered without an id.');
		}

		return $id;
	}//end writeInvoice()

	/**
	 * Save the order back under its own id.
	 *
	 * @param array<string,mixed> $order   The order with the change.
	 * @param string              $orderId Its id.
	 *
	 * @return void
	 */
	private function saveOrder(array $order, string $orderId): void {
		unset($order['@self']);
		$this->objectService->saveObject(object: $order, register: $this->register(), schema: 'WebshopOrder', uuid: $orderId);
	}//end saveOrder()

	/**
	 * One record by id, or null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function record(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $this->objectService->setRegister($this->register())->setSchema($schema), id: $id);
	}//end record()

	/**
	 * The rows of a schema matching the filters, as arrays.
	 *
	 * @param string              $schema  The schema.
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);

		$rows = [];
		foreach ($found as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			} elseif (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$rows[] = ['id' => ObjectIdentifier::resolve(saved: $row)] + (array)$row->getObject();
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
