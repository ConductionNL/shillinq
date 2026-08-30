<?php

/**
 * Invoice-from-Booking Guard
 *
 * ADR-031 exception-path lifecycle guard for the Order `complete` transition
 * (confirmed -> completed). Before a booking order is marked completed and a
 * final Invoice is materialised in Shillinq, it validates:
 *   1. completedAt is set (REQ-DI-002).
 *   2. When a deposit is required, the linked DepositPayment exists and is in
 *      the `authorized` state (REQ-DI-002).
 *   3. The order has not already been invoiced — idempotency (REQ-DI-002).
 *
 * It also exposes pure computation helpers used to build the final invoice's
 * line items, deposit credit, VAT and gross totals, and due date
 * (REQ-DI-003/004/005). These are deterministic, side-effect-free, and unit
 * tested directly.
 *
 * Referenced from the BookingOrder schema's x-openregister-lifecycle transition
 * `complete.requires` in lib/Settings/register.d/bookings-deposit-to-invoice.json.
 *
 * ADR-031 exception reason: the deposit-credit calculation must resolve the
 * authorised amount from a related DepositPayment record and split VAT across a
 * positive service line and a 0%-VAT negative credit line — cross-schema lookup
 * plus integer-cent arithmetic the declarative lifecycle DSL cannot yet express.
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Completion precondition + invoice-composition helpers for the BookingOrder
 * schema.
 *
 * Fail-closed: any unexpected exception denies the completion (CWE-863).
 *
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-6
 */
class InvoiceFromBookingGuard {

	/**
	 * Default net payment terms, in days, when an order declares none (REQ-DI-005).
	 */
	private const DEFAULT_PAYMENT_TERMS_DAYS = 14;

	/**
	 * Default VAT percentage when an order declares none (REQ-DI-004).
	 */
	private const DEFAULT_TAX_RATE = 21;

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Completion precondition for the Order `complete` transition (REQ-DI-002).
	 *
	 * Returns true only when the order may be completed and a final invoice
	 * materialised: completedAt is present, any required deposit is authorised,
	 * and the order has not already been invoiced (idempotency).
	 *
	 * Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $orderId The Order.id (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Order object being transitioned.
	 *
	 * @return bool True when the order may transition to completed.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-22
	 */
	public function canComplete(string $orderId, ?array $object = null): bool {
		try {
			// BUGFIX (issue #503, 2026-07-23): this literal was still 'Order'
			// after the booking-context order schema was renamed to
			// 'BookingOrder' in 07709a0f (to free the `Order` slug for
			// abstract-order-primitive). Untested because every existing
			// test pre-supplies $object, never exercising this fallback
			// lookup — the fallback silently resolved 0 rows against a
			// schema slug that no longer existed.
			$order = ($object ?? $this->findOne(schema: 'BookingOrder', filters: ['orderId' => $orderId]));
			if ($order === null) {
				$this->logger->info(
					'InvoiceFromBookingGuard: order not found — denying completion',
					['orderId' => $orderId]
				);
				return false;
			}

			// REQ-DI-002: completedAt must be set before invoicing.
			if (($order['completedAt'] ?? null) === null || (string)$order['completedAt'] === '') {
				$this->logger->info(
					'InvoiceFromBookingGuard: completedAt not set — denying completion',
					['orderId' => $orderId]
				);
				return false;
			}

			// REQ-DI-002: idempotency — never invoice an order twice.
			if (($order['invoiceId'] ?? null) !== null && (string)$order['invoiceId'] !== '') {
				$this->logger->info(
					'InvoiceFromBookingGuard: order already invoiced — denying duplicate completion',
					['orderId' => $orderId, 'invoiceId' => $order['invoiceId']]
				);
				return false;
			}

			// REQ-DI-002: a required deposit must be authorised before invoicing.
			return $this->depositIsSatisfied(order: $order);
		} catch (\Throwable $e) {
			$this->logger->error(
				'InvoiceFromBookingGuard: canComplete failed — denying completion (fail-closed)',
				['orderId' => $orderId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canComplete()

	/**
	 * Verify that any required deposit is present and authorised (REQ-DI-002).
	 *
	 * Orders without a deposit rule are always satisfied (REQ-DI-008).
	 *
	 * @param array<string,mixed> $order The order being completed.
	 *
	 * @return bool True when no deposit is required or the deposit is authorised.
	 */
	private function depositIsSatisfied(array $order): bool {
		if (((bool)($order['depositRequired'] ?? false)) === false) {
			return true;
		}

		$depositPaymentId = (string)($order['depositPaymentId'] ?? '');
		if ($depositPaymentId === '') {
			$this->logger->info(
				'InvoiceFromBookingGuard: deposit required but no depositPaymentId — denying completion',
				['orderId' => ($order['orderId'] ?? 'unknown')]
			);
			return false;
		}

		$deposit = $this->findOne(
			schema: 'DepositPayment',
			filters: [
				'depositPaymentId' => $depositPaymentId,
				'administrationId' => (string)($order['administrationId'] ?? ''),
			]
		);

		if ($deposit === null || (string)($deposit['state'] ?? '') !== 'authorized') {
			$this->logger->info(
				'InvoiceFromBookingGuard: deposit not authorised — denying completion',
				[
					'orderId' => ($order['orderId'] ?? 'unknown'),
					'depositPaymentId' => $depositPaymentId,
					'state' => ($deposit['state'] ?? 'missing'),
				]
			);
			return false;
		}

		return true;
	}//end depositIsSatisfied()

	/**
	 * Resolve the deposit credit amount, in minor units, to apply to the final
	 * invoice for an order (REQ-DI-003).
	 *
	 * Prefers the explicit Order.depositAmount; falls back to the linked
	 * DepositPayment.amount. Returns 0 when no deposit applies (REQ-DI-008) or
	 * the deposit is not authorised.
	 *
	 * @param array<string,mixed> $order The order being invoiced.
	 *
	 * @return int Deposit credit amount in minor units (>= 0).
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-7
	 */
	public function resolveDepositCreditCents(array $order): int {
		if (((bool)($order['depositRequired'] ?? false)) === false) {
			return 0;
		}

		$explicit = (int)($order['depositAmount'] ?? 0);
		if ($explicit > 0) {
			return $explicit;
		}

		$depositPaymentId = (string)($order['depositPaymentId'] ?? '');
		if ($depositPaymentId === '') {
			return 0;
		}

		$deposit = $this->findOne(
			schema: 'DepositPayment',
			filters: [
				'depositPaymentId' => $depositPaymentId,
				'administrationId' => (string)($order['administrationId'] ?? ''),
			]
		);

		if ($deposit === null || (string)($deposit['state'] ?? '') !== 'authorized') {
			return 0;
		}

		return max(0, (int)($deposit['amount'] ?? 0));
	}//end resolveDepositCreditCents()

	/**
	 * Build the final invoice line items for an order (REQ-DI-003/004).
	 *
	 * Always emits a positive service line taxed at the order's tax rate. When a
	 * deposit credit applies, appends a negative 0%-VAT deposit-credit line.
	 * All amounts are in minor currency units (EUR cents). Pure function.
	 *
	 * @param array<string,mixed> $order The order being invoiced.
	 * @param int $depositCreditCents Deposit credit in minor units (>= 0).
	 *
	 * @return array<int,array<string,mixed>> Ordered invoice line arrays.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-8
	 */
	public function buildLineItems(array $order, int $depositCreditCents): array {
		$netCents = (int)($order['basePrice'] ?? 0);
		$taxRate = (int)($order['taxRate'] ?? self::DEFAULT_TAX_RATE);
		$taxCents = $this->vatOn(netCents: $netCents, taxRate: $taxRate);

		$lines = [
			[
				'administrationId' => (string)($order['administrationId'] ?? ''),
				'lineNumber' => 1,
				'lineType' => 'service',
				'description' => (string)($order['description'] ?? 'Booking service'),
				'quantity' => 1,
				'unitPrice' => $netCents,
				'lineAmount' => $netCents,
				'taxRate' => $taxRate,
				'taxAmount' => $taxCents,
				'grossAmount' => ($netCents + $taxCents),
			],
		];

		if ($depositCreditCents > 0) {
			$creditCents = (0 - $depositCreditCents);
			// REQ-DI-004: deposit was already taxed at collection — credit carries 0% VAT.
			$lines[] = [
				'administrationId' => (string)($order['administrationId'] ?? ''),
				'lineNumber' => 2,
				'lineType' => 'deposit_credit',
				'description' => 'Deposit Credit Applied',
				'quantity' => 1,
				'unitPrice' => $creditCents,
				'lineAmount' => $creditCents,
				'taxRate' => 0,
				'taxAmount' => 0,
				'grossAmount' => $creditCents,
			];
		}

		return $lines;
	}//end buildLineItems()

	/**
	 * Compute net/VAT/gross totals for a set of invoice lines (REQ-DI-003/004).
	 *
	 * The net amount is the sum of positive (service) net line amounts only — the
	 * deposit credit is a post-VAT gross reduction, not a net reduction (D4).
	 * The VAT amount is the sum of all line VAT (credit lines contribute 0).
	 * The gross amount is the sum of every line gross, including the negative credit.
	 * Pure function.
	 *
	 * @param array<int,array<string,mixed>> $lines Invoice line arrays.
	 *
	 * @return array{netAmount:int,vatAmount:int,grossAmount:int} Totals in minor units.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-9
	 */
	public function computeTotals(array $lines): array {
		$net = 0;
		$vat = 0;
		$gross = 0;
		foreach ($lines as $line) {
			$lineAmount = (int)($line['lineAmount'] ?? 0);
			if ($lineAmount > 0) {
				$net += $lineAmount;
			}

			$vat += (int)($line['taxAmount'] ?? 0);
			$gross += (int)($line['grossAmount'] ?? 0);
		}

		return [
			'netAmount' => $net,
			'vatAmount' => $vat,
			'grossAmount' => $gross,
		];

	}//end computeTotals()

	/**
	 * Compute the invoice due date from an invoice date and an order's payment
	 * terms (REQ-DI-005).
	 *
	 * The due date is invoiceDate + paymentTermsDays (default 14). Pure function;
	 * handles month/year boundaries via the date arithmetic of the calendar.
	 *
	 * @param string $invoiceDate Invoice date as YYYY-MM-DD.
	 * @param array<string,mixed> $order The order being invoiced.
	 *
	 * @return string Due date as YYYY-MM-DD.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-10
	 */
	public function computeDueDate(string $invoiceDate, array $order): string {
		$termsDays = (int)($order['paymentTermsDays'] ?? self::DEFAULT_PAYMENT_TERMS_DAYS);
		if ($termsDays < 0) {
			$termsDays = self::DEFAULT_PAYMENT_TERMS_DAYS;
		}

		$utc = new DateTimeZone('UTC');

		// Validate strictly so malformed input falls back to today rather than
		// letting the constructor coerce a partial date. Avoids static factory.
		$spec = 'today';
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate) === 1) {
			$spec = $invoiceDate . ' 00:00:00';
		}

		$base = new DateTimeImmutable($spec, $utc);

		return $base->add(new DateInterval('P' . $termsDays . 'D'))->format('Y-m-d');
	}//end computeDueDate()

	/**
	 * Build the stable source-document URN for an order (REQ-DI-001).
	 *
	 * Also serves as the invoice materialisation idempotency key (REQ-DI-002).
	 * Pure function.
	 *
	 * @param string $orderId The order identifier.
	 *
	 * @return string The URN urn:nextcloud:booking:order:{orderId}.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-11
	 */
	public function sourceDocumentUri(string $orderId): string {
		return 'urn:nextcloud:booking:order:' . $orderId;
	}//end sourceDocumentUri()

	/**
	 * Compute integer-cent VAT for a net amount at a whole-percentage rate.
	 *
	 * Uses round-half-up on the cent product to match Dutch invoicing rounding.
	 * Pure function.
	 *
	 * @param int $netCents Net amount in minor units.
	 * @param int $taxRate VAT percentage (e.g. 21).
	 *
	 * @return int VAT amount in minor units.
	 */
	private function vatOn(int $netCents, int $taxRate): int {
		if ($taxRate <= 0 || $netCents === 0) {
			return 0;
		}

		return (int)round(($netCents * $taxRate) / 100);
	}//end vatOn()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Find a single record by exact-match filters in the configured register.
	 *
	 * Returns null when the schema is not yet available (dependency not seeded),
	 * keeping the guard usable before sibling registers are merged.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$result = $objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll(['filters' => $filters, 'limit' => 1]);

			if (is_array($result) === false || count($result) === 0) {
				return null;
			}

			return reset($result);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'InvoiceFromBookingGuard: schema lookup unavailable — treating as absent',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findOne()
}//end class
