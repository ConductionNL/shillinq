<?php

/**
 * Purchase Order Approval Service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Slice 11 of the bookkeeping-purchase-order-3way chain — records
 * approver identities + decision timestamps on a PurchaseOrder's
 * approval chain so the audit-trail export (REQ-PO3W-010) can show,
 * per-PO, which user signed off at which threshold tier and when.
 *
 * The service is intentionally narrow: it owns the
 * `recordApprovalDecision()` write path that stamps the authenticated
 * user (NEVER the request body) as `userId`, the supplied decision and
 * the deterministic ISO `decidedAt` timestamp on the next pending
 * approval-chain entry. When the chain becomes fully approved the PO
 * statusCode is advanced to `approved`; a `rejected` decision sends
 * it to `cancelled`, the schema's terminal state (#1753). The state-machine itself stays in OR — this service
 * only writes the canonical record so OR's audit-trail-immutable
 * abstraction captures it.
 *
 * Administration scope is validated server-side by
 * {@see AdministrationContextService::canAccess()} — cross-tenant calls
 * are masked as RuntimeException(`not found`) and surface as 404 in the
 * controller (ADR-005 IDOR-safe). The poId is also re-loaded server-side
 * before any approval write so a forged POST cannot pivot a decision
 * into another tenant's PO record.
 *
 * No new approval-chain entries are minted by this service — the chain
 * is materialised by {@see PurchaseOrderService::createPurchaseOrder()}.
 * This service ONLY signs the next pending slot in the chain.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Lifecycle\PurchaseOrderApprovalGuard;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Slice 11 — records approver identity + decidedAt on the next pending
 * approval-chain entry of a PurchaseOrder and advances the PO lifecycle
 * when the chain is fully signed (REQ-PO3W-010 audit-trail capture).
 *
 * Public method:
 *  - recordApprovalDecision(): stamp userId + decision + decidedAt on
 *    the next pending entry; advance statusCode to approved when
 *    the chain is fully approved or cancelled when a single rejection
 *    is recorded.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
class PurchaseOrderApprovalService {

	/**
	 * Decision enum values mirror the slice-01 PurchaseOrder.approvalChain.decision enum.
	 *
	 * @var string
	 */
	public const DECISION_APPROVED = 'approved';

	/**
	 * Decision rejected — terminal: the PO advances to statusCode=cancelled.
	 *
	 * @var string
	 */
	public const DECISION_REJECTED = 'rejected';

	/**
	 * Decision delegated — chains the slot to another role; not advanced here.
	 *
	 * @var string
	 */
	public const DECISION_DELEGATED = 'delegated';

	/**
	 * Decision pending: the slot waits for its approver.
	 *
	 * @var string
	 */
	public const DECISION_PENDING = 'pending';

	/**
	 * Schema slug for PurchaseOrder records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PURCHASE_ORDER = 'PurchaseOrder';

	/**
	 * The accepted decision enum — anything else is rejected with a
	 * RuntimeException so the controller maps it to a 400.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_DECISIONS = [
		self::DECISION_APPROVED,
		self::DECISION_REJECTED,
		self::DECISION_DELEGATED,
	];

	/**
	 * Constructor.
	 *
	 *                                      ObjectService is fetched
	 *                                      lazily so unit tests
	 *                                      swap an in-memory stub.
	 * @param IAppConfig $appConfig App config for the OR
	 *                              register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession Session for userId
	 *                                  attribution
	 *                                  (server-authoritative).
	 * @param LoggerInterface $logger Logger (no sensitive
	 *                                payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param ApprovalActivityEmitter|null $activityEmitter Optional emitter for
	 *                                                      approval activity events.
	 * @param ServerWriteAuthority $writeAuthority Grants the approve transition for the decision save.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?ApprovalActivityEmitter $activityEmitter = null,
		private readonly ServerWriteAuthority $writeAuthority = new ServerWriteAuthority(),
	) {

	}//end __construct()

	/**
	 * Record an approver decision on a PurchaseOrder's approval chain.
	 *
	 * The method stamps the next pending entry's `userId` from the
	 * authenticated user session (NEVER from the request body), the
	 * `decision` from the (validated) input and a deterministic
	 * `decidedAt` ISO timestamp. When the chain becomes fully approved
	 * the PO statusCode advances to `approved`; any rejection
	 * advances it to `cancelled`. Delegated does not advance the
	 * lifecycle (the slot remains pending under a different role —
	 * outside slice 11 scope).
	 *
	 * Server-authoritative:
	 *  - administration scope is validated;
	 *  - the poId is re-loaded from OR so a forged POST cannot pivot to
	 *    a cross-tenant record;
	 *  - the decision is validated against {@see ALLOWED_DECISIONS};
	 *  - the PO must currently be in `draft` (waiting for its chain) — calling on an
	 *    already-approved or terminated PO throws a RuntimeException;
	 *  - the chain must carry at least one pending entry — calling on
	 *    a fully-signed chain throws a RuntimeException.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $purchaseOrderId PurchaseOrder id.
	 * @param string $decision One of {@see ALLOWED_DECISIONS}.
	 * @param string|null $comment Optional approver comment.
	 *
	 * @return array<string,mixed> The updated PurchaseOrder record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing PO,
	 *                           invalid decision, non-pending PO state,
	 *                           or fully-signed chain.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function recordApprovalDecision(
		string $administrationId,
		string $purchaseOrderId,
		string $decision,
		?string $comment = null,
	): array {
		$approval = new ApprovalDecision();
		$approval->administrationId = $administrationId;
		$approval->purchaseOrderId  = $purchaseOrderId;
		$approval->decision         = $decision;
		$approval->comment          = $comment;

		return $this->recordDecision(approval: $approval);
	}//end recordApprovalDecision()

	/**
	 * Check, sign, save and announce one decision.
	 *
	 * @param ApprovalDecision $approval The decision asked for.
	 *
	 * @return array<string,mixed> The updated PurchaseOrder record.
	 *
	 * @throws \RuntimeException When a check refuses the decision.
	 */
	private function recordDecision(ApprovalDecision $approval): array {
		$purchaseOrder = $this->decidablePurchaseOrder(approval: $approval);

		$approval->decidedAt = $this->nowIso();
		$approval->userId    = $this->currentUserId();
		$purchaseOrder       = $this->applyDecision(purchaseOrder: $purchaseOrder, approval: $approval);

		$saved = $this->writeAuthority->during(
			purpose: PurchaseOrderApprovalGuard::PURPOSE,
			objectId: $approval->purchaseOrderId,
			work: fn (): array => $this->saveObject(schema: self::SCHEMA_PURCHASE_ORDER, object: $purchaseOrder)
		);

		$this->emitDecisionActivity(purchaseOrder: $purchaseOrder, approval: $approval);

		return $saved;
	}//end recordDecision()

	/**
	 * The purchase order the decision is for, once the tenant, the input and
	 * the order's own state allow a decision.
	 *
	 * @param ApprovalDecision $approval The decision asked for.
	 *
	 * @return array<string,mixed> The loaded PurchaseOrder record.
	 *
	 * @throws \RuntimeException When the tenant, the input or the order refuses.
	 */
	private function decidablePurchaseOrder(ApprovalDecision $approval): array {
		$this->assertAccess(administrationId: $approval->administrationId);
		$this->assertDecisionInput(approval: $approval);

		$purchaseOrder = $this->loadPurchaseOrder(
			administrationId: $approval->administrationId,
			purchaseOrderId: $approval->purchaseOrderId
		);
		$this->assertAwaitingApproval(purchaseOrder: $purchaseOrder);

		return $purchaseOrder;
	}//end decidablePurchaseOrder()

	/**
	 * Refuse an empty purchase order id or a decision outside the enum.
	 *
	 * @param ApprovalDecision $approval The decision asked for.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the input is unusable.
	 */
	private function assertDecisionInput(ApprovalDecision $approval): void {
		if ($approval->purchaseOrderId === '') {
			throw new RuntimeException('Purchase order not found');
		}

		if (in_array($approval->decision, self::ALLOWED_DECISIONS, true) === false) {
			throw new RuntimeException('Invalid approval decision');
		}
	}//end assertDecisionInput()

	/**
	 * Refuse a purchase order that is not waiting for its chain.
	 *
	 * A PO waiting for approval is in the schema's `draft` state (#1753); a
	 * draft with an empty chain has nothing to sign.
	 *
	 * @param array<string,mixed> $purchaseOrder The loaded PurchaseOrder record.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the order is not pending or its chain is empty.
	 */
	private function assertAwaitingApproval(array $purchaseOrder): void {
		if ((string)($purchaseOrder['statusCode'] ?? '') !== 'draft') {
			throw new RuntimeException('Purchase order is not pending approval');
		}

		if ((array)($purchaseOrder['approvalChain'] ?? []) === []) {
			throw new RuntimeException('Approval chain is empty');
		}
	}//end assertAwaitingApproval()

	/**
	 * The purchase order with the decision signed into its chain and its
	 * statusCode advanced.
	 *
	 * @param array<string,mixed> $purchaseOrder The loaded PurchaseOrder record.
	 * @param ApprovalDecision    $approval      The checked decision.
	 *
	 * @return array<string,mixed> The record to save.
	 *
	 * @throws \RuntimeException When no entry is left to sign.
	 */
	private function applyDecision(array $purchaseOrder, ApprovalDecision $approval): array {
		$chain = $this->signNextPending(chain: (array)($purchaseOrder['approvalChain'] ?? []), approval: $approval);

		$purchaseOrder['approvalChain'] = $chain;
		$purchaseOrder['statusCode']    = $this->nextLifecycleState(
			decision: $approval->decision,
			chain: $chain,
			current: (string)($purchaseOrder['statusCode'] ?? '')
		);

		return $purchaseOrder;
	}//end applyDecision()

	/**
	 * The chain with its first pending entry signed.
	 *
	 * @param array<int|string,mixed> $chain    The approval chain.
	 * @param ApprovalDecision        $approval The checked decision.
	 *
	 * @return array<int|string,mixed> The signed chain.
	 *
	 * @throws \RuntimeException When every entry is already signed.
	 */
	private function signNextPending(array $chain, ApprovalDecision $approval): array {
		foreach ($chain as $index => $entry) {
			if (is_array($entry) === false || self::isPendingEntry(entry: $entry) === false) {
				continue;
			}

			$chain[$index] = $this->signedEntry(entry: $entry, approval: $approval);
			return $chain;
		}

		throw new RuntimeException('Approval chain is fully signed');
	}//end signNextPending()

	/**
	 * One pending entry, signed.
	 *
	 * An entry written before #1716 carries status/signedAt/signedBy; signing
	 * it moves it onto the one shape the schema declares. A comment is kept
	 * trimmed, and only when something is left.
	 *
	 * @param array<string,mixed> $entry    The pending entry.
	 * @param ApprovalDecision    $approval The checked decision.
	 *
	 * @return array<string,mixed> The signed entry.
	 */
	private function signedEntry(array $entry, ApprovalDecision $approval): array {
		unset($entry['status'], $entry['signedAt'], $entry['signedBy']);
		$entry['userId']    = $approval->userId;
		$entry['decision']  = $approval->decision;
		$entry['decidedAt'] = $approval->decidedAt;

		$trimmed = trim((string)$approval->comment);
		if ($trimmed !== '') {
			$entry['comment'] = $trimmed;
		}

		return $entry;
	}//end signedEntry()

	/**
	 * Emit the Nextcloud Activity event for the decision (REQ-RAP-006), so the
	 * BookkeepingActivityFeed manifest entry surfaces it in the user-facing
	 * timeline. The emitter is optional so unit tests don't have to wire
	 * IActivityManager; production DI always provides it. A delegation emits
	 * nothing.
	 *
	 * @param array<string,mixed> $purchaseOrder The saved PurchaseOrder record.
	 * @param ApprovalDecision    $approval      The recorded decision.
	 *
	 * @return void
	 */
	private function emitDecisionActivity(array $purchaseOrder, ApprovalDecision $approval): void {
		if ($this->activityEmitter === null) {
			return;
		}

		$summary = sprintf('Purchase order %s', (string)($purchaseOrder['poNumber'] ?? $approval->purchaseOrderId));
		if ($approval->decision === self::DECISION_APPROVED) {
			$this->emitApproved(approval: $approval, summary: $summary);
			return;
		}

		if ($approval->decision === self::DECISION_REJECTED) {
			$this->emitRejected(approval: $approval, summary: $summary);
		}
	}//end emitDecisionActivity()

	/**
	 * Emit the approved activity event.
	 *
	 * @param ApprovalDecision $approval The recorded decision.
	 * @param string           $summary  The purchase order label.
	 *
	 * @return void
	 */
	private function emitApproved(ApprovalDecision $approval, string $summary): void {
		$this->activityEmitter?->emitApprovalApproved(
			objectType:  self::SCHEMA_PURCHASE_ORDER,
			objectId:    $approval->purchaseOrderId,
			actorUid:    $approval->userId,
			summaryHint: $summary,
			comment:     (string)($approval->comment ?? '')
		);
	}//end emitApproved()

	/**
	 * Emit the rejected activity event, the comment as its reason.
	 *
	 * @param ApprovalDecision $approval The recorded decision.
	 * @param string           $summary  The purchase order label.
	 *
	 * @return void
	 */
	private function emitRejected(ApprovalDecision $approval, string $summary): void {
		$this->activityEmitter?->emitApprovalRejected(
			objectType:  self::SCHEMA_PURCHASE_ORDER,
			objectId:    $approval->purchaseOrderId,
			actorUid:    $approval->userId,
			summaryHint: $summary,
			reason:      (string)($approval->comment ?? '')
		);
	}//end emitRejected()

	/**
	 * Whether an approval-chain entry still waits for its approver.
	 *
	 * The chain has one shape, the one `PurchaseOrder.approvalChain` declares:
	 * `userId`, `decision`, `decidedAt`, `comment`. An entry written before
	 * #1716 carries `status: pending` instead of a decision, and still counts
	 * as pending so a purchase order created then can be approved.
	 *
	 * @param array<string,mixed> $entry One approval-chain entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public static function isPendingEntry(array $entry): bool {
		$decision = (string)($entry['decision'] ?? '');
		if ($decision !== '') {
			return $decision === self::DECISION_PENDING;
		}

		return (string)($entry['status'] ?? '') === self::DECISION_PENDING;
	}//end isPendingEntry()

	/**
	 * Whether an approval-chain entry is approved and stamped with its time.
	 *
	 * The send check and the Peppol transmission read this, so they read what
	 * recordApprovalDecision() writes (#1716).
	 *
	 * @param mixed $entry One approval-chain entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public static function isApprovedEntry(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		return (string)($entry['decision'] ?? '') === self::DECISION_APPROVED
			&& trim((string)($entry['decidedAt'] ?? '')) !== '';
	}//end isApprovedEntry()

	/**
	 * Determine the next PO statusCode after a decision.
	 *
	 * - rejected → cancelled (the schema's terminal state; the chain entry keeps the rejection)
	 * - approved + every chain entry approved → approved
	 * - approved + chain still has pending entries → draft
	 * - delegated → draft (the slot is re-routed; outside this slice)
	 *
	 * @param string $decision The decision recorded.
	 * @param array<int,mixed> $chain The updated approval chain.
	 * @param string $current Current statusCode (always `draft`).
	 *
	 * @return string
	 */
	private function nextLifecycleState(string $decision, array $chain, string $current): string {
		if ($decision === self::DECISION_REJECTED) {
			return 'cancelled';
		}

		if ($decision === self::DECISION_DELEGATED) {
			return $current;
		}

		if ($this->isFullyApproved(chain: $chain) === true) {
			return 'approved';
		}

		return $current;
	}//end nextLifecycleState()

	/**
	 * Whether every entry of the chain is approved; a malformed entry is
	 * skipped.
	 *
	 * @param array<int|string,mixed> $chain The approval chain.
	 *
	 * @return bool
	 */
	private function isFullyApproved(array $chain): bool {
		foreach ($chain as $entry) {
			if (is_array($entry) === true && (string)($entry['decision'] ?? '') !== self::DECISION_APPROVED) {
				return false;
			}
		}

		return true;
	}//end isFullyApproved()

	/**
	 * Load a PurchaseOrder by id, scoped to the administration so a
	 * forged id from another tenant masks as "not found" (ADR-005).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $purchaseOrderId PurchaseOrder id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the PO is not found in the tenant.
	 */
	private function loadPurchaseOrder(string $administrationId, string $purchaseOrderId): array {
		$purchaseOrder = $this->findOne(
			schema: self::SCHEMA_PURCHASE_ORDER,
			filters: [
				'id' => $purchaseOrderId,
				'administrationId' => $administrationId,
			]
		);
		if ($purchaseOrder === null) {
			throw new RuntimeException('Purchase order not found');
		}

		return $purchaseOrder;
	}//end loadPurchaseOrder()

	/**
	 * Validate the administration scope (ADR-005 IDOR-safe).
	 *
	 * @param string $administrationId Tenant id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()

	/**
	 * Resolve the current user id — anonymous callers cannot record
	 * approval decisions (the controller rejects them with 401 before
	 * the service is called, but we belt-and-brace here).
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When no user session is bound.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('Approval decision requires an authenticated user');
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Persist via the real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object to persist.
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
		} catch (\Throwable $exception) {
			$this->logger->error(
				'PurchaseOrderApprovalService: failed to persist object',
				['schema' => $schema, 'exception' => $exception->getMessage()]
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
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $exception) {
			$this->logger->error(
				'PurchaseOrderApprovalService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $exception->getMessage()]
			);
			return null;
		}

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
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
	 * Current timestamp in ISO-8601 — server-authoritative.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
