<?php

/**
 * Commitment Materialisation Listener.
 *
 * REQ-VPL-010 (Tasks 1+2) — thin event-glue that reacts to a PurchaseOrder
 * reaching `approved` or a Contract reaching `active`, and forwards to
 * {@see CommitmentMaterialisationService} to auto-materialise the matching
 * `Commitment`. Contains no budget/mandate/assembly logic itself.
 *
 * PO path is fail-closed: {@see InsufficientCommitmentBudgetException} is
 * allowed to propagate. OR dispatches `ObjectTransitionedEvent` /
 * `ObjectCreatedEvent` synchronously in the same write path as the
 * originating PurchaseOrderApprovalService::saveObject() call (no queue),
 * so an uncaught exception here surfaces back to the approval caller as a
 * failed request — the mechanism REQ-VPL-010's "insufficient budget blocks
 * the approval, not just the invoice" scenario relies on. This is a
 * best-effort synchronous guarantee (see tasks.md Deviations): if OR
 * persists the PurchaseOrder's `approved` status before dispatching the
 * event, the PO write itself is not automatically rolled back by this
 * exception; only the erroring response is guaranteed.
 *
 * Contract path is fail-soft: any exception (including one that somehow
 * escapes the service's own internal catch) is caught here too, defence
 * in depth, so a Contract activation is never blocked by this listener.
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
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\Commitment\CommitmentMaterialisationService;
use OCA\Shillinq\Service\Commitment\InsufficientCommitmentBudgetException;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reacts to PO-approved / Contract-active OR lifecycle events and forwards
 * to CommitmentMaterialisationService (REQ-VPL-010).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class CommitmentMaterialisationListener implements IEventListener {
	/**
	 * Construct the listener.
	 *
	 * @param CommitmentMaterialisationService $materialiser Thin materialisation service.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly CommitmentMaterialisationService $materialiser,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OR ObjectCreatedEvent or ObjectTransitionedEvent for the
	 * PurchaseOrder (-> approved) or Contract (-> active) schemas.
	 *
	 * @param Event $event OR object lifecycle event.
	 *
	 * @return void
	 *
	 * @throws InsufficientCommitmentBudgetException When a PO approval denies for insufficient budget (fail-closed).
	 *
	 * @listener-placement inline correctness — this handler must run INSIDE the
	 *   write it observes, because the write's outcome depends on it. OR
	 *   dispatches ObjectCreatedEvent synchronously in the same path as
	 *   PurchaseOrderApprovalService::saveObject(), so an
	 *   InsufficientCommitmentBudgetException raised here propagates back to the
	 *   approval caller and fails the request. That propagation IS the mechanism
	 *   REQ-VPL-010 relies on for "insufficient budget blocks the approval, not
	 *   just the invoice". Deferring this through ListenerDeferralService would
	 *   let the approval succeed and surface the denial later, which is the
	 *   opposite of the specified behaviour — so the latency is the price of the
	 *   guarantee rather than an oversight.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function handle(Event $event): void {
		$entity = $this->resolveEntity(event: $event);
		if ($entity === null) {
			return;
		}

		$schema = strtolower(trim($this->schemaResolver->schemaSlug(entity: $entity)));
		$payload = $entity->getObject();
		if (is_array($payload) === false) {
			return;
		}

		$isPurchaseOrder = str_ends_with(haystack: $schema, needle: 'purchaseorder') === true
			&& str_ends_with(haystack: $schema, needle: 'purchaseorderline') === false;
		$isContract = $isPurchaseOrder === false && str_ends_with(haystack: $schema, needle: 'contract') === true;

		if ($isPurchaseOrder === false && $isContract === false) {
			return;
		}

		$targetState = 'active';
		if ($isPurchaseOrder === true) {
			$targetState = 'approved';
		}

		if ($this->reachedState(event: $event, targetState: $targetState) === false) {
			return;
		}

		if ($isPurchaseOrder === true) {
			// Fail-closed: let InsufficientCommitmentBudgetException propagate.
			$this->materialiser->materialiseFromPurchaseOrder(purchaseOrder: $payload);
			return;
		}

		try {
			$this->materialiser->materialiseFromContract(contract: $payload);
		} catch (Throwable $e) {
			// Defence in depth — the service itself is fail-soft on this path.
			$this->logger->warning(
				'CommitmentMaterialisationListener: contract materialisation failed — fail-soft',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Resolve the carrying OR entity for a created/transitioned object.
	 *
	 * @param Event $event OR event.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null
	 */
	private function resolveEntity(Event $event): ?\OCA\OpenRegister\Db\ObjectEntity {
		if ($event instanceof ObjectCreatedEvent === true) {
			return $event->getObject();
		}

		if ($event instanceof ObjectTransitionedEvent === true) {
			return $event->getObject();
		}

		return null;
	}//end resolveEntity()

	/**
	 * Whether this event represents the schema reaching the given
	 * materialisation trigger state. ObjectCreatedEvent is checked against
	 * the payload's own status field (a record created directly in the
	 * target state); ObjectTransitionedEvent is checked against the
	 * transition's `to` state.
	 *
	 * @param Event $event OR event.
	 * @param string $targetState Trigger state ('approved' for PurchaseOrder, 'active' for Contract).
	 *
	 * @return bool
	 */
	private function reachedState(Event $event, string $targetState): bool {
		if ($event instanceof ObjectTransitionedEvent === true) {
			return $event->getTo() === $targetState;
		}

		if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();

			$payload = null;
			if ($entity !== null) {
				$payload = $entity->getObject();
			}

			$status = '';
			if (is_array($payload) === true) {
				$status = (string)($payload['statusCode'] ?? ($payload['status'] ?? ''));
			}

			return $status === $targetState;
		}

		return false;
	}//end reachedState()
}//end class
