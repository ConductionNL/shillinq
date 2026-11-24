<?php

/**
 * Purchase Order Decision Lock Listener
 *
 * Keeps a purchase order's `approvalChain` history as it is. Purchase order
 * approval runs on OpenRegister's declared approval chain (REQ-PAD-001), so
 * nothing in shillinq writes the in-object chain any more: a new order may
 * not carry one, and an existing order's history may not change through a
 * user's write. Without this, a PATCH through the object API could rewrite
 * who decided an order before the move.
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
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Refuses a user's write of a purchase order's approval-chain history.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
 */
class PurchaseOrderDecisionLockListener implements IEventListener {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'Approval decisions are recorded by the approvers themselves. Use approve or reject on the purchase order.';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Matches PurchaseOrder in shillinq's register.
	 * @param IUserSession                    $userSession    The caller; system saves pass.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse a chain on create, or a changed chain on update, from a user's write.
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
			$refuse = $this->createsChain(event: $event);
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$refuse = $this->changesChain(event: $event);
		}

		if ($refuse === true) {
			$event->setErrors(['message' => self::MESSAGE, 'field' => 'approvalChain']);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Whether a purchase order is created with approval-chain entries.
	 *
	 * @param ObjectCreatingEvent $event The create.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
	 */
	private function createsChain(ObjectCreatingEvent $event): bool {
		$entity = $event->getObject();
		return $this->isPurchaseOrder(entity: $entity) === true
			&& ((array)($entity->getObject()['approvalChain'] ?? [])) !== [];
	}//end createsChain()

	/**
	 * Whether an update changes a purchase order's approval-chain history.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.4
	 */
	private function changesChain(ObjectUpdatingEvent $event): bool {
		$refuse = false;
		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($new !== null && $old !== null && $this->isPurchaseOrder(entity: $new) === true) {
			$before = ($old->getObject()['approvalChain'] ?? []);
			$after  = ($new->getObject()['approvalChain'] ?? []);
			$refuse = json_encode($before) !== json_encode($after);
		}

		return $refuse;
	}//end changesChain()

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
}//end class
