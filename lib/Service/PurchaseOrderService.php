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
 * declared by slice 01); see toCents/fromCents helpers. Approval-chain routing is
 * threshold-based: below €10,000 a single Teamleider approves; €10,000–€49,999.99
 * adds Facility Manager; €50,000 or above adds Procurement Manager. The PO cannot
 * transition to lifecycle state "sent" until every assigned ApprovalTask is signed
 * with a timestamp.
 *
 * Approver notifications are dispatched via NC's standard notification manager
 * (OCP\Notification\IManager); the manager's app id is "shillinq" and the object
 * type is "purchase_order" so the Vue layer can deep-link from the notification
 * back to the PO detail.
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

use DateTime;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 02 of bookkeeping-purchase-order-3way: PO creation + approval routing.
 *
 * Public methods:
 * - createPurchaseOrder(): validates requester + cost-center budget, generates a
 *   CBS-conform po_number server-side, materialises the approval chain and the
 *   ApprovalTask records, dispatches notifications, persists the PurchaseOrder
 *   with lifecycle "draft" (or "pending_approval" once tasks exist).
 * - determineApprovalChain(): pure-logic threshold evaluation; returns an ordered
 *   list of approver-role descriptors. Used by createPurchaseOrder and by the
 *   service-layer guards. Independent of storage so it is trivially unit-tested.
 * - blockSendUntilApproved(): refuses the transition to "sent" unless every
 *   required approver in the chain has approved with a timestamp; on success it
 *   persists the lifecycle change and returns the updated record.
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
	 * Approval-chain threshold for the single-approver tier (Teamleider only).
	 * A PO with a total strictly below this amount needs ONE approver.
	 *
	 * @var int Cents.
	 */
	private const THRESHOLD_DOUBLE_APPROVER_CENTS = 1000000;

	/**
	 * Approval-chain threshold for the procurement-manager tier.
	 * A PO with a total at or above this amount also needs the procurement manager.
	 *
	 * @var int Cents.
	 */
	private const THRESHOLD_PROCUREMENT_MANAGER_CENTS = 5000000;

	/**
	 * Notification "object type" for ApprovalTask deep links.
	 *
	 * @var string
	 */
	private const NOTIFICATION_OBJECT_TYPE = 'purchase_order';

	/**
	 * Notification subject identifier for new approval-task assignments.
	 *
	 * @var string
	 */
	private const NOTIFICATION_SUBJECT_APPROVAL_REQUESTED = 'po_approval_requested';

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
	 * @param INotificationManager $notificationManager NC notification dispatcher.
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
	 * @param ApprovalActivityEmitter|null $activityEmitter Optional Activity emitter for the
	 *                                                      REQ-RAP-006 `approval_requested`
	 *                                                      event; nullable so unit tests need
	 *                                                      not wire IActivityManager.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		?PeppolTransmissionAdapterInterface $peppolAdapter = null,
		?PurchaseOrderMailerInterface $purchaseOrderMailer = null,
		?PeppolBisOrderMapper $peppolMapper = null,
		?SupplierQualificationGuard $supplierQualificationGuard = null,
		?FrameworkAgreementDrawdownGuard $frameworkAgreementDrawdownGuard = null,
		private readonly ?ApprovalActivityEmitter $activityEmitter = null,
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
	 *  - the approval chain is computed from determineApprovalChain() and an
	 *    ApprovalTask record is created for each required approver;
	 *  - every approver is notified via the notification manager;
	 *  - lifecycle starts at "pending_approval" (when an approval chain exists)
	 *    or "draft" (no chain — defensive fallback that should not occur for
	 *    positive totals).
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

		$approvalChain = $this->determineApprovalChain(amount: $totalAmount);
		$poNumber = $this->generatePoNumber(administrationId: $administrationId);

		$lifecycleState = 'draft';
		if ($approvalChain !== []) {
			$lifecycleState = 'pending_approval';
		}

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
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			'approvalChain' => $this->initialiseApprovalChainEntries(chain: $approvalChain),
			'lifecycleState' => $lifecycleState,
			'createdAt' => $this->nowIso(),
			'notes' => trim((string)($payload['notes'] ?? '')),
		];

		$persisted = $this->saveObject(schema: 'PurchaseOrder', object: $purchaseOrder);
		$poId = (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? $poNumber));

		$this->assignApprovalTasks(
			administrationId: $administrationId,
			purchaseOrderId: $poId,
			poNumber: $poNumber,
			chain: $approvalChain
		);

		// Record the framework-agreement call-off drawdown now the PO is persisted
		// (REQ-PG-004). The guard already verified this fits the remaining ceiling.
		if ($frameworkAgreement !== null) {
			$frameworkAgreement['drawnAmount'] = ((int)($frameworkAgreement['drawnAmount'] ?? 0) + $totalCent);
			$this->saveObject(schema: 'FrameworkAgreement', object: $frameworkAgreement);
		}

		return $persisted;
	}//end createPurchaseOrder()

	/**
	 * Determine the ordered list of required approvers for a PO total.
	 *
	 * Threshold table (REQ-PO3W-001):
	 *  - amount < €10,000          → [Teamleider]
	 *  - €10,000 ≤ amount < €50,000 → [Teamleider, Facility Manager]
	 *  - amount ≥ €50,000           → [Teamleider, Facility Manager, Procurement Manager]
	 *
	 * Comparison is done in integer cents to avoid float drift.
	 *
	 * @param float $amount The PO total in euro (multipleOf 0.01).
	 *
	 * @return array<int,array{role:string,order:int}> Ordered approver descriptors.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 */
	public function determineApprovalChain(float $amount): array {
		$cents = $this->toCents(amount: $amount);
		if ($cents <= 0) {
			return [];
		}

		$chain = [['role' => 'teamleider', 'order' => 1]];

		if ($cents >= self::THRESHOLD_DOUBLE_APPROVER_CENTS) {
			$chain[] = ['role' => 'facility_manager', 'order' => 2];
		}

		if ($cents >= self::THRESHOLD_PROCUREMENT_MANAGER_CENTS) {
			$chain[] = ['role' => 'procurement_manager', 'order' => 3];
		}

		return $chain;
	}//end determineApprovalChain()

	/**
	 * Refuse to advance a PO to lifecycle "sent" until the chain is fully signed.
	 *
	 * Server-authoritative: the Vue layer never grants the transition. The method
	 * inspects the persisted PurchaseOrder, asserts every approval_chain entry has
	 * status=approved + a non-empty signedAt timestamp, and on success persists
	 * lifecycleState="sent" with a sentAt stamp. On failure the PO is left in its
	 * current state and a RuntimeException is raised so the controller maps it to
	 * a 409 Conflict (ADR-005, REQ-PO3W-001 send-block).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing, not approved, or the chain
	 *                           is incomplete.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 */
	public function blockSendUntilApproved(string $administrationId, string $purchaseOrderId): array {
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

		$chain = (array)($po['approvalChain'] ?? []);
		if ($chain === []) {
			throw new RuntimeException('Purchase order has no approval chain');
		}

		foreach ($chain as $entry) {
			$status = (string)($entry['status'] ?? '');
			$signedAt = trim((string)($entry['signedAt'] ?? ''));
			if ($status !== 'approved' || $signedAt === '') {
				throw new RuntimeException('Purchase order cannot be sent: approval chain incomplete');
			}
		}

		$po['lifecycleState'] = 'sent';
		$po['sentAt'] = $this->nowIso();

		return $this->saveObject(schema: 'PurchaseOrder', object: $po);
	}//end blockSendUntilApproved()

	/**
	 * Transmit an approved PO to the supplier via Peppol BIS Ordering 3.0.
	 *
	 * Slice 03 surface. The method enforces the slice-02 approval-complete
	 * precondition (re-using the same chain check as blockSendUntilApproved so
	 * the guard stays single-sourced), resolves the supplier's Peppol participant
	 * id via the adapter port, transforms the PO into a UBL 2.1 Order document
	 * via PeppolBisOrderMapper, submits the document to the Peppol Access Point,
	 * and persists `peppolMessageId` + `peppolSentAt` + `lifecycleState=sent`
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
	 * @throws \RuntimeException When the PO is missing or the approval chain is
	 *                           incomplete (mapped to 404 / 409 by the controller).
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
		$po['lifecycleState'] = 'sent';
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
	 * @throws \RuntimeException When the PO is missing, the approval chain is
	 *                           incomplete, or the mailer cannot dispatch.
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
		$po['lifecycleState'] = 'sent';
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
	 * @throws \RuntimeException When the PO is missing or the chain is incomplete.
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

		$chain = (array)($po['approvalChain'] ?? []);
		if ($chain === []) {
			throw new RuntimeException('Purchase order has no approval chain');
		}

		foreach ($chain as $entry) {
			$status = (string)($entry['status'] ?? '');
			$signedAt = trim((string)($entry['signedAt'] ?? ''));
			if ($status !== 'approved' || $signedAt === '') {
				throw new RuntimeException('Purchase order cannot be sent: approval chain incomplete');
			}
		}

		return $po;
	}//end loadPurchaseOrderForTransmission()

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
	 * Assign an ApprovalTask record to each required approver and notify them.
	 *
	 * Each task is persisted with its purchaseOrderId, role, order, status=pending,
	 * the administration scope, and a createdAt stamp. Approver notifications are
	 * dispatched via the NC notification manager so the Vue layer can deep-link
	 * straight to the PO detail.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $purchaseOrderId PO id.
	 * @param string $poNumber PO number for the
	 *                         notification
	 *                         subject params.
	 * @param array<int,array{role:string,order:int}> $chain Ordered chain.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
	 */
	private function assignApprovalTasks(
		string $administrationId,
		string $purchaseOrderId,
		string $poNumber,
		array $chain,
	): void {
		$tasksAssigned = false;

		foreach ($chain as $entry) {
			$role = $entry['role'];
			$order = $entry['order'];
			if ($role === '' || $order === 0) {
				continue;
			}

			$task = [
				'purchaseOrderId' => $purchaseOrderId,
				'administrationId' => $administrationId,
				'role' => $role,
				'order' => $order,
				'status' => 'pending',
				'createdAt' => $this->nowIso(),
			];

			$this->saveObject(schema: 'ApprovalTask', object: $task);

			$this->notifyApprovers(
				administrationId: $administrationId,
				purchaseOrderId: $purchaseOrderId,
				poNumber: $poNumber,
				role: $role
			);

			$tasksAssigned = true;
		}//end foreach

		// REQ-RAP-006 row 1 (`approval_requested`): the ApprovalTask records
		// that put this PO in front of an approver have just been created, so
		// this is the "ApprovalRequest created" trigger the event table names.
		// Emitted ONCE per purchase order (not once per role) so the Activity
		// feed carries a single entry per approval round, matching the
		// already-live `approval_approved` / `approval_rejected` rows in
		// PurchaseOrderApprovalService::recordApprovalDecision(). The emitter
		// is nullable for the same reason it is there: unit tests need not
		// wire IActivityManager, and publishing is best-effort (the OR audit
		// trail remains the authoritative record).
		if ($tasksAssigned === true && $this->activityEmitter !== null) {
			$this->activityEmitter->emitApprovalRequested(
				objectType:  'PurchaseOrder',
				objectId:    $purchaseOrderId,
				summaryHint: sprintf('Purchase order %s', $poNumber)
			);
		}

	}//end assignApprovalTasks()

	/**
	 * Dispatch a notification to every user in the administration who carries the
	 * given role.
	 *
	 * Membership lookups go through AdministrationContextService's underlying
	 * register but the call is deliberately self-contained here so the
	 * notification side-effect can be exercised independently. A delivery failure
	 * is logged but does not abort the PO creation.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $purchaseOrderId PO id to deep-link.
	 * @param string $poNumber PO number (parameterises the notification subject).
	 * @param string $role Required role for the approver.
	 *
	 * @return void
	 */
	private function notifyApprovers(
		string $administrationId,
		string $purchaseOrderId,
		string $poNumber,
		string $role,
	): void {
		$approverIds = $this->findApproverIds(administrationId: $administrationId, role: $role);
		foreach ($approverIds as $approverId) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification
					->setApp(Application::APP_ID)
					->setUser($approverId)
					->setDateTime(new DateTime())
					->setObject(self::NOTIFICATION_OBJECT_TYPE, $purchaseOrderId)
					->setSubject(
						self::NOTIFICATION_SUBJECT_APPROVAL_REQUESTED,
						[
							'poNumber' => $poNumber,
							'role' => $role,
						]
					);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'PurchaseOrderService: failed to dispatch approval notification',
					[
						'purchaseOrderId' => $purchaseOrderId,
						'approverId' => $approverId,
						'role' => $role,
						'exception' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

	}//end notifyApprovers()

	/**
	 * Find userIds of administration members carrying a given role.
	 *
	 * Reads AdministrationMembership records through the real ObjectService API
	 * (findAll); never invents a method.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $role The role to match.
	 *
	 * @return array<int,string>
	 */
	private function findApproverIds(string $administrationId, string $role): array {
		$rows = $this->findAll(
			schema: 'AdministrationMembership',
			filters: [
				'administrationId' => $administrationId,
				'role' => $role,
			]
		);

		$userIds = [];
		foreach ($rows as $row) {
			$userId = trim((string)($row['userId'] ?? ''));
			if ($userId !== '') {
				$userIds[] = $userId;
			}
		}

		return $userIds;
	}//end findApproverIds()

	/**
	 * Project the approval-chain descriptor into the persisted PurchaseOrder shape.
	 *
	 * Each entry adds a status=pending stub and an empty signedAt; the controller
	 * (or the matcher service in later slices) will set status=approved + signedAt
	 * once the approver acts.
	 *
	 * @param array<int,array{role:string,order:int}> $chain Chain returned by determineApprovalChain.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function initialiseApprovalChainEntries(array $chain): array {
		$entries = [];
		foreach ($chain as $entry) {
			$entries[] = [
				'role' => $entry['role'],
				'order' => $entry['order'],
				'status' => 'pending',
				'signedAt' => '',
				'signedBy' => '',
			];
		}

		return $entries;
	}//end initialiseApprovalChainEntries()

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
