<?php

/**
 * Purchase Order Approval Chain Listener
 *
 * Keeps a purchase order's approval chain in the hands of the approval
 * service. A new order may only carry undecided entries, and an existing
 * order's chain may only change while PurchaseOrderApprovalService saves a
 * decision. Without this, a PATCH through the object API could write approved
 * entries for absent approvers and let the next real signature complete the
 * chain.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\PurchaseOrderApprovalGuard;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCA\Shillinq\Service\ServerWriteAuthority;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Refuses approval-chain decisions written outside the approval service.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 */
class PurchaseOrderApprovalChainListener implements IEventListener {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'Approval decisions are recorded by the approvers themselves. Use approve or reject on the purchase order.';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Matches PurchaseOrder in shillinq's register.
	 * @param ServerWriteAuthority   $authority      The request-scoped write grants.
	 * @param IUserSession           $userSession    The caller; system saves pass.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ServerWriteAuthority $authority,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse a decided entry on create, or a changed chain on update, outside the service.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
	 */
	public function handle(Event $event): void {
		if ($this->userSession->getUser() === null) {
			return;
		}

		$refuse = false;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
			$refuse = $this->isPurchaseOrder(entity: $entity) === true
				&& $this->hasDecidedEntry(chain: ($entity->getObject()['approvalChain'] ?? [])) === true;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$new = $event->getNewObject();
			$old = $event->getOldObject();
			if ($new !== null && $old !== null && $this->isPurchaseOrder(entity: $new) === true) {
				$before = ($old->getObject()['approvalChain'] ?? []);
				$after  = ($new->getObject()['approvalChain'] ?? []);
				$id     = (string)($new->getUuid() ?? '');
				$refuse = json_encode($before) !== json_encode($after)
					&& $this->authority->holds(purpose: PurchaseOrderApprovalGuard::PURPOSE, objectId: $id) === false;
			}
		}

		if ($refuse === true) {
			$event->setErrors(['message' => self::MESSAGE, 'field' => 'approvalChain']);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Whether the entity is a PurchaseOrder of shillinq's register.
	 *
	 * @param object|null $entity The object being saved.
	 *
	 * @return bool
	 */
	private function isPurchaseOrder(?object $entity): bool {
		return $entity !== null && $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'PurchaseOrder') === true;
	}//end isPurchaseOrder()

	/**
	 * Whether any chain entry already carries a decision.
	 *
	 * @param mixed $chain The approval chain.
	 *
	 * @return bool
	 */
	private function hasDecidedEntry(mixed $chain): bool {
		foreach ((array)$chain as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$decision = (string)($entry['decision'] ?? ($entry['status'] ?? ''));
			if ($decision !== '' && $decision !== PurchaseOrderApprovalService::DECISION_PENDING) {
				return true;
			}
		}

		return false;
	}//end hasDecidedEntry()
}//end class
