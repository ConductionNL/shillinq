<?php

/**
 * Purchase Order Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\PurchaseOrderServiceInterface;
use OCA\Shillinq\Lifecycle\FrameworkAgreementDrawdownGuard;
use OCA\Shillinq\Lifecycle\SupplierQualificationGuard;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\Peppol\LogPeppolTransmissionAdapter;
use OCA\Shillinq\Service\PurchaseOrder\LogPurchaseOrderMailer;
use OCA\Shillinq\Service\PurchaseOrder\PeppolBisOrderMapper;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Member 02 of bookkeeping-purchase-order-3way: PO creation + approval routing.
 *
 * Public methods:
 * - createPurchaseOrder(): validates requester + cost-center budget, generates a
 *   CBS-conform po_number server-side, materialises the approval chain and the
 *   ApprovalTask records, dispatches notifications, persists the PurchaseOrder
 *   with statusCode "draft"; the approval chain carries the pending decisions.
 * - determineApprovalChain(): pure-logic threshold evaluation; returns an ordered
 *   list of approver-role descriptors. Used by createPurchaseOrder and by the
 *   service-layer guards. Independent of storage so it is trivially unit-tested.
 * - blockSendUntilApproved(): refuses the transition to "sent" unless every
 *   required approver in the chain has approved with a timestamp; on success it
 *   persists the lifecycle change and returns the updated record.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 * Pre-existing debt (issue #506): broad domain surface area; variable
 * renames deferred pending a dedicated pass.
 */
class PurchaseOrderService implements PurchaseOrderServiceInterface {
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
	 * Dispatch channel: Peppol through integriq (REQ-PODR-001).
	 *
	 * @var string
	 */
	public const CHANNEL_PEPPOL = 'peppol';

	/**
	 * Dispatch channel: PDF and email through the mail channel (REQ-PODR-002).
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * Dispatch status: handed off, waiting for the delivery status.
	 *
	 * @var string
	 */
	public const DISPATCH_PENDING = 'pending';

	/**
	 * Dispatch status: the supplier's channel took the order.
	 *
	 * @var string
	 */
	public const DISPATCH_SENT = 'sent';

	/**
	 * Dispatch status: it did not reach the supplier; dispatchError says why.
	 *
	 * @var string
	 */
	public const DISPATCH_FAILED = 'failed';

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
	 * @param ObjectTransitionRunner|null $transitionRunner Runs the declared `send` transition (REQ-PODR-003).
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
		private readonly ?ObjectTransitionRunner $transitionRunner = null,
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
	 *  - statusCode starts at "draft", the schema's initial state, also while the approval chain is pending
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
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public function createPurchaseOrder(string $administrationId, array $payload): array {
		$order              = $this->checkedOrder(administrationId: $administrationId, payload: $payload);
		$frameworkAgreement = $this->assertCreationPolicies(order: $order);
		$persisted          = $this->persistNewPurchaseOrder(order: $order);

		// Record the framework-agreement call-off drawdown now the PO is persisted
		// (REQ-PG-004). The guard already verified this fits the remaining ceiling.
		if ($frameworkAgreement !== null) {
			$frameworkAgreement['drawnAmount'] = ((int)($frameworkAgreement['drawnAmount'] ?? 0) + $order['totalCents']);
			$this->saveObject(schema: 'FrameworkAgreement', object: $frameworkAgreement);
		}

		return $persisted;
	}//end createPurchaseOrder()

	/**
	 * The checked parties, lines and total of a new order, refused in that
	 * order when one is missing or invalid.
	 *
	 * @param string              $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload          Caller payload.
	 *
	 * @return array{administrationId:string,requesterId:string,supplierId:string,costCenter:string,lines:array<int,array<string,mixed>>,totalCents:int,payload:array<string,mixed>}
	 *
	 * @throws \RuntimeException When the requester lacks access or a field is missing or invalid.
	 */
	private function checkedOrder(string $administrationId, array $payload): array {
		$order = $this->orderParties(administrationId: $administrationId, payload: $payload);

		$order['lines']      = $this->normaliseLines(rawLines: (array)($payload['lines'] ?? []));
		$order['totalCents'] = $this->totalCents(lines: $order['lines']);
		if ($order['totalCents'] <= 0) {
			throw new RuntimeException('Purchase order total must be positive');
		}

		$order['payload'] = $payload;
		return $order;
	}//end checkedOrder()

	/**
	 * The administration, the authenticated requester, the supplier and the
	 * cost center of a new order.
	 *
	 * @param string              $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload          Caller payload.
	 *
	 * @return array{administrationId:string,requesterId:string,supplierId:string,costCenter:string}
	 *
	 * @throws \RuntimeException When the requester lacks access or a party is missing.
	 */
	private function orderParties(string $administrationId, array $payload): array {
		return [
			'administrationId' => $administrationId,
			'requesterId' => $this->authorisedRequester(administrationId: $administrationId),
			'supplierId' => $this->requiredText(payload: $payload, field: 'supplierId'),
			'costCenter' => $this->requiredText(payload: $payload, field: 'costCenter'),
		];
	}//end orderParties()

	/**
	 * The authenticated requester in an accessible administration; the
	 * requester is never trusted from the request body (ADR-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the administration is missing or inaccessible, or nobody is signed in.
	 */
	private function authorisedRequester(string $administrationId): string {
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

		return $requesterId;
	}//end authorisedRequester()

	/**
	 * A trimmed required payload field.
	 *
	 * @param array<string,mixed> $payload Caller payload.
	 * @param string              $field   The field, also the refusal's label.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the field is empty.
	 */
	private function requiredText(array $payload, string $field): string {
		$value = trim((string)($payload[$field] ?? ''));
		if ($value === '') {
			throw new RuntimeException($field . ' is required');
		}

		return $value;
	}//end requiredText()

	/**
	 * Refuse an order over its cost-center budget, without a required
	 * requisition, to an unqualified supplier (REQ-PG-002), or over the
	 * remaining ceiling of the framework agreement it calls off (REQ-PG-004).
	 *
	 * @param array<string,mixed> $order The checked order.
	 *
	 * @return array<string,mixed>|null The framework agreement called off, or null.
	 *
	 * @throws \RuntimeException When a policy refuses the order.
	 */
	private function assertCreationPolicies(array $order): ?array {
		$administrationId = $order['administrationId'];
		$this->assertCostCenterBudget(administrationId: $administrationId, costCenter: $order['costCenter'], addCents: $order['totalCents']);
		$this->assertRequisitionPolicy(administrationId: $administrationId, requisitionId: trim((string)($order['payload']['requisitionId'] ?? '')));
		$this->assertSupplierQualificationPolicy(administrationId: $administrationId, supplierId: $order['supplierId']);

		$frameworkAgreementId = trim((string)($order['payload']['frameworkAgreementId'] ?? ''));
		if ($frameworkAgreementId === '') {
			return null;
		}

		return $this->frameworkAgreementDrawdownGuard->assertWithinCeiling(
			administrationId: $administrationId,
			frameworkAgreementId: $frameworkAgreementId,
			addCents: $order['totalCents']
		);
	}//end assertCreationPolicies()

	/**
	 * Save the new order with its approval chain and raise an ApprovalTask
	 * per required approver.
	 *
	 * @param array<string,mixed> $order The checked, policy-cleared order.
	 *
	 * @return array<string,mixed> The persisted PurchaseOrder payload.
	 */
	private function persistNewPurchaseOrder(array $order): array {
		$order['approvalChain'] = $this->determineApprovalChain(amount: $this->fromCents(cents: $order['totalCents']));
		$order['poNumber']      = $this->generatePoNumber(administrationId: $order['administrationId']);

		$persisted = $this->saveObject(schema: 'PurchaseOrder', object: $this->purchaseOrderRecord(order: $order));
		$this->assignApprovalTasks(
			administrationId: $order['administrationId'],
			purchaseOrderId: (string)($persisted['id'] ?? ($persisted['@self']['id'] ?? $order['poNumber'])),
			poNumber: $order['poNumber'],
			chain: $order['approvalChain']
		);

		return $persisted;
	}//end persistNewPurchaseOrder()

	/**
	 * The new PurchaseOrder record.
	 *
	 * @param array<string,mixed> $order The order with its number and approval chain.
	 *
	 * @return array<string,mixed>
	 */
	private function purchaseOrderRecord(array $order): array {
		$payload = $order['payload'];

		return [
			'poNumber' => $order['poNumber'],
			'administrationId' => $order['administrationId'],
			'supplierId' => $order['supplierId'],
			'requesterId' => $order['requesterId'],
			'costCenter' => $order['costCenter'],
			'projectCode' => trim((string)($payload['projectCode'] ?? '')),
			'requisitionId' => trim((string)($payload['requisitionId'] ?? '')),
			'frameworkAgreementId' => trim((string)($payload['frameworkAgreementId'] ?? '')),
			'lines' => $order['lines'],
			'totalAmount' => $this->fromCents(cents: $order['totalCents']),
			'currency' => (string)($payload['currency'] ?? 'EUR'),
			'approvalChain' => $this->initialiseApprovalChainEntries(chain: $order['approvalChain']),
			// The schema's lifecycle field (#1753). A PO waiting for its approval
			// chain stays in `draft` (the lifecycle's initial state); the chain
			// entries carry the pending decisions.
			'statusCode' => 'draft',
			'createdAt' => $this->nowIso(),
			'notes' => trim((string)($payload['notes'] ?? '')),
		];
	}//end purchaseOrderRecord()

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
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
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
	 * decision=approved + a non-empty decidedAt timestamp, and on success stamps
	 * sentAt and moves the order to "sent" through the declared `send` transition. On failure the PO is left in its
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
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-1.1
	 */
	public function blockSendUntilApproved(string $administrationId, string $purchaseOrderId): array {
		$po = $this->loadPurchaseOrderForTransmission(administrationId: $administrationId, purchaseOrderId: $purchaseOrderId);

		// The state moves only through the declared `send` transition
		// (REQ-PODR-003); the service stamps when, the lifecycle says what.
		$po['sentAt'] = $this->nowIso();

		return $this->transitionToSent(purchaseOrder: $this->saveObject(schema: 'PurchaseOrder', object: $po));
	}//end blockSendUntilApproved()

	/**
	 * Which dispatch channels can take an order, and why one cannot (REQ-PODR-001, REQ-PODR-002).
	 *
	 * Peppol is there when an adapter other than the logging stand-in is bound
	 * (integriq is installed); email when the mailer says a channel takes mail.
	 * A channel that is not there is shown disabled with its reason, so the
	 * page never offers a send that would only be logged.
	 *
	 * @return array{peppol: array{available: bool, reason: string|null}, email: array{available: bool, reason: string|null}}
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
	 */
	public function dispatchChannels(): array {
		$peppol = ($this->peppolAdapter instanceof LogPeppolTransmissionAdapter) === false;
		$email  = $this->purchaseOrderMailer->isAvailable();

		return [
			'peppol' => ['available' => $peppol, 'reason' => ($peppol === true ? null : 'Peppol sending is not configured')],
			'email' => ['available' => $email, 'reason' => ($email === true ? null : 'Email sending is not configured')],
		];
	}//end dispatchChannels()

	/**
	 * Hand an approved PO to integriq for Peppol delivery (REQ-PODR-001).
	 *
	 * The approval-complete precondition of the slice-02 send block still runs
	 * first. The supplier is looked up through the adapter; a supplier that is
	 * not a Peppol participant, an adapter that is not configured and a refused
	 * hand-off are recorded on the order as a failed Peppol dispatch with the
	 * reason, the order stays approved and the page offers email instead. On a
	 * hand-off the order shows the dispatch pending; it moves to sent only when
	 * integriq reports the delivery ({@see applyPeppolDeliveryStatus}).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder with its dispatch recorded.
	 *
	 * @throws \RuntimeException When the PO is missing, the approval chain is
	 *                           incomplete or a dispatch is already pending
	 *                           (mapped to 404 / 409 by the controller).
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.2
	 */
	public function sendToPeppol(string $administrationId, string $purchaseOrderId): array {
		$po = $this->loadPurchaseOrderForTransmission(
			administrationId: $administrationId,
			purchaseOrderId: $purchaseOrderId
		);
		$supplierId = (string)($po['supplierId'] ?? '');

		try {
			// The generalised port (REQ-EINV-004) names the parameter partyId.
			$participant = $this->peppolAdapter->lookupParticipant(
				administrationId: $administrationId,
				partyId: $supplierId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PurchaseOrderService: Peppol participant lookup failed',
				['purchaseOrderId' => $purchaseOrderId, 'exception' => $e->getMessage()]
			);
			return $this->recordDispatchFailure(
				purchaseOrder: $po,
				channel: self::CHANNEL_PEPPOL,
				error: 'Peppol sending is not available: ' . $e->getMessage()
			);
		}

		if ($participant === null || trim($participant) === '') {
			$po['peppolFallbackReason'] = 'supplier_not_peppol_participant';
			return $this->recordDispatchFailure(
				purchaseOrder: $po,
				channel: self::CHANNEL_PEPPOL,
				error: 'The supplier is not a Peppol participant.'
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
				ublOrderXml: $ubl,
				purchaseOrder: $po
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'PurchaseOrderService: Peppol hand-off failed',
				['purchaseOrderId' => $purchaseOrderId, 'exception' => $e->getMessage()]
			);
			$po['peppolFallbackReason'] = 'peppol_send_failed';
			return $this->recordDispatchFailure(
				purchaseOrder: $po,
				channel: self::CHANNEL_PEPPOL,
				error: 'The order could not be handed to the Peppol access point.'
			);
		}

		$po['peppolMessageId'] = $messageId;
		$po['peppolFallbackReason'] = null;
		$po['dispatchChannel'] = self::CHANNEL_PEPPOL;
		$po['dispatchStatus'] = self::DISPATCH_PENDING;
		$po['dispatchError'] = null;

		return $this->saveObject(schema: 'PurchaseOrder', object: $po);
	}//end sendToPeppol()

	/**
	 * Send an approved PO by PDF and email through the mail channel (REQ-PODR-002).
	 *
	 * Refused outright when no mail channel is there, so nothing is claimed.
	 * The order moves to sent through the declared `send` transition only when
	 * the channel accepted the message; a refusal is recorded on the order as
	 * a failed email dispatch and the order stays approved.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id.
	 * @param string $fallbackReason The reason Peppol was not used (audit
	 *                               trail); empty string defaults to
	 *                               `manual_pdf_email_fallback`.
	 *
	 * @return array<string,mixed> The PurchaseOrder after the attempt.
	 *
	 * @throws \RuntimeException When the PO is missing, the approval chain is
	 *                           incomplete, a Peppol dispatch is pending or no
	 *                           mail channel is configured.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.1
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
		if ($this->purchaseOrderMailer->isAvailable() === false) {
			throw new RuntimeException('Email sending is not configured');
		}

		$reason = trim($fallbackReason);
		if ($reason === '') {
			$reason = 'manual_pdf_email_fallback';
		}

		$accepted = $this->purchaseOrderMailer->sendPurchaseOrderEmail(
			administrationId: $administrationId,
			purchaseOrder: $po
		);
		if ($accepted === false) {
			return $this->recordDispatchFailure(
				purchaseOrder: $po,
				channel: self::CHANNEL_EMAIL,
				error: 'The email channel did not accept the message.'
			);
		}

		$po['peppolFallbackReason'] = $reason;
		$po['dispatchChannel'] = self::CHANNEL_EMAIL;
		$po['dispatchStatus'] = self::DISPATCH_SENT;
		$po['dispatchError'] = null;
		$po['sentAt'] = $this->nowIso();

		return $this->transitionToSent(purchaseOrder: $this->saveObject(schema: 'PurchaseOrder', object: $po));
	}//end sendToPDFEmail()

	/**
	 * Apply integriq's delivery status to the order it names (REQ-PODR-001).
	 *
	 * Called by the delivery-status listener as the system: the buyer was
	 * authorised when the dispatch was handed off. Only an order with a Peppol
	 * dispatch is touched. `sent` or `delivered` moves it to sent through the
	 * declared transition; `failed` or `rejected` records the reason and keeps
	 * it approved so the page offers email. A repeated status is harmless.
	 *
	 * @param string $purchaseOrderId The order's id.
	 * @param string $status          integriq's delivery status.
	 * @param string $detail          integriq's reason, for a failure.
	 *
	 * @return bool True when the order changed.
	 *
	 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-2.3
	 */
	public function applyPeppolDeliveryStatus(string $purchaseOrderId, string $status, string $detail): bool {
		$po = $this->findOne(schema: 'PurchaseOrder', filters: ['id' => $purchaseOrderId]);
		if ($po === null || ($po['dispatchChannel'] ?? null) !== self::CHANNEL_PEPPOL
			|| ($po['statusCode'] ?? null) !== 'approved'
		) {
			return false;
		}

		if (in_array($status, ['sent', 'delivered'], true) === true) {
			$now = $this->nowIso();
			$po['dispatchStatus'] = self::DISPATCH_SENT;
			$po['dispatchError'] = null;
			$po['peppolSentAt'] = $now;
			$po['sentAt'] = $now;
			$this->transitionToSent(purchaseOrder: $this->saveObject(schema: 'PurchaseOrder', object: $po), asSystem: true);
			return true;
		}

		if (in_array($status, ['failed', 'rejected'], true) === true) {
			$reason = trim($detail);
			if ($reason === '') {
				$reason = 'The Peppol delivery failed.';
			}

			$this->recordDispatchFailure(purchaseOrder: $po, channel: self::CHANNEL_PEPPOL, error: $reason);
			return true;
		}

		return false;
	}//end applyPeppolDeliveryStatus()

	/**
	 * Record a dispatch that did not reach the supplier; the order keeps its state.
	 *
	 * @param array<string,mixed> $purchaseOrder The order.
	 * @param string              $channel       peppol or email.
	 * @param string              $error         Why it did not go.
	 *
	 * @return array<string,mixed> The saved order.
	 */
	private function recordDispatchFailure(array $purchaseOrder, string $channel, string $error): array {
		$purchaseOrder['dispatchChannel'] = $channel;
		$purchaseOrder['dispatchStatus'] = self::DISPATCH_FAILED;
		$purchaseOrder['dispatchError'] = $error;

		return $this->saveObject(schema: 'PurchaseOrder', object: $purchaseOrder);
	}//end recordDispatchFailure()

	/**
	 * Move a saved order to sent through its declared `send` transition (REQ-PODR-003).
	 *
	 * @param array<string,mixed> $purchaseOrder The saved order, still approved.
	 * @param bool                $asSystem      Run as the system, for the delivery-status listener.
	 *
	 * @return array<string,mixed> The order as it reads after the transition.
	 *
	 * @throws RuntimeException When no transition engine is wired or the engine refuses.
	 */
	private function transitionToSent(array $purchaseOrder, bool $asSystem = false): array {
		if ($this->transitionRunner === null) {
			throw new RuntimeException('Purchase order cannot be sent: no lifecycle engine');
		}

		$id = (string)($purchaseOrder['id'] ?? ($purchaseOrder['@self']['id'] ?? ''));
		if ($asSystem === true) {
			$this->transitionRunner->runAsSystem(objectId: $id, action: 'send');
		} else {
			$this->transitionRunner->run(objectId: $id, action: 'send');
		}

		$sent = $this->findOne(schema: 'PurchaseOrder', filters: ['id' => $id]);
		if ($sent === null) {
			$sent = array_merge($purchaseOrder, ['statusCode' => 'sent']);
		}

		return $sent;
	}//end transitionToSent()

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

		$this->assertFullyApproved(purchaseOrder: $po);
		if (($po['dispatchStatus'] ?? null) === self::DISPATCH_PENDING) {
			throw new RuntimeException('Purchase order cannot be sent: a dispatch is already pending');
		}

		return $po;
	}//end loadPurchaseOrderForTransmission()

	/**
	 * Refuse an order without an approval chain, or with an entry that is not
	 * approved and stamped.
	 *
	 * @param array<string,mixed> $purchaseOrder The persisted PurchaseOrder record.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the chain is empty or incomplete.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PurchaseOrderApprovalService::isApprovedEntry()
	 *  is a pure check on one chain entry, shared with the approval service so the
	 *  send check and the signing read one shape (#1716).
	 */
	private function assertFullyApproved(array $purchaseOrder): void {
		$chain = (array)($purchaseOrder['approvalChain'] ?? []);
		if ($chain === []) {
			throw new RuntimeException('Purchase order has no approval chain');
		}

		foreach ($chain as $entry) {
			if (PurchaseOrderApprovalService::isApprovedEntry(entry: $entry) === false) {
				throw new RuntimeException('Purchase order cannot be sent: approval chain incomplete');
			}
		}
	}//end assertFullyApproved()

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
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
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
					->setApp(AppIdentity::APP_ID)
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
	 * Each entry is written in the shape `PurchaseOrder.approvalChain` declares
	 * and PurchaseOrderApprovalService::recordApprovalDecision() signs:
	 * `decision: pending`, an empty `decidedAt` and `userId` (#1716).
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
				'userId' => '',
				'decision' => PurchaseOrderApprovalService::DECISION_PENDING,
				// No `decidedAt` until a decision: the schema declares it a
				// date-time and OpenRegister refuses an empty string (#1753).
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
		foreach (array_values($rawLines) as $index => $raw) {
			if (is_array($raw) === false) {
				throw new RuntimeException('Line item must be an object');
			}

			$lines[] = $this->pricedLine(line: $this->checkedLine(raw: $raw, lineNumber: ($index + 1)));
		}

		return $lines;
	}//end normaliseLines()

	/**
	 * One raw line's fields, refused when the product code or GL account is
	 * missing, the quantity is not positive, the price is negative or the VAT
	 * rate is not a fraction.
	 *
	 * @param array<string,mixed> $raw        The raw line.
	 * @param int                 $lineNumber The line's position.
	 *
	 * @return array{lineNumber:int,productCode:string,glAccount:string,quantity:float,unitPrice:float,vatRate:float}
	 *
	 * @throws \RuntimeException When the line is malformed.
	 */
	private function checkedLine(array $raw, int $lineNumber): array {
		$line = [
			'lineNumber' => ((int)($raw['lineNumber'] ?? $lineNumber)),
			'productCode' => trim((string)($raw['productCode'] ?? '')),
			'glAccount' => trim((string)($raw['glAccount'] ?? '')),
			'quantity' => (float)($raw['quantity'] ?? 0),
			'unitPrice' => (float)($raw['unitPrice'] ?? 0),
			'vatRate' => (float)($raw['vatRate'] ?? 0),
		];

		$refusal = match (true) {
			$line['productCode'] === '' => 'is missing productCode',
			$line['glAccount'] === '' => 'is missing glAccount',
			$line['quantity'] <= 0.0 => 'must have positive quantity',
			$line['unitPrice'] < 0.0 => 'must have non-negative unitPrice',
			($line['vatRate'] < 0.0 || $line['vatRate'] > 1.0) => 'vatRate must be a fraction between 0 and 1',
			default => '',
		};
		if ($refusal !== '') {
			throw new RuntimeException('Line ' . $lineNumber . ' ' . $refusal);
		}

		return $line;
	}//end checkedLine()

	/**
	 * A checked line with its unit price, total and VAT rounded in cents.
	 *
	 * @param array{lineNumber:int,productCode:string,glAccount:string,quantity:float,unitPrice:float,vatRate:float} $line The checked line.
	 *
	 * @return array<string,mixed>
	 */
	private function pricedLine(array $line): array {
		$unitCents = $this->toCents(amount: $line['unitPrice']);
		$lineCents = (int)round(($unitCents * $line['quantity']), 0, PHP_ROUND_HALF_UP);
		$vatCents  = (int)round(($lineCents * $line['vatRate']), 0, PHP_ROUND_HALF_UP);

		return [
			'lineNumber' => $line['lineNumber'],
			'productCode' => $line['productCode'],
			'quantity' => $line['quantity'],
			'unitPrice' => $this->fromCents(cents: $unitCents),
			'lineTotal' => $this->fromCents(cents: $lineCents),
			'vatRate' => $line['vatRate'],
			'vatAmount' => $this->fromCents(cents: $vatCents),
			'glAccount' => $line['glAccount'],
		];
	}//end pricedLine()

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
			AppIdentity::APP_ID,
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
			AppIdentity::APP_ID,
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
