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
 * the artefact -> Peppol transmission port submit -> emit
 * `nl.conduction.peppol.outbound.requested` -> advance `ARInvoice.deliveryStatus`
 * to `queued` (REQ-AR-011). Mirrors {@see \OCA\Shillinq\Service\PurchaseOrderService::sendToPeppol()}'s
 * shape (IDOR guard, graceful null-participant fallback) but stays event-only
 * for the actual transmission trigger (ADR-022) — the emitted event is what
 * openconnector's Peppol access point acts on; the local
 * {@see \OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface::submit()}
 * call only reserves a provisional `transmissionId` so the ARInvoice carries
 * one immediately (design.md "Synchronous send vs. queued" trade-off).
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
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\Peppol\PeppolTransmissionPortInterface;
use OCP\EventDispatcher\GenericEvent;
use OCP\EventDispatcher\IEventDispatcher;
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
	 * Cross-app cloud-event name emitted on a successful pre-send validation
	 * (REQ-EINV-005). Consumed by openconnector's Peppol access point.
	 *
	 * @var string
	 */
	public const EVENT_OUTBOUND_REQUESTED = 'nl.conduction.peppol.outbound.requested';

	/**
	 * Peppol document-type identifier for an outbound NLCIUS invoice.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE = 'ubl-invoice-2.1';

	/**
	 * The warning added when the Peppol submit fails.
	 *
	 * @var array{field:string,code:string,message:string}
	 */
	private const SUBMIT_FAILED_WARNING = [
		'field' => 'transmission',
		'code' => 'peppol_send_failed',
		'message' => 'Peppol transmission failed — falling back to PDF + email',
	];

	/**
	 * Peppol transmission port (generalised, REQ-EINV-004). Defaults to the
	 * Log adapter so dev/CI works without openconnector.
	 *
	 * @var PeppolTransmissionPortInterface
	 */
	private readonly PeppolTransmissionPortInterface $peppolPort;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param LoggerInterface $logger Logger (no PII/document bodies logged).
	 * @param IEventDispatcher $eventDispatcher NC event dispatcher (cross-app transport).
	 * @param ArInvoiceUblMapper $ublMapper NLCIUS UBL 2.1 mapper (REQ-EINV-001).
	 * @param InvoicePdfGenerator $pdfGenerator Hybrid PDF embed (REQ-EINV-002).
	 * @param EInvoiceValidationService $validationService Pre-send validation (REQ-EINV-003).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param PeppolTransmissionPortInterface|null $peppolPort Optional transmission port; defaults to
	 *                                                         {@see LogPeppolTransmissionAdapter}.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ArInvoiceUblMapper $ublMapper,
		private readonly InvoicePdfGenerator $pdfGenerator,
		private readonly EInvoiceValidationService $validationService,
		private readonly ObjectServiceInterface $objectService,
		?PeppolTransmissionPortInterface $peppolPort = null,
	) {
		// ADR-084: the adapter takes the ObjectService contract now, not the DI
		// container. This site still passed `container: $container` — a parameter
		// this constructor does not declare — so every EInvoiceService built
		// without an explicit $peppolPort fataled at REQUEST time.
		$this->peppolPort = ($peppolPort ?? new LogPeppolTransmissionAdapter(
			objectService: $objectService,
			appConfig: $appConfig,
			logger: $logger
		));

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
	 * Store the hybrid PDF and submit it over Peppol; a failed submit falls
	 * back to PDF + email and leaves the invoice not-sent.
	 *
	 * @param EInvoiceSend $send The validated send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 */
	private function transmit(EInvoiceSend $send): array {
		$ublXml = $this->ublMapper->toNlciusXml(arInvoice: $send->invoice);
		$hybrid = $this->pdfGenerator->generateHybridPdf(
			invoice: $send->invoice,
			lines: (array)($send->invoice['invoiceLines'] ?? []),
			ublXml: $ublXml
		);
		$send->payloadFileUri = $this->storeArtefact(invoice: $send->invoice, hybrid: $hybrid);

		try {
			$send->transmissionId = $this->peppolPort->submit(
				participantId: $send->participantId,
				documentType: self::DOCUMENT_TYPE,
				payloadFileUri: $send->payloadFileUri
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'EInvoiceService: Peppol submit failed — invoice stays not-sent',
				['invoiceNumber' => $send->invoiceNumber, 'exception' => $e->getMessage()]
			);
			return $this->fallbackResult(warnings: array_merge($send->warnings, [self::SUBMIT_FAILED_WARNING]));
		}

		return $this->queue(send: $send);
	}//end transmit()

	/**
	 * Save the invoice as queued with its provenance and emit the
	 * outbound-requested event.
	 *
	 * @param EInvoiceSend $send The submitted send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 */
	private function queue(EInvoiceSend $send): array {
		$invoice = $send->invoice;
		$invoice['deliveryStatus']           = 'queued';
		$invoice['transmissionId']           = $send->transmissionId;
		$invoice['payloadFileUri']           = $send->payloadFileUri;
		$invoice['buyerPeppolParticipantId'] = $send->participantId;

		$persisted = $this->saveObject(schema: 'ARInvoice', object: $invoice);
		$this->emitOutboundRequested(send: $send, invoice: $persisted);

		return [
			'deliveryStatus' => 'queued',
			'transmissionId' => $send->transmissionId,
			'payloadFileUri' => $send->payloadFileUri,
			'fallback' => false,
			'warnings' => $send->warnings,
		];
	}//end queue()

	/**
	 * The not-sent answer that falls back to PDF + email.
	 *
	 * @param array<int,array<string,string>> $warnings The warnings to report.
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
	 * Emit the `nl.conduction.peppol.outbound.requested` cloud event (REQ-EINV-005).
	 *
	 * Fail-soft: a dispatcher error is logged but never bubbles up to the
	 * caller — the ARInvoice has already been persisted at `queued`, which is
	 * the source of truth; the event is a derived cross-app notification.
	 *
	 * @param EInvoiceSend        $send    The submitted send.
	 * @param array<string,mixed> $invoice The persisted ARInvoice record.
	 *
	 * @return void
	 */
	private function emitOutboundRequested(EInvoiceSend $send, array $invoice): void {
		$payload = [
			'sourceApp' => 'shillinq',
			'objectType' => 'ar-invoice',
			'objectUri' => $this->objectUri(invoice: $invoice),
			'recipientPeppolId' => $send->participantId,
			'documentType' => self::DOCUMENT_TYPE,
			'payloadFileUri' => $send->payloadFileUri,
			'administrationId' => $send->administrationId,
		];

		try {
			$event = new GenericEvent(null, $payload);
			$this->eventDispatcher->dispatch(self::EVENT_OUTBOUND_REQUESTED, $event);
		} catch (Throwable $e) {
			$this->logger->info(
				'EInvoiceService: outbound-requested dispatch failed — fail-soft',
				['exception' => $e->getMessage()]
			);
		}

	}//end emitOutboundRequested()

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
	 * Best-effort hand-off of the hybrid PDF artefact to Docudesk/Files
	 * storage. No live docudesk integration exists in this fleet yet (mirrors
	 * {@see \OCA\Shillinq\Service\AuditExportService}'s archival hand-off
	 * pattern): the intent is logged and a deterministic placeholder URI is
	 * returned so `ARInvoice.payloadFileUri` is always a stable FK-shaped
	 * reference (never inline XML/PDF bytes, REQ-EINV-006).
	 *
	 * @param array<string,mixed> $invoice Invoice record.
	 * @param array{filename:string,pdf:string,mimeType:string,embeddedXmlFilename:string} $hybrid Hybrid PDF payload.
	 *
	 * @return string The stored artefact URI.
	 */
	private function storeArtefact(array $invoice, array $hybrid): string {
		$reference = substr(hash('sha256', $hybrid['pdf']), 0, 16);

		try {
			$this->logger->info(
				'EInvoiceService: hybrid PDF artefact ready for docudesk hand-off',
				[
					'invoiceNumber' => (string)($invoice['invoiceNumber'] ?? ''),
					'filename' => $hybrid['filename'],
					'bytes' => strlen($hybrid['pdf']),
					'reference' => $reference,
				]
			);
		} catch (Throwable $e) {
			// Logger failure is non-fatal — the artefact reference is still returned.
		}

		return 'docudesk://file/' . $reference;
	}//end storeArtefact()

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

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
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
