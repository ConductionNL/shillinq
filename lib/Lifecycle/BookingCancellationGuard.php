<?php

/**
 * Booking Cancellation Guard
 *
 * ADR-031 exception-path lifecycle guard for the Order `cancelAfterInvoice`
 * transition (completed -> cancelled). Before a booking that already has an
 * issued final Invoice is cancelled and a reversing CreditNote is materialised
 * in Shillinq, it validates:
 *   1. The order carries an invoiceId (REQ-DI-006).
 *   2. The linked Invoice exists and is in a reversible state (issued /
 *      partially_paid / overdue — i.e. not already reversed) (REQ-DI-006).
 *   3. No CreditNote already reverses that invoice — idempotency (REQ-DI-006).
 *
 * It also exposes pure helpers to compose the reversing CreditNote and to
 * decide whether the deposit must be auto-refunded on cancellation (REQ-DI-006).
 *
 * Referenced from the BookingOrder schema's x-openregister-lifecycle transition
 * `cancelAfterInvoice.requires` in
 * lib/Settings/register.d/bookings-deposit-to-invoice.json.
 *
 * ADR-031 exception reason: cancellation reversal must resolve the linked
 * Invoice across schemas, enforce one-credit-note-per-invoice idempotency, and
 * mirror the full invoice gross — cross-schema lookups the declarative
 * lifecycle DSL cannot yet express.
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
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancellation-after-invoice precondition + credit-note composition helpers.
 *
 * Fail-closed: any unexpected exception denies the cancellation (CWE-863).
 *
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-16
 */
class BookingCancellationGuard {

	/**
	 * Invoice states from which a reversing credit note may still be issued.
	 *
	 * @var array<int,string>
	 */
	private const REVERSIBLE_INVOICE_STATES = [
		'issued',
		'partially_paid',
		'overdue',
	];

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
	 * Cancellation precondition for the Order `cancelAfterInvoice` transition
	 * (REQ-DI-006).
	 *
	 * Returns true only when the order has an invoiced, reversible final Invoice
	 * that has not yet been reversed by an existing CreditNote.
	 *
	 * Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $orderId The Order.id (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Order object being transitioned.
	 *
	 * @return bool True when the cancellation may proceed and a CreditNote materialise.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-27
	 */
	public function canCancel(string $orderId, ?array $object = null): bool {
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
				return false;
			}

			$invoiceId = (string)($order['invoiceId'] ?? '');
			if ($invoiceId === '') {
				// Not yet invoiced — this transition does not apply; deny.
				$this->logger->info(
					'BookingCancellationGuard: order has no invoice — cancelAfterInvoice not applicable',
					['orderId' => $orderId]
				);
				return false;
			}

			$invoice = $this->findOne(
				schema: 'Invoice',
				filters: [
					'invoiceId' => $invoiceId,
					'administrationId' => (string)($order['administrationId'] ?? ''),
				]
			);

			if ($invoice === null) {
				$this->logger->info(
					'BookingCancellationGuard: linked invoice not found — denying cancellation',
					['orderId' => $orderId, 'invoiceId' => $invoiceId]
				);
				return false;
			}

			if (in_array((string)($invoice['state'] ?? ''), self::REVERSIBLE_INVOICE_STATES, true) === false) {
				$this->logger->info(
					'BookingCancellationGuard: invoice not in a reversible state — denying cancellation',
					['orderId' => $orderId, 'invoiceId' => $invoiceId, 'state' => ($invoice['state'] ?? '')]
				);
				return false;
			}

			// REQ-DI-006 idempotency: one CreditNote per invoice.
			if ($this->creditNoteExistsFor(invoiceId: $invoiceId, administrationId: (string)($order['administrationId'] ?? '')) === true) {
				$this->logger->info(
					'BookingCancellationGuard: credit note already exists — denying duplicate reversal',
					['orderId' => $orderId, 'invoiceId' => $invoiceId]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'BookingCancellationGuard: canCancel failed — denying cancellation (fail-closed)',
				['orderId' => $orderId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canCancel()

	/**
	 * Compose the reversing CreditNote payload for a cancelled, invoiced order
	 * (REQ-DI-006).
	 *
	 * Reverses the full invoice gross. Pure function — the caller persists the
	 * returned array.
	 *
	 * @param array<string,mixed> $invoice The invoice being reversed.
	 * @param string $creditDate Credit date as YYYY-MM-DD.
	 * @param string $reason Human-readable cancellation reason.
	 *
	 * @return array<string,mixed> The CreditNote field map.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-16
	 */
	public function buildReversingCreditNote(array $invoice, string $creditDate, string $reason): array {
		return [
			'administrationId' => (string)($invoice['administrationId'] ?? ''),
			'linkedInvoiceId' => (string)($invoice['invoiceId'] ?? ''),
			'customerId' => (string)($invoice['customerId'] ?? ''),
			'creditDate' => $creditDate,
			'reason' => $reason,
			'grossAmount' => (int)($invoice['grossAmount'] ?? 0),
			'currency' => (string)($invoice['currency'] ?? 'EUR'),
			'state' => 'issued',
		];

	}//end buildReversingCreditNote()

	/**
	 * Decide whether the deposit must be auto-refunded when an order is
	 * cancelled (REQ-DI-006).
	 *
	 * Only deposits whose refundPolicy is automatic_on_cancellation and that are
	 * currently captured/authorized are auto-refunded; otherwise the operator
	 * processes the refund manually. Pure function.
	 *
	 * @param array<string,mixed>|null $deposit The linked DepositPayment, or null.
	 *
	 * @return bool True when an automatic refund must be initiated.
	 *
	 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-17
	 */
	public function shouldAutoRefundDeposit(?array $deposit): bool {
		if ($deposit === null) {
			return false;
		}

		if ((string)($deposit['refundPolicy'] ?? 'manual') !== 'automatic_on_cancellation') {
			return false;
		}

		return in_array((string)($deposit['state'] ?? ''), ['authorized', 'captured'], true);
	}//end shouldAutoRefundDeposit()

	/**
	 * Return true when a CreditNote already reverses the given invoice.
	 *
	 * @param string $invoiceId The linked invoice identifier.
	 * @param string $administrationId The owning administration for tenant scope.
	 *
	 * @return bool
	 */
	private function creditNoteExistsFor(string $invoiceId, string $administrationId): bool {
		$existing = $this->findOne(
			schema: 'CreditNote',
			filters: [
				'linkedInvoiceId' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);

		return $existing !== null;
	}//end creditNoteExistsFor()

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
	 * Returns null when the schema is not yet available (dependency not seeded).
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
				'BookingCancellationGuard: schema lookup unavailable — treating as absent',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findOne()
}//end class
