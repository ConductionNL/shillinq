<?php

/**
 * Purchase Order Approval Service.
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
 * lifecycleState is advanced to `approved`; a `rejected` decision sends
 * it to `rejected`. The state-machine itself stays in OR — this service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
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
 *    the next pending entry; advance lifecycleState to approved when
 *    the chain is fully approved or rejected when a single rejection
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
	 * Decision rejected — terminal: the PO advances to lifecycleState=rejected.
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
	) {

	}//end __construct()

	/**
	 * Record an approver decision on a PurchaseOrder's approval chain.
	 *
	 * The method stamps the next pending entry's `userId` from the
	 * authenticated user session (NEVER from the request body), the
	 * `decision` from the (validated) input and a deterministic
	 * `decidedAt` ISO timestamp. When the chain becomes fully approved
	 * the PO lifecycleState advances to `approved`; any rejection
	 * advances it to `rejected`. Delegated does not advance the
	 * lifecycle (the slot remains pending under a different role —
	 * outside slice 11 scope).
	 *
	 * Server-authoritative:
	 *  - administration scope is validated;
	 *  - the poId is re-loaded from OR so a forged POST cannot pivot to
	 *    a cross-tenant record;
	 *  - the decision is validated against {@see ALLOWED_DECISIONS};
	 *  - the PO must currently be in `pending_approval` — calling on an
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
		$this->assertAccess(administrationId: $administrationId);
		if ($purchaseOrderId === '') {
			throw new RuntimeException('Purchase order not found');
		}

		if (in_array($decision, self::ALLOWED_DECISIONS, true) === false) {
			throw new RuntimeException('Invalid approval decision');
		}

		$purchaseOrder = $this->loadPurchaseOrder(
			administrationId: $administrationId,
			purchaseOrderId: $purchaseOrderId
		);

		$lifecycleState = (string)($purchaseOrder['lifecycleState'] ?? '');
		if ($lifecycleState !== 'pending_approval') {
			throw new RuntimeException('Purchase order is not pending approval');
		}

		$chain = (array)($purchaseOrder['approvalChain'] ?? []);
		if ($chain === []) {
			throw new RuntimeException('Approval chain is empty');
		}

		$decidedAt = $this->nowIso();
		$userId = $this->currentUserId();
		$signed = false;
		foreach ($chain as $index => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ((string)($entry['decision'] ?? '') !== 'pending') {
				continue;
			}

			$entry['userId'] = $userId;
			$entry['decision'] = $decision;
			$entry['decidedAt'] = $decidedAt;
			if ($comment !== null) {
				$trimmed = trim($comment);
				if ($trimmed !== '') {
					$entry['comment'] = $trimmed;
				}
			}

			$chain[$index] = $entry;
			$signed = true;
			break;
		}//end foreach

		if ($signed === false) {
			throw new RuntimeException('Approval chain is fully signed');
		}

		$purchaseOrder['approvalChain'] = $chain;
		$purchaseOrder['lifecycleState'] = $this->nextLifecycleState(
			decision: $decision,
			chain: $chain,
			current: $lifecycleState
		);

		$saved = $this->saveObject(schema: self::SCHEMA_PURCHASE_ORDER, object: $purchaseOrder);

		// Emit a Nextcloud Activity event per REQ-RAP-006 so the
		// BookkeepingActivityFeed manifest entry surfaces the decision
		// in the user-facing timeline. The emitter is optional (nullable)
		// so unit tests don't have to wire IActivityManager; production
		// DI always provides it via ApplicationServer.
		if ($this->activityEmitter !== null) {
			$summary = sprintf('Purchase order %s', (string)($purchaseOrder['poNumber'] ?? $purchaseOrderId));
			if ($decision === self::DECISION_APPROVED) {
				$this->activityEmitter->emitApprovalApproved(
					objectType:  self::SCHEMA_PURCHASE_ORDER,
					objectId:    $purchaseOrderId,
					actorUid:    $userId,
					summaryHint: $summary,
					comment:     (string)($comment ?? '')
				);
			} elseif ($decision === self::DECISION_REJECTED) {
				$this->activityEmitter->emitApprovalRejected(
					objectType:  self::SCHEMA_PURCHASE_ORDER,
					objectId:    $purchaseOrderId,
					actorUid:    $userId,
					summaryHint: $summary,
					reason:      (string)($comment ?? '')
				);
			}
		}

		return $saved;
	}//end recordApprovalDecision()

	/**
	 * Determine the next PO lifecycleState after a decision.
	 *
	 * - rejected → rejected (terminal for this slice; cancels payment chain)
	 * - approved + every chain entry approved → approved
	 * - approved + chain still has pending entries → pending_approval
	 * - delegated → pending_approval (the slot is re-routed; outside this slice)
	 *
	 * @param string $decision The decision recorded.
	 * @param array<int,mixed> $chain The updated approval chain.
	 * @param string $current Current lifecycle (always `pending_approval`).
	 *
	 * @return string
	 */
	private function nextLifecycleState(string $decision, array $chain, string $current): string {
		if ($decision === self::DECISION_REJECTED) {
			return 'rejected';
		}

		if ($decision === self::DECISION_DELEGATED) {
			return $current;
		}

		$fullyApproved = true;
		foreach ($chain as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$entryDecision = (string)($entry['decision'] ?? '');
			if ($entryDecision !== self::DECISION_APPROVED) {
				$fullyApproved = false;
				break;
			}
		}

		if ($fullyApproved === true) {
			return 'approved';
		}

		return $current;
	}//end nextLifecycleState()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
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
