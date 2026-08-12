<?php

/**
 * Peppol Inbound UBL Invoice Listener
 *
 * Reacts to OpenRegister's ObjectCreatedEvent on the `PeppolInboundMessage`
 * schema published by openconnector's Peppol Access Point. When the
 * inbound message carries a UBL Invoice document, dispatches it into
 * {@see SupplierInvoiceService::ingestUBLInvoice()} so the invoice lands
 * in our SupplierInvoice register at lifecycle state `received`, ready
 * for the matching engine in slice 06 (REQ-PO3W-004 ingestion side).
 *
 * This is slice 05 of the bookkeeping-purchase-order-3way chain and
 * implements the "Subscribe to Peppol-received UBL Invoice events from
 * openconnector" task. The contract with openconnector is:
 *
 *  - openconnector receives a Peppol message via its Access Point;
 *  - it persists a `PeppolInboundMessage` record in OpenRegister with the
 *    fields `documentType` (e.g. "Invoice"), `payload` (the UBL XML),
 *    `peppolMessageId`, `receivedAt`, and `administrationId`;
 *  - OR dispatches ObjectCreatedEvent;
 *  - this listener picks it up, filters on `documentType=Invoice`, calls
 *    the service, and the SupplierInvoice is persisted.
 *
 * Fail-soft: any downstream exception is logged but never bubbles up to
 * the OR write itself — the source-of-truth is the openconnector inbound
 * record; downstream slices can re-process from it.
 *
 * Idempotency: SupplierInvoiceService::ingestUBLInvoice() de-duplicates
 * on (administrationId, invoiceNumber, supplierId); the listener trusts
 * that contract and does not re-check.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatcher from a Peppol inbound UBL Invoice -> SupplierInvoice register.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
 */
class PeppolInboundUblInvoiceListener implements IEventListener {
	/**
	 * OR schema slug published by openconnector for incoming Peppol
	 * messages. Kept as a constant so the test can assert against the
	 * exact same value the listener filters on.
	 *
	 * @var string
	 */
	public const PEPPOL_INBOUND_SCHEMA = 'PeppolInboundMessage';

	/**
	 * Peppol document type carried by UBL Invoice payloads. Other document
	 * types (CreditNote, Order, etc.) are ignored at this slice.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE_INVOICE = 'Invoice';

	/**
	 * Construct the listener with the ingestion service + a logger.
	 *
	 * @param SupplierInvoiceService $supplierInvoices Slice-05 ingestion service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly SupplierInvoiceService $supplierInvoices,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectCreatedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($this->schemaResolver->schemaSlug(entity: $entity) !== self::PEPPOL_INBOUND_SCHEMA) {
				return;
			}

			$message = $entity->getObject();
			if (is_array($message) === false) {
				return;
			}

			if ((string)($message['documentType'] ?? '') !== self::DOCUMENT_TYPE_INVOICE) {
				return;
			}

			$administrationId = trim((string)($message['administrationId'] ?? ''));
			if ($administrationId === '') {
				$this->logger->warning(
					'PeppolInboundUblInvoiceListener: inbound Peppol Invoice without administrationId — skipping',
					['peppolMessageId' => ($message['peppolMessageId'] ?? null)]
				);
				return;
			}

			$payload = (string)($message['payload'] ?? '');
			if (trim($payload) === '') {
				$this->logger->warning(
					'PeppolInboundUblInvoiceListener: inbound Peppol Invoice without payload — skipping',
					['peppolMessageId' => ($message['peppolMessageId'] ?? null)]
				);
				return;
			}

			$this->supplierInvoices->ingestUBLInvoice(
				administrationId: $administrationId,
				ublXml: $payload,
				context: [
					'peppolMessageId' => (string)($message['peppolMessageId'] ?? ''),
					'ublSourceUri' => (string)($message['ublSourceUri'] ?? ''),
					'peppolReceivedAt' => (string)($message['receivedAt'] ?? ''),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'PeppolInboundUblInvoiceListener: failed to ingest Peppol UBL Invoice',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()
}//end class
