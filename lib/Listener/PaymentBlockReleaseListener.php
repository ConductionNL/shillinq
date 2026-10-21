<?php

/**
 * Payment Block Release Listener
 *
 * A payment block on an AP transaction or a supplier is a browser PATCH of
 * `paymentBlocked`. Blocking is safe and stays open to anyone who may update
 * the record, with a reason. Releasing lets money leave, so it needs the
 * controller or treasurer role and a release reason, and the reason the block
 * was set for is kept.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\RoleMembership;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Enforces the role and reasons on a payment block and its release.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.2
 */
class PaymentBlockReleaseListener implements IEventListener {

	/**
	 * Schemas that carry a payment block.
	 *
	 * @var array<int, string>
	 */
	public const SCHEMAS = ['APTransaction', 'Payee'];

	/**
	 * Roles that may release a block.
	 *
	 * @var array<int, string>
	 */
	public const RELEASE_ROLES = ['controller', 'treasurer'];

	/**
	 * Refusal for a release without the role.
	 *
	 * @var string
	 */
	public const MESSAGE_ROLE = 'Only a controller or treasurer can release a payment block.';

	/**
	 * Refusal for a release without a reason.
	 *
	 * @var string
	 */
	public const MESSAGE_RELEASE_REASON = 'Give a reason for releasing the payment block.';

	/**
	 * Refusal for a release that drops the block reason.
	 *
	 * @var string
	 */
	public const MESSAGE_KEEP_REASON = 'The reason the payment was blocked is kept when the block is released.';

	/**
	 * Refusal for a block without a reason.
	 *
	 * @var string
	 */
	public const MESSAGE_BLOCK_REASON = 'Give a reason for the payment block.';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Matches the blockable schemas in shillinq's register.
	 * @param RoleMembership         $roles          The caller's roles.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly RoleMembership $roles,
	) {
	}//end __construct()

	/**
	 * Check a block or a release.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.2
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		$new = $event->getNewObject();
		if ($old === null || $new === null) {
			return;
		}

		$before     = $old->getObject();
		$after      = $new->getObject();
		$wasBlocked = (($before['paymentBlocked'] ?? false) === true);
		$isBlocked  = (($after['paymentBlocked'] ?? false) === true);
		if ($wasBlocked === $isBlocked || $this->isBlockable(entity: $new) === false) {
			return;
		}

		$error = $this->refusal(before: $before, after: $after, isBlocked: $isBlocked);
		if ($error !== null) {
			$event->setErrors(['message' => $error, 'field' => 'paymentBlocked']);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why a block or release is refused, or null.
	 *
	 * @param array<string, mixed> $before    Stored record.
	 * @param array<string, mixed> $after     Record as it would be saved.
	 * @param bool                 $isBlocked Whether the save blocks (true) or releases (false).
	 *
	 * @return string|null
	 */
	private function refusal(array $before, array $after, bool $isBlocked): ?string {
		if ($isBlocked === true) {
			if (trim((string)($after['paymentBlockReason'] ?? '')) === '') {
				return self::MESSAGE_BLOCK_REASON;
			}

			return null;
		}

		if ($this->roles->callerHoldsAny(roles: self::RELEASE_ROLES) === false) {
			return self::MESSAGE_ROLE;
		}

		if (trim((string)($after['paymentBlockReleaseReason'] ?? '')) === '') {
			return self::MESSAGE_RELEASE_REASON;
		}

		if ((string)($before['paymentBlockReason'] ?? '') !== (string)($after['paymentBlockReason'] ?? '')) {
			return self::MESSAGE_KEEP_REASON;
		}

		return null;
	}//end refusal()

	/**
	 * Whether the entity is an APTransaction or Payee of shillinq's register.
	 *
	 * @param object $entity The object being saved.
	 *
	 * @return bool
	 */
	private function isBlockable(object $entity): bool {
		foreach (self::SCHEMAS as $schema) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $schema) === true) {
				return true;
			}
		}

		return false;
	}//end isBlockable()
}//end class
