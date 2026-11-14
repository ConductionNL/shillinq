<?php

/**
 * Server Write Grant Guard
 *
 * Base for the lifecycle guards that let a transition through only when the
 * owning service saves it under its request-scoped ServerWriteAuthority grant
 * for that very object. A PATCH of the state through the object API carries
 * no grant and is refused with the guard's message.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\ServerWriteAuthority;

/**
 * Allows a transition only under the guard's own write grant on the object.
 *
 * A subclass names its grant in PURPOSE and its refusal in MESSAGE; one that
 * needs more than the grant overrides check() and calls savedUnderGrant().
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
 */
abstract class ServerWriteGrantGuard implements LifecycleGuardInterface {

	/**
	 * The grant the owning service holds while it saves the transition.
	 *
	 * @var string
	 */
	public const PURPOSE = '';

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = '';

	/**
	 * Construct the guard.
	 *
	 * @param ServerWriteAuthority $authority The request-scoped write grants.
	 */
	public function __construct(
		protected readonly ServerWriteAuthority $authority,
	) {
	}//end __construct()

	/**
	 * Allow the transition only under the guard's grant.
	 *
	 * @param array<string, mixed> $object The object as it would be saved.
	 * @param string               $action The transition.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->savedUnderGrant(object: $object, purpose: static::PURPOSE) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(static::MESSAGE);
	}//end check()

	/**
	 * Whether the object is being saved under the given grant: the grant is
	 * held for its id (`id`, else `@self.id`).
	 *
	 * @param array<string, mixed> $object  The object as it would be saved.
	 * @param string               $purpose The grant.
	 *
	 * @return bool
	 */
	protected function savedUnderGrant(array $object, string $purpose): bool {
		$id = (string)($object['id'] ?? ($object['@self']['id'] ?? ''));
		return $this->authority->holds(purpose: $purpose, objectId: $id);
	}//end savedUnderGrant()
}//end class
