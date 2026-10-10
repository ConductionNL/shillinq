<?php

/**
 * Purchase Order Service
 *
 * Server-authoritative create / approval-chain routing / send-block guard for the
 * 3-way-match Purchase Order sub-ledger (REQ-PO3W-001). Implements member 02 of
 * the bookkeeping-purchase-order-3way chain: the schemas + registers were declared
 * in member 01; this service uses them via OpenRegister's real ObjectService API
 * (find / findAll / saveObject — the methods findObject / createFromArray /
 * deleteFromId DO NOT exist and are never used, ADR-022). Every read/write is
 * scoped to the caller's administrationId, validated by
 * AdministrationContextService (ADR-005, ADR-031 IDOR-safe).
 *
 * Monetary arithmetic is integer-cent only (multipleOf 0.01 on the schema fields
 * declared by slice 01); see toCents/fromCents helpers. Approval is not this
 * service's: the PurchaseOrder schema declares an OpenRegister approval chain on
 * its `approve` transition (purchasing-approval-delegation REQ-PAD-001), which
 * routes the steps to the teamleider, facility_manager and procurement_manager
 * groups and notifies them. The PO cannot be sent until its statusCode is
 * `approved`.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 02 of bookkeeping-purchase-order-3way: PO creation and transmission.
 *
 * Public methods:
 * - createPurchaseOrder(): validates requester + cost-center budget, generates a
 *   CBS-conform po_number server-side and persists the PurchaseOrder with
 *   statusCode "draft" and totalExclVat in cents for the declared approval chain.
 * - markSent(), sendToPeppol(), sendToPDFEmail(): refuse unless the order is
 *   approved, then move it to "sent".
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 * Pre-existing debt (issue #506): broad domain surface area; variable
 * renames deferred pending a dedicated pass.
 */
class PurchaseOrderService {
	/**
	 * Peppol transmission adapter (port). Resolved at construction; defaults to the
	 * log adapter so slice 02 callers keep working without binding the new port.
	 *
	 * @var PeppolTransmissionAdapterInterface
	 */
	private readonly PeppolTransmissionAdapterInterface $peppolAdapter;

	/**
	 * PDF + email transmission mailer (port). Default binding logs the dispatch
	 * attempt; production deployments swap it for an SMTP-backed implementation.
	 *
	 * @var PurchaseOrderMailerInterface
	 */
	private readonly PurchaseOrderMailerInterface $purchaseOrderMailer;

	/**
	 * Pure PO → UBL Order document mapper.
	 *
	 * @var PeppolBisOrderMapper
	 */
	private readonly PeppolBisOrderMapper $peppolMapper;

	/**
	 * Supplier-qualification gate (procurement-governance). Blocks a PO to an
	 * unqualified supplier when the require_supplier_qualification_for_po policy
	 * is on. Resolved at construction; defaults to a self-constructed instance so
	 * existing callers keep working (default-inert while the policy is off).
	 *
	 * @var SupplierQualificationGuard
	 */
	private readonly SupplierQualificationGuard $supplierQualificationGuard;

	/**
	 * Framework-agreement ceiling gate (procurement-governance). Blocks a PO
	 * call-off that would exceed the agreement ceiling; only engaged when the
	 * payload carries a frameworkAgreementId. Resolved at construction.
	 *
	 * @var FrameworkAgreementDrawdownGuard
	 */
	private readonly FrameworkAgreementDrawdownGuard $frameworkAgreementDrawdownGuard;

	/**
	 * Constructor.
	 *
	 *                                      ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param PeppolTransmissionAdapterInterface|null $peppolAdapter Optional Peppol port (slice 03);
	 *                                                               defaults to
	 *                                                               LogPeppolTransmissionAdapter.
	 * @param PurchaseOrderMailerInterface|null $purchaseOrderMailer Optional PDF+email mailer (slice 03);
	 *                                                               defaults to LogPurchaseOrderMailer.
	 * @param PeppolBisOrderMapper|null $peppolMapper Optional UBL mapper (slice 03);
	 *                                                defaults to a fresh instance.
	 * @param SupplierQualificationGuard|null $supplierQualificationGuard Optional supplier-qualification
	 *                                                                    gate (procurement-governance); defaults
	 *                                                                    to a self-constructed instance.
	 * @param FrameworkAgreementDrawdownGuard|null $frameworkAgreementDrawdownGuard Optional framework-agreement
	 *                                                                              ceiling gate (procurement-governance);
	 *                                                                              defaults to a self-constructed instance.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		?PeppolTransmissionAdapterInterface $peppolAdapter = null,
		?PurchaseOrderMailerInterface $purchaseOrderMailer = null,
		?PeppolBisOrderMapper $peppolMapper = null,
		?SupplierQualificationGuard $supplierQualificationGuard = null,
		?FrameworkAgreementDrawdownGuard $frameworkAgreementDrawdownGuard = null,
	) {
		// ADR-084: these three collaborators used to be handed the DI container so
		// they could resolve OpenRegister's ObjectService lazily. They now take the
		// contract directly, so the container argument was removed from all three —
		// but this composition site kept passing `container: $container`, a
		// parameter this constructor no longer declares. An undefined variable is
		// null, so every PurchaseOrderService built without an explicit
		// $peppolAdapter/$supplierQualificationGuard/$frameworkAgreementDrawdownGuard
		// died here at REQUEST time (Error: Unknown named parameter $container /
		// TypeError: $container must be of type ContainerInterface, null given),
		// taking every /api/purchase-orders* route with it.
		$this->peppolAdapter = ($peppolAdapter ?? new LogPeppolTransmissionAdapter(
			objectService: $objectService,
			appConfig: $appConfig,
			logger: $logger
		));
		$this->purchaseOrderMailer = ($purchaseOrderMailer ?? new LogPurchaseOrderMailer(logger: $logger));
		$this->peppolMapper = ($peppolMapper ?? new PeppolBisOrderMapper());
		$this->supplierQualificationGuard = ($supplierQualificationGuard ?? new SupplierQualificationGuard(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $objectService
		));
		$this->frameworkAgreementDrawdownGuard = ($frameworkAgreementDrawdownGuard ?? new FrameworkAgreementDrawdownGuard(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $objectService
		));
	}//end __construct()

	/**
	 * Create a purchase order with a materialised approval chain (REQ-PO3W-001).
	 *
	 * Server-authoritative:
	 *  - the requesterId is derived from the validated administration membership;
	 *    it is never trusted from the request body (ADR-005);
	 *  - the po_number is generated server-side using a CBS-conform sequence
	 *    (PO-{year}-{administrationCode}-{6-digit-sequence});
	 *  - cost-center budget is checked against the CostCenter record;
	 *  - statusCode starts at "draft", the schema's initial state. Approval is
	 *    not this service's: submitting the order attempts its `approve`
	 *    transition, and OpenRegister's declared approval chain opens the
	 *    steps, routes them to the approver groups and notifies them
	 *    (purchasing-approval-delegation REQ-PAD-001);
	 *  - totalExclVat is written in integer cents, the chain's amountField.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (supplierId, costCenter,
	 *                                     projectCode, lines, etc.). Lines are
	 *                                     accepted as a flat array of
	 *                                     {productCode, quantity, unitPrice,
	 *                                     vatRate, glAccount, lineNumber?} entries.
	 *
	 * @return array<string,mixed> The persisted PurchaseOrder payload.
	 *
	 * @throws \RuntimeException When the requester lacks access, the cost-center
	 *                           budget is exceeded, or a required field is missing.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 */
	public function createPurchaseOrder(string $administrationId, array $payload): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			// Mask as not-found per ADR-005 (avoid disclosing other tenants).
			throw new RuntimeException('Administration not found');
		}

		$requesterId = (string)$this->administrationContext->currentUserId();
		if ($requesterId === '') {
			throw new RuntimeException('Authenticated requester is required');
		}

		$supplierId = trim((string)($payload['supplierId'] ?? ''));
		if ($supplierId === '') {
			throw new RuntimeException('supplierId is required');
		}

		$costCenter = trim((string)($payload['costCenter'] ?? ''));
		$projectCode = trim((string)($payload['projectCode'] ?? ''));
		if ($costCenter === '') {
			throw new RuntimeException('costCenter is required');
		}

		$lines = $this->normaliseLines(rawLines: (array)($payload['lines'] ?? []));
		$totalCent = $this->totalCents(lines: $lines);
		if ($totalCent <= 0) {
			throw new RuntimeException('Purchase order total must be positive');
		}

		$totalAmount = $this->fromCents(cents: $totalCent);
		$this->assertCostCenterBudget(
			administrationId: $administrationId,
			costCenter: $costCenter,
			addCents: $totalCent
		);

		$requisitionId = trim((string)($payload['requisitionId'] ?? ''));
		$this->assertRequisitionPolicy(administrationId: $administrationId, requisitionId: $requisitionId);

		// Procurement-governance gate (REQ-PG-002): block a PO to an unqualified
		// supplier when the require_supplier_qualification_for_po policy is on.
		$this->assertSupplierQualificationPolicy(administrationId: $administrationId, supplierId: $supplierId);

		// Procurement-governance gate (REQ-PG-004): when the PO is a call-off
		// against a framework agreement, block it if it exceeds the remaining
		// ceiling. The resolved agreement is drawn down after the PO persists.
		$frameworkAgreement = null;
		$frameworkAgreementId = trim((string)($payload['frameworkAgreementId'] ?? ''));
		if ($frameworkAgreementId !== '') {
			$frameworkAgreement = $this->frameworkAgreementDrawdownGuard->assertWithinCeiling(
				administrationId: $administrationId,
				frameworkAgreementId: $frameworkAgreementId,
				addCents: $totalCent
			);
		}

		$poNumber = $this->generatePoNumber(administrationId: $administrationId);

		$purchaseOrder = [
			'poNumber' => $poNumber,
			'administrationId' => $administrationId,
			'supplierId' => $supplierId,
			'requesterId' => $requesterId,
			'costCenter' => $costCenter,
			'projectCode' => $projectCode,
			'requisitionId' => $requisitionId,
			'frameworkAgreementId' => $frameworkAgreementId,
			'lines' => $lines,
			'totalAmount' => $totalAmount,
			// The declared approval chain's amountField (integer cents);
			// OpenRegister resolves the approver tiers from it.
			'totalExclVat' => $totalCent,
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			// The schema's lifecycle field (#1753). The order stays in `draft`
			// until OpenRegister's approval chain releases its `approve` transition.
			'statusCode' => 'draft',
			'createdAt' => $this->nowIso(),
			'notes' => trim((string)($payload['notes'] ?? '')),
		];

		$persisted = $this->saveObject(schema: 'PurchaseOrder', object: $purchaseOrder);

		// Record the framework-agreement call-off drawdown now the PO is persisted
		// (REQ-PG-004). The guard already verified this fits the remaining ceiling.
		if ($frameworkAgreement !== null) {
			$frameworkAgreement['drawnAmount'] = ((int)($frameworkAgreement['drawnAmount'] ?? 0) + $totalCent);
			$this->saveObject(schema: 'FrameworkAgreement', object: $frameworkAgreement);
		}

		return $persisted;
	}//end createPurchaseOrder()

	/**
	 * Transmit an approved PO to the supplier via Peppol BIS Ordering 3.0.
	 *
	 * Slice 03 surface. The method enforces the slice-02 approval-complete
	 * precondition (re-using the same approved check as markSent so
	 * the guard stays single-sourced), resolves the supplier's Peppol participant
	 * id via the adapter port, transforms the PO into a UBL 2.1 Order document
	 * via PeppolBisOrderMapper, submits the document to the Peppol Access Point,
	 * and persists `peppolMessageId` + `peppolSentAt` + `statusCode=sent`
	 * on the PurchaseOrder record (REQ-PO3W-002).
	 *
	 * Graceful fallback (REQ-PO3W-002 D2): when the supplier is not a Peppol
	 * participant the method automatically delegates to {@see sendToPDFEmail}
	 * with a `supplier_not_peppol_participant` reason — the PO is never silently
	 * un-transmitted. When the Peppol Access Point itself fails the fallback
	 * also fires with reason `peppol_send_failed`.
	 *
	 * Server-authoritative: the controller cannot forge `peppolMessageId` or
	 * bypass the approval-state precondition (ADR-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing or not approved
	 *                           (mapped to 404 / 409 by the controller).
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 */
	public function sendToPeppol(string $administrationId, string $purchaseOrderId): array {
		$po = $this->loadPurchaseOrderForTransmission(
			administrationId: $administrationId,
			purchaseOrderId: $purchaseOrderId
		);
		$supplierId = (string)($po['supplierId'] ?? '');
		// The generalised port (REQ-EINV-004) names the parameter partyId —
		// it resolves suppliers (PO) and debtors (AR) through the same lookup.
		$participant = $this->peppolAdapter->lookupParticipant(
			administrationId: $administrationId,
			partyId: $supplierId
		);

		// No Peppol participant id = graceful PDF + email fallback (REQ-PO3W-002 D2).
		if ($participant === null || trim($participant) === '') {
			return $this->sendToPDFEmail(
				administrationId: $administrationId,
				purchaseOrderId: $purchaseOrderId,
				fallbackReason: 'supplier_not_peppol_participant'
			);
		}

		$ubl = $this->peppolMapper->toUblOrderXml(
			purchaseOrder: $po,
			buyerParticipantId: $this->buyerParticipantId(administrationId: $administrationId),
			supplierParticipantId: $participant,
			issueDate: substr($this->nowIso(), 0, 10)
		);

		try {
			$messageId = $this->peppolAdapter->submitOrder(
				participantId: $participant,
				ublOrderXml: $ubl
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PurchaseOrderService: Peppol submission failed, falling back to PDF+email',
				[
					'purchaseOrderId' => $purchaseOrderId,
					'exception' => $e->getMessage(),
				]
			);
			return $this->sendToPDFEmail(
				administrationId: $administrationId,
				purchaseOrderId: $purchaseOrderId,
				fallbackReason: 'peppol_send_failed'
			);
		}

		$po['peppolMessageId'] = $messageId;
		$po['peppolSentAt'] = $this->nowIso();
		$po['peppolFallbackReason'] = null;
		$po['statusCode'] = 'sent';
		$po['sentAt'] = $this->nowIso();

		return $this->saveObject(schema: 'PurchaseOrder', object: $po);
	}//end sendToPeppol()

	/**
	 * Transmit an approved PO via the PDF + email fallback path.
	 *
	 * Slice 03 surface. Used directly when the operator selects "PDF + email" on
	 * the PO form, and used indirectly by {@see sendToPeppol} when the supplier
	 * is not Peppol-registered or the Access Point fails. The method enforces
	 * the slice-02 approval-complete precondition, delegates the actual
	 * dispatch to the mailer port, persists `peppolFallbackReason` (the audit
	 * trail of why Peppol was not used) and transitions the PO to `sent`
	 * (REQ-PO3W-002 D2 — graceful fallback, never silent).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id.
	 * @param string $fallbackReason The reason the fallback was used (audit
	 *                               trail); empty string defaults to
	 *                               `manual_pdf_email_fallback`.
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing, is not approved,
	 *                           or the mailer cannot dispatch.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
	 */
	public function sendToPDFEmail(
		string $administrationId,
		string $purchaseOrderId,
		string $fallbackReason = '',
	): array {
		$po = $this->loadPurchaseOrderForTransmission(
			administrationId: $administrationId,
			purchaseOrderId: $purchaseOrderId
		);
		$reason = trim($fallbackReason);
		if ($reason === '') {
			$reason = 'manual_pdf_email_fallback';
		}

		$this->purchaseOrderMailer->sendPurchaseOrderEmail(
			administrationId: $administrationId,
			purchaseOrder: $po
		);

		$po['peppolFallbackReason'] = $reason;
		$po['statusCode'] = 'sent';
		$po['sentAt'] = $this->nowIso();

		return $this->saveObject(schema: 'PurchaseOrder', object: $po);
	}//end sendToPDFEmail()

	/**
	 * Load a PurchaseOrder for transmission and enforce the approval-complete
	 * precondition (REQ-PO3W-002 — reuses the slice-02 send-block guard).
	 *
	 * Centralises the IDOR + approval-chain checks so {@see sendToPeppol} and
	 * {@see sendToPDFEmail} cannot diverge — neither path can ever skip the
	 * approval gate (ADR-005 fail-closed).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $purchaseOrderId PO id.
	 *
	 * @return array<string,mixed> The persisted PurchaseOrder record.
	 *
	 * @throws \RuntimeException When the PO is missing or not approved.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	private function loadPurchaseOrderForTransmission(
		string $administrationId,
		string $purchaseOrderId,
	): array {
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Purchase order not found');
		}

		$po = $this->findOne(
			schema: 'PurchaseOrder',
			filters: [
				'id' => $purchaseOrderId,
				'administrationId' => $administrationId,
			]
		);
		if ($po === null) {
			throw new RuntimeException('Purchase order not found');
		}

		// OpenRegister's approval chain is the only way into `approved`
		// (REQ-PAD-001); the in-object chain is history and is not read.
		if ((string)($po['statusCode'] ?? '') !== 'approved') {
			throw new RuntimeException('Purchase order cannot be sent: it is not approved');
		}

		return $po;
	}//end loadPurchaseOrderForTransmission()

	/**
	 * Mark an approved purchase order as sent, without transmitting it.
	 *
	 * The send endpoint's path for an order handed over outside shillinq.
	 * Refused unless OpenRegister's approval chain approved the order
	 * (statusCode `approved`), with the same check the transmit paths use.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id.
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing (404) or not approved (409).
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function markSent(string $administrationId, string $purchaseOrderId): array {
		$po = $this->loadPurchaseOrderForTransmission(
			administrationId: $administrationId,
			purchaseOrderId: $purchaseOrderId
		);

		$po['statusCode'] = 'sent';
		$po['sentAt'] = $this->nowIso();

		return $this->saveObject(schema: 'PurchaseOrder', object: $po);
	}//end markSent()

	/**
	 * Resolve the buyer's Peppol participant id from the administration record.
	 *
	 * Reads `peppolParticipantId` from the matching Administration record when
	 * the schema is present. Defaults to a Dutch KvK scheme placeholder
	 * (`0106:00000000`) so the UBL document still validates structurally when
	 * the field is absent in dev.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return string The buyer Peppol participant id.
	 */
	private function buyerParticipantId(string $administrationId): string {
		$record = $this->findOne(
			schema: 'Administration',
			filters: ['id' => $administrationId]
		);
		if ($record !== null) {
			$value = trim((string)($record['peppolParticipantId'] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '0106:00000000';
	}//end buyerParticipantId()

	/**
	 * Normalise + validate the line items in the request payload.
	 *
	 * Each line carries productCode, quantity, unitPrice, vatRate (as a fraction,
	 * e.g. 0.21), glAccount and an auto-numbered lineNumber when the caller did
	 * not supply one. Monetary fields (unitPrice, lineTotal, vatAmount) are
	 * computed in integer cents and presented back as floats with multipleOf 0.01.
	 *
	 * @param array<int,mixed> $rawLines Raw line entries from the caller.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \RuntimeException When a line is malformed.
	 */
	private function normaliseLines(array $rawLines): array {
		if ($rawLines === []) {
			throw new RuntimeException('Purchase order must have at least one line');
		}

		$lines = [];
		$lineNumber = 0;
		foreach ($rawLines as $raw) {
			if (is_array($raw) === false) {
				throw new RuntimeException('Line item must be an object');
			}

			$lineNumber++;
			$productCode = trim((string)($raw['productCode'] ?? ''));
			$glAccount = trim((string)($raw['glAccount'] ?? ''));
			$quantity = (float)($raw['quantity'] ?? 0);
			$unitPrice = (float)($raw['unitPrice'] ?? 0);
			$vatRate = (float)($raw['vatRate'] ?? 0);

			if ($productCode === '') {
				throw new RuntimeException('Line ' . $lineNumber . ' is missing productCode');
			}

			if ($glAccount === '') {
				throw new RuntimeException('Line ' . $lineNumber . ' is missing glAccount');
			}

			if ($quantity <= 0.0) {
				throw new RuntimeException('Line ' . $lineNumber . ' must have positive quantity');
			}

			if ($unitPrice < 0.0) {
				throw new RuntimeException('Line ' . $lineNumber . ' must have non-negative unitPrice');
			}

			if ($vatRate < 0.0 || $vatRate > 1.0) {
				throw new RuntimeException('Line ' . $lineNumber . ' vatRate must be a fraction between 0 and 1');
			}

			$unitCents = $this->toCents(amount: $unitPrice);
			$lineCents = (int)round(($unitCents * $quantity), 0, PHP_ROUND_HALF_UP);
			$vatCents = (int)round(($lineCents * $vatRate), 0, PHP_ROUND_HALF_UP);

			$lines[] = [
				'lineNumber' => ((int)($raw['lineNumber'] ?? $lineNumber)),
				'productCode' => $productCode,
				'quantity' => $quantity,
				'unitPrice' => $this->fromCents(cents: $unitCents),
				'lineTotal' => $this->fromCents(cents: $lineCents),
				'vatRate' => $vatRate,
				'vatAmount' => $this->fromCents(cents: $vatCents),
				'glAccount' => $glAccount,
			];
		}//end foreach

		return $lines;
	}//end normaliseLines()

	/**
	 * Sum the lineTotal of every line as integer cents.
	 *
	 * @param array<int,array<string,mixed>> $lines Normalised lines.
	 *
	 * @return int Total in cents.
	 */
	private function totalCents(array $lines): int {
		$total = 0;
		foreach ($lines as $line) {
			$total += $this->toCents(amount: (float)($line['lineTotal'] ?? 0));
		}

		return $total;
	}//end totalCents()

	/**
	 * Assert the cost-center's remaining budget covers the PO total.
	 *
	 * Looks up the CostCenter record for the administration; raises a runtime
	 * exception when budgetRemaining < addAmount. When the record is missing the
	 * check is treated as advisory (CostCenter is optional per slice 01) and
	 * passes silently — this keeps the gate strict only when budgets ARE
	 * configured, while not blocking customers without a CostCenter register.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $costCenter The costCenter code to look up.
	 * @param int $addCents The PO total to consume.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the budget would be exceeded.
	 */
	private function assertCostCenterBudget(string $administrationId, string $costCenter, int $addCents): void {
		$record = $this->findOne(
			schema: 'CostCenter',
			filters: [
				'administrationId' => $administrationId,
				'code' => $costCenter,
			]
		);
		if ($record === null) {
			return;
		}

		if (array_key_exists('budgetRemaining', $record) === false || $record['budgetRemaining'] === null) {
			return;
		}

		$remainingCents = $this->toCents(amount: (float)$record['budgetRemaining']);
		if ($remainingCents < $addCents) {
			throw new RuntimeException('Cost center budget exceeded for ' . $costCenter);
		}

	}//end assertCostCenterBudget()

	/**
	 * Policy gate: when enabled, refuse to create a PurchaseOrder unless it
	 * traces back to an approved (or already-converted) Requisition
	 * (purchase-requisition change, REQ-REQ-006). Defaults OFF via the
	 * `require_approved_requisition_for_po` app-config flag so existing PO
	 * creation flows — which never reference a requisition — keep working
	 * unchanged. When the flag is ON: a blank requisitionId is refused, and a
	 * non-blank requisitionId must resolve to a Requisition in this
	 * administration whose statusCode is 'approved' or 'converted'.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $requisitionId Requisition id from the payload (may be blank).
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the policy is enabled and the requisition
	 *                           is missing, blank, or not approved/converted.
	 */
	private function assertRequisitionPolicy(string $administrationId, string $requisitionId): void {
		$required = $this->appConfig->getValueString(
			Application::APP_ID,
			'require_approved_requisition_for_po',
			'false'
		);
		if ($required !== 'true') {
			return;
		}

		if ($requisitionId === '') {
			throw new RuntimeException('A purchase order requires an approved requisition');
		}

		$requisition = $this->findOne(
			schema: 'Requisition',
			filters: [
				'id' => $requisitionId,
				'administrationId' => $administrationId,
			]
		);

		if ($requisition === null) {
			throw new RuntimeException('Purchase order requires an approved requisition');
		}

		$status = (string)($requisition['statusCode'] ?? '');
		if (in_array($status, ['approved', 'converted'], true) === false) {
			throw new RuntimeException('Purchase order requires an approved requisition');
		}

	}//end assertRequisitionPolicy()

	/**
	 * Policy gate (procurement-governance, REQ-PG-002): when the
	 * `require_supplier_qualification_for_po` app-config flag is enabled, refuse
	 * to create a PurchaseOrder for a supplier that is not qualified — no
	 * `qualified` SupplierQualification, or a required document that is missing or
	 * expired. Defaults OFF so existing PO flows keep working unchanged. Delegates
	 * to the reused, unmodified SupplierQualificationGuard (fail-closed).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId Supplier reference from the payload.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the policy is enabled and the supplier is not qualified.
	 */
	private function assertSupplierQualificationPolicy(string $administrationId, string $supplierId): void {
		$required = $this->appConfig->getValueString(
			Application::APP_ID,
			'require_supplier_qualification_for_po',
			'false'
		);
		if ($required !== 'true') {
			return;
		}

		$this->supplierQualificationGuard->assertQualifiedForPo(
			administrationId: $administrationId,
			supplierId: $supplierId
		);

	}//end assertSupplierQualificationPolicy()

	/**
	 * Generate a CBS-conform PO number for the administration.
	 *
	 * Format: PO-{year}-{administrationCode}-{6-digit-sequence}. The sequence is
	 * the count of PurchaseOrder records for the administration in the current
	 * year plus one, zero-padded to six digits. Race conditions across concurrent
	 * requests are tolerated at this layer (the PO id remains unique via the OR
	 * record id); the displayable po_number is best-effort sequential.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return string
	 */
	private function generatePoNumber(string $administrationId): string {
		$year = (int)date('Y');

		$existing = $this->findAll(
			schema: 'PurchaseOrder',
			filters: ['administrationId' => $administrationId]
		);

		$thisYear = 0;
		foreach ($existing as $row) {
			$created = (string)($row['createdAt'] ?? '');
			if ($created !== '' && (int)substr($created, 0, 4) === $year) {
				$thisYear++;
			}
		}

		$sequence = str_pad((string)($thisYear + 1), 6, '0', STR_PAD_LEFT);

		return sprintf('PO-%d-%s-%s', $year, $administrationId, $sequence);
	}//end generatePoNumber()

	/**
	 * Persist an object via OpenRegister's real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object The object to persist.
	 *
	 * @return array<string,mixed> The persisted record (id stamped by OR).
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
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all matching records via the real ObjectService API (findAll).
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
		} catch (\Throwable $e) {
			$this->logger->error(
				'PurchaseOrderService: failed to query OpenRegister',
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Convert a euro float to integer cents, rounding half-up.
	 *
	 * @param float $amount Amount in euro.
	 *
	 * @return int Cents.
	 */
	private function toCents(float $amount): int {
		return (int)round(($amount * 100), 0, PHP_ROUND_HALF_UP);
	}//end toCents()

	/**
	 * Convert integer cents back to a euro float (multipleOf 0.01).
	 *
	 * @param int $cents Amount in cents.
	 *
	 * @return float Amount in euro.
	 */
	private function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Current timestamp in ISO-8601 (Y-m-d\TH:i:sP) — server-authoritative for
	 * createdAt / sentAt / signedAt.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
