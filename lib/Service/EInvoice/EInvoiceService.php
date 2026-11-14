<?php

/**
 * E-Invoice Orchestrator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drives the "Send e-invoice" flow (REQ-EINV-005) for an issued `ARInvoice`:
 * pre-send validation (KvK/BTW/Peppol participant, REQ-EINV-003) -> NLCIUS UBL
 * 2.1 rendering (REQ-EINV-001) -> hybrid PDF embed (REQ-EINV-002; NL/Peppol
 * UBL, not Factur-X/ZUGFeRD CII — see REQ-EINV-008) -> store
 * the UBL XML and hybrid PDF in the administration's Files (REQ-SEIX-002) ->
 * save one `nl.conduction.peppol.outbound.requested` CloudEvent in integriq's
 * event register, which integriq's Peppol consumer reads (REQ-SEIX-001) ->
 * advance `ARInvoice.deliveryStatus` to `queued` (REQ-AR-011) only after that
 * save. Mirrors {@see \OCA\Shillinq\Service\PurchaseOrderService::sendToPeppol()}'s
 * shape (IDOR guard, graceful null-participant fallback). The log-only local
 * transmission port is not called on this path; `transmissionId` is written
 * when integriq's delivery status carries it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\EInvoice;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\IntegriqCloudEventPublisher;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrates ARInvoice e-invoice generation, validation and Peppol dispatch.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 */
final class EInvoiceService {
	/**
	 * CloudEvent type of the outbound request integriq's Peppol consumer reads
	 * (REQ-SEIX-001).
	 *
	 * @var string
	 */
	public const EVENT_OUTBOUND_REQUESTED = 'nl.conduction.peppol.outbound.requested';

	/**
	 * CloudEvent source of the outbound request.
	 *
	 * @var string
	 */
	public const EVENT_SOURCE = '/apps/shillinq/ar-invoices';

	/**
	 * Peppol document-type identifier for an outbound NLCIUS invoice.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE = 'ubl-invoice-2.1';

	/**
	 * The warning added when the invoice cannot be handed to integriq: integriq
	 * is not installed or the event could not be saved. The fallback flag
	 * offers email instead.
	 *
	 * @var array{field:string,code:string,message:string}
	 */
	public const UNAVAILABLE_WARNING = [
		'field' => 'transmission',
		'code' => 'peppol_unavailable',
		'message' => 'Peppol sending is not available on this instance',
	];

	/**
	 * The warning added when the e-invoice files could not be stored, so there
	 * is no payload to hand over.
	 *
	 * @var array{field:string,code:string,message:string}
	 */
	public const NOT_STORED_WARNING = [
		'field' => 'transmission',
		'code' => 'peppol_payload_not_stored',
		'message' => 'The e-invoice file could not be stored',
	];

	/**
	 * The payload reference the old send path recorded: a hash, not a stored file.
	 *
	 * @var string
	 */
	public const OLD_PATH_PAYLOAD_PREFIX = 'docudesk://';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param LoggerInterface $logger Logger (no PII/document bodies logged).
	 * @param ArInvoiceUblMapper $ublMapper NLCIUS UBL 2.1 mapper (REQ-EINV-001).
	 * @param InvoicePdfGenerator $pdfGenerator Hybrid PDF embed (REQ-EINV-002).
	 * @param EInvoiceValidationService $validationService Pre-send validation (REQ-EINV-003).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param EInvoiceArtefactStore $artefactStore Stores the UBL XML and hybrid PDF in Files (REQ-SEIX-002).
	 * @param IntegriqCloudEventPublisher $publisher Hands the request to integriq (REQ-SEIX-001).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ArInvoiceUblMapper $ublMapper,
		private readonly InvoicePdfGenerator $pdfGenerator,
		private readonly EInvoiceValidationService $validationService,
		private readonly ObjectServiceInterface $objectService,
		private readonly EInvoiceArtefactStore $artefactStore,
		private readonly IntegriqCloudEventPublisher $publisher,
	) {

	}//end __construct()

	/**
	 * Run the Send e-invoice flow for one ARInvoice (REQ-EINV-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $invoiceNumber ARInvoice.invoiceNumber to send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @throws RuntimeException When the invoice is missing/not accessible (IDOR-safe 404),
	 *                          not in the required `issued` lifecycle state, or pre-send
	 *                          validation fails (400 — no event is ever emitted on failure).
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function sendEInvoice(string $administrationId, string $invoiceNumber): array {
		$invoice    = $this->sendableInvoice(administrationId: $administrationId, invoiceNumber: $invoiceNumber);
		$validation = $this->validateForSend(administrationId: $administrationId, invoice: $invoice);
		if ($validation['peppolParticipantId'] === null) {
			// Graceful fallback (REQ-EINV-003 D2, mirrors the PO null-participant
			// contract): no event is emitted, deliveryStatus stays not-sent.
			return $this->fallbackResult(warnings: $validation['warnings']);
		}

		$send = new EInvoiceSend();
		$send->administrationId = $administrationId;
		$send->invoiceNumber    = $invoiceNumber;
		$send->invoice          = $invoice;
		$send->participantId    = $validation['peppolParticipantId'];
		$send->warnings         = $validation['warnings'];

		return $this->transmit(send: $send);

	}//end sendEInvoice()

	/**
	 * The invoices the old send path left at `queued` without handing them over.
	 *
	 * Before this change a send reserved a made-up transmission id from the
	 * log-only port and recorded a `docudesk://file/<hash>` reference that named
	 * no stored file, so nothing reached integriq. A `queued` invoice is one that
	 * was handed over only when its payload reference is a stored file; the rest
	 * are what `occ shillinq:einvoice:requeue` offers to send again.
	 *
	 * Operator use only: this reads every administration in the register.
	 *
	 * @return array<int,array<string,mixed>> The stuck invoices, in register order.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	public function queuedByTheOldPath(): array {
		$stuck = [];
		foreach ($this->findAll(schema: 'ARInvoice', filters: ['deliveryStatus' => 'queued']) as $invoice) {
			if (self::handedOver(invoice: $invoice) === false) {
				$stuck[] = $invoice;
			}
		}

		return $stuck;
	}//end queuedByTheOldPath()

	/**
	 * Send one invoice the old path left at `queued` again, through integriq.
	 *
	 * The same validation, storage and hand-off as {@see self::sendEInvoice()},
	 * without the per-user administration check: the caller is the operator's
	 * `occ` command, which has no user session. The made-up transmission id of
	 * the log-only port is cleared; integriq's status writes the real one.
	 *
	 * @param array<string,mixed> $invoice One record from {@see self::queuedByTheOldPath()}.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @throws RuntimeException When the record names no administration or invoice
	 *                          number, is not issued, was already handed over, or
	 *                          fails validation.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	public function requeue(array $invoice): array {
		$administrationId = trim((string)($invoice['administrationId'] ?? ''));
		$invoiceNumber    = trim((string)($invoice['invoiceNumber'] ?? ''));
		if ($administrationId === '' || $invoiceNumber === '') {
			throw new RuntimeException('The invoice names no administration or invoice number');
		}

		if ((string)($invoice['lifecycleState'] ?? '') !== ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE) {
			throw new RuntimeException(
				'ARInvoice must be in lifecycleState "' . ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE . '" to send an e-invoice'
			);
		}

		if ((string)($invoice['deliveryStatus'] ?? '') !== 'queued' || self::handedOver(invoice: $invoice) === true) {
			throw new RuntimeException('ARInvoice ' . $invoiceNumber . ' is not waiting from the old send path');
		}

		$invoice['transmissionId'] = null;

		$validation = $this->validateForSend(administrationId: $administrationId, invoice: $invoice);
		if ($validation['peppolParticipantId'] === null) {
			return $this->fallbackResult(warnings: $validation['warnings']);
		}

		$send = new EInvoiceSend();
		$send->administrationId = $administrationId;
		$send->invoiceNumber    = $invoiceNumber;
		$send->invoice          = $invoice;
		$send->participantId    = $validation['peppolParticipantId'];
		$send->warnings         = $validation['warnings'];

		return $this->transmit(send: $send);
	}//end requeue()

	/**
	 * Whether a queued invoice was handed over: its payload is a stored file.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return bool False for no reference or the old path's placeholder.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	private static function handedOver(array $invoice): bool {
		$uri = trim((string)($invoice['payloadFileUri'] ?? ''));

		return $uri !== '' && str_starts_with($uri, self::OLD_PATH_PAYLOAD_PREFIX) === false;
	}//end handedOver()

	/**
	 * The issued ARInvoice to send, once the tenant may be served.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $invoiceNumber    ARInvoice.invoiceNumber to send.
	 *
	 * @return array<string,mixed> The ARInvoice record.
	 *
	 * @throws RuntimeException When the administration is missing or inaccessible (masked as
	 *                          not found), the invoice is missing, or it is not issued.
	 */
	private function sendableInvoice(string $administrationId, string $invoiceNumber): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Mask as not-found per ADR-005 (avoid disclosing other tenants).
			throw new RuntimeException('ARInvoice not found');
		}

		$invoice = $this->findInvoice(administrationId: $administrationId, invoiceNumber: $invoiceNumber);
		if ($invoice === null) {
			throw new RuntimeException('ARInvoice not found');
		}

		if ((string)($invoice['lifecycleState'] ?? '') !== ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE) {
			throw new RuntimeException(
				'ARInvoice must be in lifecycleState "' . ArInvoiceUblMapper::REQUIRED_LIFECYCLE_STATE . '" to send an e-invoice'
			);
		}

		return $invoice;
	}//end sendableInvoice()

	/**
	 * The pre-send validation, refusing an invoice that fails it.
	 *
	 * @param string              $administrationId Administration scope.
	 * @param array<string,mixed> $invoice          The ARInvoice record.
	 *
	 * @return array<string,mixed> The validation outcome.
	 *
	 * @throws RuntimeException When validation fails (no event is ever emitted).
	 */
	private function validateForSend(string $administrationId, array $invoice): array {
		$validation = $this->validationService->validate(administrationId: $administrationId, arInvoice: $invoice);
		if ($validation['valid'] === false) {
			$messages = array_map(
				static fn (array $error): string => $error['message'],
				$validation['errors']
			);
			throw new RuntimeException('E-invoice validation failed: ' . implode('; ', $messages));
		}

		return $validation;
	}//end validateForSend()

	/**
	 * Hand the invoice to integriq: store the UBL XML and hybrid PDF, save the
	 * outbound request in integriq's event register, then queue the invoice.
	 * Without integriq, without stored files or when the save fails, the
	 * invoice stays not-sent and the send falls back to email.
	 *
	 * @param EInvoiceSend $send The validated send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	private function transmit(EInvoiceSend $send): array {
		if ($this->publisher->isAvailable() === false) {
			$this->logger->info(
				'EInvoiceService: integriq is not installed, the e-invoice is not sent',
				['invoiceNumber' => $send->invoiceNumber]
			);
			return $this->fallbackResult(warnings: array_merge($send->warnings, [self::UNAVAILABLE_WARNING]));
		}

		$ublXml = $this->ublMapper->toNlciusXml(arInvoice: $send->invoice);
		$hybrid = $this->pdfGenerator->generateHybridPdf(
			invoice: $send->invoice,
			lines: (array)($send->invoice['invoiceLines'] ?? []),
			ublXml: $ublXml
		);

		$stored = $this->artefactStore->store(
			administrationId: $send->administrationId,
			invoice: $send->invoice,
			pdf: $hybrid['pdf'],
			ublXml: $ublXml
		);
		if ($stored === null) {
			return $this->fallbackResult(warnings: array_merge($send->warnings, [self::NOT_STORED_WARNING]));
		}

		$send->payloadFileUri = $stored;

		if ($this->publishOutboundRequested(send: $send) === false) {
			return $this->fallbackResult(warnings: array_merge($send->warnings, [self::UNAVAILABLE_WARNING]));
		}

		return $this->queue(send: $send);
	}//end transmit()

	/**
	 * Persist the invoice as queued, after the hand-off was saved.
	 *
	 * @param EInvoiceSend $send The send that was handed over.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	private function queue(EInvoiceSend $send): array {
		$invoice = $send->invoice;
		$invoice['deliveryStatus']           = 'queued';
		$invoice['payloadFileUri']           = $send->payloadFileUri;
		$invoice['buyerPeppolParticipantId'] = $send->participantId;

		$this->saveObject(schema: 'ARInvoice', object: $invoice);

		return [
			'deliveryStatus' => 'queued',
			'transmissionId' => null,
			'payloadFileUri' => $send->payloadFileUri,
			'fallback' => false,
			'warnings' => $send->warnings,
		];
	}//end queue()

	/**
	 * The graceful-fallback result (not-sent, PDF + email path).
	 *
	 * @param array<int,array<string,string>> $warnings Non-blocking warnings.
	 *
	 * @return array{deliveryStatus:string,transmissionId:null,payloadFileUri:null,fallback:bool,warnings:array<int,array<string,string>>}
	 */
	private function fallbackResult(array $warnings): array {
		return [
			'deliveryStatus' => 'not-sent',
			'transmissionId' => null,
			'payloadFileUri' => null,
			'fallback' => true,
			'warnings' => $warnings,
		];
	}//end fallbackResult()

	/**
	 * Save the outbound request in integriq's event register, with the data
	 * envelope integriq's Peppol transmission reads.
	 *
	 * @param EInvoiceSend $send The send.
	 *
	 * @return bool True when the request was saved.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.2
	 */
	private function publishOutboundRequested(EInvoiceSend $send): bool {
		$invoice = $send->invoice;

		return $this->publisher->publish(
			type: self::EVENT_OUTBOUND_REQUESTED,
			source: self::EVENT_SOURCE,
			subject: (string)($invoice['uuid'] ?? ($invoice['@self']['id'] ?? ($invoice['id'] ?? $send->invoiceNumber))),
			data: [
				'sourceApp' => 'shillinq',
				'objectType' => 'ar-invoice',
				'objectUri' => $this->objectUri(invoice: $invoice),
				'recipientPeppolId' => $send->participantId,
				'documentType' => self::DOCUMENT_TYPE,
				'payloadFileUri' => $send->payloadFileUri,
				'administrationId' => $send->administrationId,
			]
		);

	}//end publishOutboundRequested()

	/**
	 * Build the canonical object URI carried on outbound/delivery-status events.
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record.
	 *
	 * @return string
	 */
	private function objectUri(array $invoice): string {
		$id = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		if ($id === '') {
			$id = (string)($invoice['invoiceNumber'] ?? '');
		}

		return 'openregister://' . $this->register() . '/ARInvoice/' . $id;
	}//end objectUri()

	/**
	 * Fetch one ARInvoice by invoiceNumber, scoped to the administration.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceNumber ARInvoice.invoiceNumber.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findInvoice(string $administrationId, string $invoiceNumber): ?array {
		$rows = $this->findAll(
			schema: 'ARInvoice',
			filters: [
				'administrationId' => $administrationId,
				'invoiceNumber' => $invoiceNumber,
			]
		);

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findInvoice()

	/**
	 * Persist an object via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object payload.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->error(
				'EInvoiceService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch all matching records via the real ObjectService API.
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'EInvoiceService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		// OpenRegister's findAll() answers ObjectEntity instances, not arrays
		// (RenderObject::renderEntities()); keeping only arrays dropped every row
		// a live instance returned, as in PeppolDeliveryStatusListener (#1754).
		$result = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$result[] = $record;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
