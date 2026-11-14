<?php

/**
 * Payment Block Release Listener
 *
 * A payment block on a supplier invoice (APTransaction) or a supplier (Payee)
 * stops every payment run from paying it. Clearing the block is a money
 * control, so it is enforced on the save path whatever client writes it: only
 * an administrator, a controller or a treasurer may release a block, the
 * release needs its own reason, given in the same save, and the reason the
 * block was set for is kept so the record still says why it was blocked.
 * Setting a block and every other edit are unchanged.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#2-state-guards
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Refuses a payment block release without the role, without a reason, or that drops the block reason.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#2-state-guards
 */
class PaymentBlockReleaseListener implements IEventListener {

	/**
	 * The schemas that carry a payment block.
	 */
	public const SCHEMAS = ['APTransaction', 'Payee'];

	/**
	 * The groups whose members may release a block, besides administrators.
	 */
	public const RELEASE_GROUPS = ['shillinq_controller', 'shillinq_treasurer'];

	/**
	 * The property holding why a block was released.
	 */
	public const RELEASE_REASON = 'paymentBlockReleaseReason';

	public const MESSAGE_ROLE = 'Only a controller or a treasurer can release a payment block.';

	public const MESSAGE_REASON = 'Give a reason for releasing the payment block.';

	public const MESSAGE_KEEP = 'Releasing a payment block keeps the reason it was blocked for.';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells an APTransaction or Payee apart.
	 * @param IUserSession                    $userSession    The caller.
	 * @param IGroupManager                   $groupManager   The caller's role groups.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#2-state-guards
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse a release that the caller may not make, gives no reason, or drops the block reason.
	 *
	 * @param Event $event The object updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#2-state-guards
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

		$before = $old->getObject();
		$after  = $new->getObject();
		if (($before['paymentBlocked'] ?? false) !== true || ($after['paymentBlocked'] ?? false) === true) {
			return;
		}

		if ($this->isBlockable(entity: $new) === false) {
			return;
		}

		$refusal = $this->refusal(before: $before, after: $after);
		if ($refusal !== null) {
			$event->setErrors($refusal);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why a release is refused, or null when it may go through.
	 *
	 * @param array<string, mixed> $before The object before the save.
	 * @param array<string, mixed> $after  The object as it would be saved.
	 *
	 * @return array{message: string, field: string}|null
	 */
	private function refusal(array $before, array $after): ?array {
		if ($this->mayRelease() === false) {
			return ['message' => self::MESSAGE_ROLE, 'field' => 'paymentBlocked'];
		}

		$reason = trim((string)($after[self::RELEASE_REASON] ?? ''));
		if ($reason === '' || $reason === trim((string)($before[self::RELEASE_REASON] ?? ''))) {
			return ['message' => self::MESSAGE_REASON, 'field' => self::RELEASE_REASON];
		}

		if ((string)($after['paymentBlockReason'] ?? '') !== (string)($before['paymentBlockReason'] ?? '')) {
			return ['message' => self::MESSAGE_KEEP, 'field' => 'paymentBlockReason'];
		}

		return null;
	}//end refusal()

	/**
	 * Whether the caller is an administrator, a controller or a treasurer.
	 *
	 * A save without a user is a system write (a background job or a lifecycle
	 * action), which the role check does not apply to; the reason rules still do.
	 *
	 * @return bool
	 */
	private function mayRelease(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return true;
		}

		$userId = $user->getUID();
		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::RELEASE_GROUPS as $group) {
			if ($this->groupManager->isInGroup($userId, $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayRelease()

	/**
	 * Whether the object is a supplier invoice or a supplier.
	 *
	 * @param object $entity The object being saved.
	 *
	 * @return bool
	 */
	private function isBlockable(object $entity): bool {
		foreach (self::SCHEMAS as $slug) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $slug) === true) {
				return true;
			}
		}

		return false;
	}//end isBlockable()
}//end class
