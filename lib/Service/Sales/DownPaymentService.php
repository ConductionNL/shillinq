<?php

/**
 * Shillinq DownPaymentService
 *
 * A down payment on an order is an ARInvoice of type code 386 for part of the
 * order, split over the order's VAT rates in proportion; the final invoice of
 * the order takes every issued down payment off as negative lines at the down
 * payment's own net and VAT (sales-down-payments REQ-SDP-001, REQ-SDP-003,
 * REQ-SDP-004, REQ-SDP-005). The posting of both is MaterialiseGlTransactionAction's;
 * the check and the stamp on issue run through DownPaymentGuard.
 *
 * Amounts are computed in whole cents, so the final invoice's VAT is the
 * order's VAT minus what the down payments charged, to the cent.
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
 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;

/**
 * Raises down payments on an order and deducts them on its final invoice.
 *
 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Raise, deduct, check and stamp share one set of amount rules.
 */
class DownPaymentService {
	/**
	 * UNTDID 1001 code of a prepayment invoice.
	 *
	 * @var string
	 */
	public const TYPE_CODE = '386';

	/**
	 * States in which a down payment has been issued and can be deducted.
	 *
	 * @var list<string>
	 */
	private const DEDUCTIBLE_STATES = ['issued', 'paid', 'overdue', 'disputed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param IL10N                  $l10n          Line texts on the invoice.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read an ARInvoice by uuid or invoice number.
	 *
	 * @param string $invoiceId The uuid or number.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When there is no such invoice.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
	 */
	public function findInvoice(string $invoiceId): array {
		$invoice = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'ARInvoice'), id: $invoiceId, fallbackProperty: 'invoiceNumber');
		if ($invoice === null) {
			throw new OutOfBoundsException('invoice ' . $invoiceId . ' not found');
		}

		return (ObjectIdentifier::recordWithId(candidate: $invoice) ?? $invoice);

	}//end findInvoice()

	/**
	 * Raise a draft down-payment invoice on an order (REQ-SDP-001).
	 *
	 * @param array<string,mixed> $request administrationId, customerId, orderReference, orderLabel,
	 *                                     orderVatBreakdown (when shillinq cannot read the order),
	 *                                     percentage or amount, invoiceNumber, invoiceDate, dueDate.
	 *
	 * @return array<string,mixed> The saved draft invoice.
	 *
	 * @throws DownPaymentRefusedException When the request cannot make a down payment.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-2.2
	 */
	public function raise(array $request): array {
		$administrationId = trim((string)($request['administrationId'] ?? ''));
		$customerId = trim((string)($request['customerId'] ?? ''));
		$orderReference = trim((string)($request['orderReference'] ?? ''));
		if ($administrationId === '' || $customerId === '' || $orderReference === '') {
			throw new DownPaymentRefusedException(template: 'Choose the customer and the order.');
		}

		$order = $this->readOrder(orderReference: $orderReference, administrationId: $administrationId);
		$breakdown = $order['breakdown'];
		if ($breakdown === []) {
			$breakdown = self::breakdownOf(given: $request['orderVatBreakdown'] ?? []);
		}

		if ($breakdown === []) {
			throw new DownPaymentRefusedException(template: 'Enter the order\'s net total per VAT rate: shillinq cannot read this order.');
		}

		$orderCents = array_sum(array_column($breakdown, 'cents'));
		$percentage = self::numberOrNull(value: $request['percentage'] ?? null);
		$amount = self::numberOrNull(value: $request['amount'] ?? null);
		$targetCents = self::targetCents(orderCents: $orderCents, percentage: $percentage, amount: $amount);

		$orderLabel = trim((string)($request['orderLabel'] ?? ''));
		if ($orderLabel === '') {
			$orderLabel = $order['label'];
		}

		if ($orderLabel === '') {
			$orderLabel = $orderReference;
		}

		$lines = $this->downPaymentLines(breakdown: $breakdown, orderCents: $orderCents, targetCents: $targetCents, orderLabel: $orderLabel);
		$invoiceDate = self::dateOr(value: $request['invoiceDate'] ?? null, fallback: gmdate('Y-m-d'));

		$invoice = [
			'administrationId' => $administrationId,
			'customerId' => $customerId,
			'invoiceNumber' => $this->invoiceNumber(given: (string)($request['invoiceNumber'] ?? ''), administrationId: $administrationId, invoiceDate: $invoiceDate),
			'invoiceDate' => $invoiceDate,
			'dueDate' => self::dateOr(value: $request['dueDate'] ?? null, fallback: gmdate('Y-m-d', ((int)strtotime($invoiceDate) + (14 * 86400)))),
			'periodId' => substr($invoiceDate, 0, 7),
			'currency' => 'EUR',
			'lifecycleState' => 'draft',
			'invoiceType' => 'standard',
			'invoiceTypeCode' => self::TYPE_CODE,
			'invoiceLines' => $lines,
			// A nested null fails the validator (nullable is not honoured below
			// the top level), so an absent percentage or amount is left out.
			'downPayment' => array_filter(
				[
				'kind' => 'down-payment',
				'orderReference' => $orderReference,
				'orderLabel' => $orderLabel,
				'orderNetTotal' => self::euros(cents: $orderCents),
				'orderVatBreakdown' => array_map(
					static fn (array $rate): array => ['rate' => $rate['rate'], 'net' => self::euros(cents: $rate['cents'])],
					$breakdown
				),
				'percentage' => $percentage,
				'amount' => $amount,
				],
				static fn (mixed $value): bool => $value !== null
			),
		];
		$invoice = array_merge($invoice, self::totalsOf(lines: $lines));

		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'ARInvoice')->saveObject($invoice));
		$invoice['id'] = (string)($saved['id'] ?? '');
		$this->logger->info(
			'DownPaymentService: down payment raised',
			['invoiceId' => $invoice['id'], 'orderReference' => $orderReference, 'net' => $invoice['netAmount']]
		);

		return $invoice;

	}//end raise()

	/**
	 * The issued down payments of the invoice's customer that no invoice has
	 * deducted, optionally for one order (REQ-SDP-003).
	 *
	 * @param array<string,mixed> $invoice        The (final) invoice.
	 * @param string              $orderReference Only this order; '' for every order.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
	 */
	public function openDownPayments(array $invoice, string $orderReference = ''): array {
		$ownId = (string)($invoice['id'] ?? '');
		$open = [];
		foreach ($this->downPaymentsOf(invoice: $invoice) as $downPayment) {
			$group = (array)($downPayment['downPayment'] ?? []);
			if (in_array((string)($downPayment['lifecycleState'] ?? ''), self::DEDUCTIBLE_STATES, true) === false) {
				continue;
			}

			if ((string)($group['deductedOnInvoiceId'] ?? '') !== '' || (string)($downPayment['id'] ?? '') === $ownId) {
				continue;
			}

			if ($orderReference !== '' && (string)($group['orderReference'] ?? '') !== $orderReference) {
				continue;
			}

			$open[] = $downPayment;
		}

		return $open;

	}//end openDownPayments()

	/**
	 * Make a draft invoice the final invoice of an order: one negative line per
	 * open down payment and VAT rate, the totals reduced, each down payment
	 * referenced (REQ-SDP-003). Saved on the draft.
	 *
	 * @param array<string,mixed> $invoice        The draft invoice.
	 * @param string              $orderReference The order.
	 *
	 * @return array<string,mixed> The invoice as saved.
	 *
	 * @throws DownPaymentRefusedException When the invoice is not a draft or nothing is open.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
	 */
	public function deductOnto(array $invoice, string $orderReference): array {
		if ((string)($invoice['lifecycleState'] ?? '') !== 'draft') {
			throw new DownPaymentRefusedException(template: 'Down payments can only be deducted on a draft invoice.');
		}

		if ((string)($invoice['invoiceTypeCode'] ?? '') === self::TYPE_CODE) {
			throw new DownPaymentRefusedException(template: 'A down-payment invoice cannot deduct other down payments.');
		}

		$already = array_column((array)(($invoice['downPayment'] ?? [])['deductions'] ?? []), 'invoiceId');
		$open = array_values(
			array_filter(
				$this->openDownPayments(invoice: $invoice, orderReference: $orderReference),
				static fn (array $downPayment): bool => in_array((string)$downPayment['id'], $already, true) === false
			)
		);
		if ($open === []) {
			throw new DownPaymentRefusedException(template: 'This customer has no issued down payment on this order left to deduct.');
		}

		$lines = (array)($invoice['invoiceLines'] ?? []);
		$deductions = (array)(($invoice['downPayment'] ?? [])['deductions'] ?? []);
		$references = (array)($invoice['precedingInvoiceReferences'] ?? []);
		$orderLabel = '';
		foreach ($open as $downPayment) {
			$orderLabel = (string)(($downPayment['downPayment'] ?? [])['orderLabel'] ?? $orderReference);
			foreach ($this->chargedPerRate(downPayment: $downPayment) as $rate) {
				$deductions[] = [
					'invoiceId' => (string)$downPayment['id'],
					'invoiceNumber' => (string)($downPayment['invoiceNumber'] ?? ''),
					'rate' => $rate['rate'],
					'net' => self::euros(cents: $rate['net']),
					'vat' => self::euros(cents: $rate['vat']),
				];
				$lines[] = $this->deductionLine(downPayment: $downPayment, rate: $rate, position: (count($lines) + 1));
			}

			$references[] = ['reference' => (string)($downPayment['invoiceNumber'] ?? ''), 'issueDate' => (string)($downPayment['invoiceDate'] ?? '')];
		}

		$patch = self::reduceTotals(invoice: $invoice, deductions: array_slice($deductions, count((array)(($invoice['downPayment'] ?? [])['deductions'] ?? []))));
		$patch['invoiceLines'] = $lines;
		$patch['precedingInvoiceReferences'] = $references;
		$patch['downPayment'] = [
			'kind' => 'final',
			'orderReference' => $orderReference,
			'orderLabel' => $orderLabel,
			'deductions' => $deductions,
		];

		$this->scoped(schema: 'ARInvoice')->patchObject((string)$invoice['id'], $patch);
		return array_merge($invoice, $patch);

	}//end deductOnto()

	/**
	 * Refuse to issue a final invoice whose down payment was already deducted
	 * on another invoice, or whose deductions exceed its total (REQ-SDP-004).
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return void
	 *
	 * @throws DownPaymentRefusedException When the invoice may not be issued.
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
	 */
	public function requireOpenDeductions(array $invoice): void {
		$deductions = self::deductionsOf(invoice: $invoice);
		if ($deductions === []) {
			return;
		}

		if (self::cents(amount: $invoice['grossAmount'] ?? 0) < 0) {
			throw new DownPaymentRefusedException(template: 'The down payments deducted exceed the invoice total.');
		}

		$ownId = (string)($invoice['id'] ?? '');
		foreach (array_unique(array_column($deductions, 'invoiceId')) as $downPaymentId) {
			$downPayment = $this->findDownPayment(downPaymentId: (string)$downPaymentId);
			$group = (array)($downPayment['downPayment'] ?? []);
			$deductedOn = (string)($group['deductedOnInvoiceId'] ?? '');
			if ($deductedOn !== '' && $deductedOn !== $ownId) {
				throw new DownPaymentRefusedException(
					template: 'Down payment %1$s was already deducted on invoice %2$s.',
					parameters: [(string)($downPayment['invoiceNumber'] ?? $downPaymentId), (string)($group['deductedOnInvoiceNumber'] ?? $deductedOn)]
				);
			}

			if ((string)($downPayment['customerId'] ?? '') !== (string)($invoice['customerId'] ?? '')) {
				throw new DownPaymentRefusedException(
					template: 'Down payment %1$s belongs to another customer.',
					parameters: [(string)($downPayment['invoiceNumber'] ?? $downPaymentId)]
				);
			}
		}

	}//end requireOpenDeductions()

	/**
	 * Record on each down payment the final invoice that deducted it (REQ-SDP-004).
	 *
	 * @param array<string,mixed> $invoice The issued final invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.1
	 */
	public function stampDeductions(array $invoice): void {
		foreach (array_unique(array_column(self::deductionsOf(invoice: $invoice), 'invoiceId')) as $downPaymentId) {
			$downPayment = $this->findDownPayment(downPaymentId: (string)$downPaymentId);
			$group = (array)($downPayment['downPayment'] ?? []);
			$group['deductedOnInvoiceId'] = (string)($invoice['id'] ?? '');
			$group['deductedOnInvoiceNumber'] = (string)($invoice['invoiceNumber'] ?? '');
			$this->scoped(schema: 'ARInvoice')->patchObject((string)$downPayment['id'], ['downPayment' => $group]);
		}

	}//end stampDeductions()

	/**
	 * Every down payment of the invoice's order with its amount, paid state
	 * and the invoice that deducted it (REQ-SDP-005).
	 *
	 * @param array<string,mixed> $invoice A down-payment or final invoice.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
	 */
	public function position(array $invoice): array {
		$orderReference = (string)(($invoice['downPayment'] ?? [])['orderReference'] ?? '');
		if ($orderReference === '') {
			return [];
		}

		$rows = [];
		foreach ($this->downPaymentsOf(invoice: $invoice) as $downPayment) {
			$group = (array)($downPayment['downPayment'] ?? []);
			if ((string)($group['orderReference'] ?? '') !== $orderReference) {
				continue;
			}

			$rows[] = [
				'id' => (string)$downPayment['id'],
				'invoiceNumber' => (string)($downPayment['invoiceNumber'] ?? ''),
				'invoiceDate' => (string)($downPayment['invoiceDate'] ?? ''),
				'grossAmount' => (float)($downPayment['grossAmount'] ?? 0),
				'lifecycleState' => (string)($downPayment['lifecycleState'] ?? ''),
				'paid' => ($downPayment['lifecycleState'] ?? '') === 'paid',
				'deductedOnInvoiceId' => (string)($group['deductedOnInvoiceId'] ?? ''),
				'deductedOnInvoiceNumber' => (string)($group['deductedOnInvoiceNumber'] ?? ''),
			];
		}

		usort($rows, static fn (array $left, array $right): int => strcmp($left['invoiceNumber'], $right['invoiceNumber']));
		return $rows;

	}//end position()

	/**
	 * The down-payment invoices of the invoice's administration and customer.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function downPaymentsOf(array $invoice): array {
		$rows = $this->scoped(schema: 'ARInvoice')->findAll(
			[
				'filters' => [
					'administrationId' => (string)($invoice['administrationId'] ?? ''),
					'customerId' => (string)($invoice['customerId'] ?? ''),
					'invoiceTypeCode' => self::TYPE_CODE,
				],
			]
		);

		$out = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)(($record['downPayment'] ?? [])['kind'] ?? '') === 'down-payment') {
				$out[] = $record;
			}
		}

		return $out;

	}//end downPaymentsOf()

	/**
	 * Read one deducted down payment.
	 *
	 * @param string $downPaymentId The down-payment invoice id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DownPaymentRefusedException When it no longer exists.
	 */
	private function findDownPayment(string $downPaymentId): array {
		try {
			return $this->findInvoice(invoiceId: $downPaymentId);
		} catch (OutOfBoundsException $e) {
			throw new DownPaymentRefusedException(template: 'Down payment %1$s no longer exists.', parameters: [$downPaymentId]);
		}

	}//end findDownPayment()

	/**
	 * The order's label and net total per VAT rate, when it is a shillinq
	 * OrderPrimitive with lines (design.md D2).
	 *
	 * @param string $orderReference   The order id.
	 * @param string $administrationId The administration it must belong to.
	 *
	 * @return array{label: string, breakdown: list<array{rate: float, cents: int}>}
	 */
	private function readOrder(string $orderReference, string $administrationId): array {
		$order = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'OrderPrimitive'), id: $orderReference, fallbackProperty: 'orderNumber');
		$order = ObjectIdentifier::recordWithId(candidate: $order);
		if ($order === null || (string)($order['administrationId'] ?? '') !== $administrationId) {
			return ['label' => '', 'breakdown' => []];
		}

		$perRate = [];
		$orderLines = $this->scoped(schema: 'OrderLine')->findAll(['filters' => ['orderId' => (string)$order['id']]]);
		foreach ($orderLines as $row) {
			$line = (ObjectIdentifier::recordWithId(candidate: $row) ?? []);
			$rate = self::rateOf(value: $line['vatRate'] ?? 0);
			$key = (string)$rate;
			$perRate[$key] = [
				'rate' => $rate,
				'cents' => (($perRate[$key]['cents'] ?? 0) + self::cents(amount: $line['lineAmount'] ?? 0)),
			];
		}

		return [
			'label' => (string)($order['orderNumber'] ?? ''),
			'breakdown' => array_values(array_filter($perRate, static fn (array $rate): bool => $rate['cents'] > 0)),
		];

	}//end readOrder()

	/**
	 * The breakdown the user entered, in cents.
	 *
	 * @param mixed $given The request's orderVatBreakdown.
	 *
	 * @return list<array{rate: float, cents: int}>
	 */
	private static function breakdownOf(mixed $given): array {
		$out = [];
		foreach ((array)$given as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$cents = self::cents(amount: $entry['net'] ?? 0);
			if ($cents > 0) {
				$out[] = ['rate' => self::rateOf(value: $entry['rate'] ?? 0), 'cents' => $cents];
			}
		}

		return $out;

	}//end breakdownOf()

	/**
	 * The down payment's net in cents.
	 *
	 * @param int        $orderCents The order's net total.
	 * @param float|null $percentage The percentage asked.
	 * @param float|null $amount     The fixed net amount asked.
	 *
	 * @return int
	 *
	 * @throws DownPaymentRefusedException When the request asks nothing, or more than the order.
	 */
	private static function targetCents(int $orderCents, ?float $percentage, ?float $amount): int {
		$target = 0;
		if ($percentage !== null) {
			$target = (int)round($orderCents * $percentage / 100);
		}

		if ($percentage === null && $amount !== null) {
			$target = self::cents(amount: $amount);
		}

		if ($target <= 0) {
			throw new DownPaymentRefusedException(template: 'Enter a percentage or an amount above zero.');
		}

		if ($target > $orderCents) {
			throw new DownPaymentRefusedException(
				template: 'A down payment cannot be more than the order\'s net total of %1$s.',
				parameters: ['EUR ' . number_format(($orderCents / 100), 2, '.', ',')]
			);
		}

		return $target;

	}//end targetCents()

	/**
	 * One invoice line per VAT rate, the target split in proportion; the last
	 * rate takes the rounding remainder so the lines add up to the target.
	 *
	 * @param list<array{rate: float, cents: int}> $breakdown   The order per rate.
	 * @param int                                  $orderCents  The order's net total.
	 * @param int                                  $targetCents The down payment's net.
	 * @param string                               $orderLabel  The order's name.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function downPaymentLines(array $breakdown, int $orderCents, int $targetCents, string $orderLabel): array {
		$lines = [];
		$left = $targetCents;
		$last = (count($breakdown) - 1);
		foreach ($breakdown as $index => $rate) {
			$share = $left;
			if ($index < $last) {
				$share = (int)round($targetCents * $rate['cents'] / $orderCents);
			}

			$left -= $share;
			$net = self::euros(cents: $share);
			$lines[] = [
				'lineId' => (string)($index + 1),
				'quantity' => 1,
				'unitCode' => 'C62',
				'itemName' => $this->l10n->t('Down payment on order %1$s', [$orderLabel]),
				'netPrice' => $net,
				'netAmount' => $net,
				'vatCategory' => self::categoryOf(rate: $rate['rate']),
				'vatRate' => $rate['rate'],
			];
		}

		return $lines;

	}//end downPaymentLines()

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
			$net = (($perRate[$key]['net'] ?? 0) + self::cents(amount: $line['netAmount']));
			$perRate[$key] = ['rate' => (float)$line['vatRate'], 'net' => $net];
		}

		$netCents = 0;
		$vatCents = 0;
		$breakdown = [];
		foreach ($perRate as $rate) {
			$vat = (int)round($rate['net'] * $rate['rate']);
			$netCents += $rate['net'];
			$vatCents += $vat;
			$breakdown[] = [
				'category' => self::categoryOf(rate: $rate['rate']),
				'rate' => $rate['rate'],
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
	 * What a down payment charged per VAT rate: its VAT breakdown, else its lines.
	 *
	 * @param array<string,mixed> $downPayment The down-payment invoice.
	 *
	 * @return list<array{rate: float, net: int, vat: int}>
	 */
	private function chargedPerRate(array $downPayment): array {
		$out = [];
		foreach ((array)($downPayment['vatBreakdown'] ?? []) as $group) {
			if (is_array($group) === true && self::cents(amount: $group['taxableAmount'] ?? 0) !== 0) {
				$out[] = [
					'rate' => self::rateOf(value: $group['rate'] ?? 0),
					'net' => self::cents(amount: $group['taxableAmount']),
					'vat' => self::cents(amount: $group['taxAmount'] ?? 0),
				];
			}
		}

		if ($out !== []) {
			return $out;
		}

		foreach ((array)($downPayment['invoiceLines'] ?? []) as $line) {
			if (is_array($line) === true) {
				$rate = self::rateOf(value: $line['vatRate'] ?? 0);
				$net = self::cents(amount: $line['netAmount'] ?? 0);
				$out[] = ['rate' => $rate, 'net' => $net, 'vat' => (int)round($net * $rate)];
			}
		}

		return $out;

	}//end chargedPerRate()

	/**
	 * A negative line that takes one rate of a down payment off.
	 *
	 * @param array<string,mixed>                   $downPayment The down payment.
	 * @param array{rate: float, net: int, vat: int} $rate        What it charged at one rate.
	 * @param int                                   $position    The line number.
	 *
	 * @return array<string,mixed>
	 */
	private function deductionLine(array $downPayment, array $rate, int $position): array {
		return [
			'lineId' => (string)$position,
			'quantity' => -1,
			'unitCode' => 'C62',
			'itemName' => $this->l10n->t('Down payment %1$s deducted', [(string)($downPayment['invoiceNumber'] ?? '')]),
			'netPrice' => self::euros(cents: $rate['net']),
			'netAmount' => self::euros(cents: -$rate['net']),
			'vatCategory' => self::categoryOf(rate: $rate['rate']),
			'vatRate' => $rate['rate'],
			'downPaymentInvoiceId' => (string)$downPayment['id'],
		];

	}//end deductionLine()

	/**
	 * The final invoice's totals less the new deductions.
	 *
	 * @param array<string,mixed>             $invoice    The draft invoice.
	 * @param list<array<string,mixed>>       $deductions The deductions added now.
	 *
	 * @return array<string,mixed>
	 */
	private static function reduceTotals(array $invoice, array $deductions): array {
		$netCents = 0;
		$vatCents = 0;
		$perRate = [];
		foreach ($deductions as $deduction) {
			$net = self::cents(amount: $deduction['net']);
			$vat = self::cents(amount: $deduction['vat']);
			$netCents += $net;
			$vatCents += $vat;
			$key = (string)$deduction['rate'];
			$perRate[$key] = [($perRate[$key][0] ?? 0) + $net, ($perRate[$key][1] ?? 0) + $vat];
		}

		$gross = (self::cents(amount: $invoice['grossAmount'] ?? 0) - $netCents - $vatCents);
		$paid = self::cents(amount: $invoice['paidAmount'] ?? 0);
		$patch = [
			'netAmount' => self::euros(cents: (self::cents(amount: $invoice['netAmount'] ?? 0) - $netCents)),
			'vatAmount' => self::euros(cents: (self::cents(amount: $invoice['vatAmount'] ?? 0) - $vatCents)),
			'grossAmount' => self::euros(cents: $gross),
			'amountDue' => self::euros(cents: ($gross - $paid)),
		];
		if (is_numeric($invoice['lineNetTotal'] ?? null) === true) {
			$patch['lineNetTotal'] = self::euros(cents: (self::cents(amount: $invoice['lineNetTotal']) - $netCents));
		}

		$breakdown = [];
		foreach ((array)($invoice['vatBreakdown'] ?? []) as $group) {
			$key = (string)self::rateOf(value: $group['rate'] ?? 0);
			if (isset($perRate[$key]) === true) {
				$group['taxableAmount'] = self::euros(cents: (self::cents(amount: $group['taxableAmount'] ?? 0) - $perRate[$key][0]));
				$group['taxAmount'] = self::euros(cents: (self::cents(amount: $group['taxAmount'] ?? 0) - $perRate[$key][1]));
			}

			$breakdown[] = $group;
		}

		if ($breakdown !== []) {
			$patch['vatBreakdown'] = $breakdown;
		}

		return $patch;

	}//end reduceTotals()

	/**
	 * The deductions a final invoice carries.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function deductionsOf(array $invoice): array {
		$group = (array)($invoice['downPayment'] ?? []);
		if (($group['kind'] ?? '') !== 'final') {
			return [];
		}

		return array_values(array_filter((array)($group['deductions'] ?? []), 'is_array'));

	}//end deductionsOf()

	/**
	 * The given invoice number, else the administration's next one for the year.
	 *
	 * @param string $given            The number the user entered.
	 * @param string $administrationId The administration.
	 * @param string $invoiceDate      The invoice date.
	 *
	 * @return string
	 */
	private function invoiceNumber(string $given, string $administrationId, string $invoiceDate): string {
		$given = trim($given);
		if ($given !== '') {
			return $given;
		}

		$count = count($this->scoped(schema: 'ARInvoice')->findAll(['filters' => ['administrationId' => $administrationId]]));
		return sprintf('%s-%04d', substr($invoiceDate, 0, 4), ($count + 1));

	}//end invoiceNumber()

	/**
	 * A VAT rate as a fraction: 21 and 0.21 both mean 21 percent.
	 *
	 * @param mixed $value The rate.
	 *
	 * @return float
	 */
	private static function rateOf(mixed $value): float {
		$rate = (float)$value;
		if ($rate > 1) {
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
	 * A number, or null when absent or empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float|null
	 */
	private static function numberOrNull(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;

	}//end numberOrNull()

	/**
	 * A Y-m-d date, or the fallback.
	 *
	 * @param mixed  $value    The value.
	 * @param string $fallback The fallback.
	 *
	 * @return string
	 */
	private static function dateOr(mixed $value, string $fallback): string {
		if (is_string($value) === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}

		return $fallback;

	}//end dateOr()

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
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
