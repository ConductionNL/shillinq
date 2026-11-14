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
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
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

		$built = self::invoiceLines(order: $order, channel: $channel);
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
				context: ['orderId' => $orderId, 'channel' => $channel, 'debtorId' => $debtorId, 'built' => $built, 'today' => $today]
			)
		);

		$this->saveOrder(order: ['arInvoiceId' => $invoiceId] + $order, orderId: $orderId);
		$this->transitions->run(objectId: $orderId, action: 'invoice');

		return 'invoiced';
	}//end handle()

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

		$country = strtoupper(trim((string)($order['buyer']['countryCode'] ?? 'NL')));
		if ($country !== '' && $country !== 'NL') {
			return sprintf('The buyer is in %s; orders to another country are not invoiced until their VAT route is set up.', $country);
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
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $channel Its channel.
	 *
	 * @return array{lines: array<int,array<string,mixed>>, netCents: int, vatCents: int, grossCents: int}
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public static function invoiceLines(array $order, array $channel): array {
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
					'rate' => $rate,
					'includesVat' => $includesVat,
					'account' => (string)($channel['revenueAccount'] ?? ''),
				],
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
					'rate' => $highestRate,
					'includesVat' => $includesVat,
					'account' => $account,
				],
				totals: $totals
			);
		}

		return ['lines' => $lines, 'netCents' => $totals['net'], 'vatCents' => $totals['vat'], 'grossCents' => ($totals['net'] + $totals['vat'])];
	}//end invoiceLines()

	/**
	 * One invoice line, adding its net and VAT cents to the running totals.
	 *
	 * The spec holds the line number, the line text (name), the quantity, the
	 * unit price as the shop sent it, the VAT rate in percent, whether the unit
	 * price includes VAT (includesVat) and the revenue account.
	 *
	 * @param array<string,mixed>       $spec   The line: number, name, quantity, unitPrice, rate, includesVat, account.
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
		$vatCents = (int)round($amountCents * $rate / 100);
		if ($spec['includesVat'] === true) {
			$netCents = (int)round($amountCents / (1 + ($rate / 100)));
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
			'vatCategory' => 'Z',
			'vatRate' => $rate,
		];
		if ($rate > 0.0) {
			$line['vatCategory'] = 'S';
		}

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
	 * invoiceLines() (built) and the invoice date as Y-m-d (today).
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param array<string,mixed> $context The invoice context: orderId, channel, debtorId, built, today.
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

		return $this->createCustomer(buyer: $buyer, administrationId: $administrationId);
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
	 * Create the CustomerMaster of a business buyer.
	 *
	 * @param array<string,mixed> $buyer            The buyer.
	 * @param string              $administrationId The administration.
	 *
	 * @return string The new customer's id.
	 *
	 * @throws RuntimeException When the save answers without an id.
	 */
	private function createCustomer(array $buyer, string $administrationId): string {
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
