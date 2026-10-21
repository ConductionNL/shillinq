<?php

/**
 * Role Membership
 *
 * Answers whether the calling user holds one of shillinq's roles. A role is the
 * Nextcloud group `shillinq_<role>`, the same group the schema `authorization`
 * blocks name, so a server-side rule and the object API agree on who holds a
 * role. A Nextcloud administrator holds every role, as OpenRegister admits them.
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
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Resolves the caller's shillinq roles from Nextcloud group membership.
 *
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 */
class RoleMembership {

	/**
	 * Prefix of every shillinq role group.
	 *
	 * @var string
	 */
	public const GROUP_PREFIX = 'shillinq_';

	/**
	 * Construct the resolver.
	 *
	 * @param IUserSession  $userSession  The caller.
	 * @param IGroupManager $groupManager Group membership lookups.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the caller holds at least one of the roles.
	 *
	 * @param array<int, string> $roles Role names, such as `controller`.
	 *
	 * @return bool False for an anonymous caller.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	public function callerHoldsAny(array $roles): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$groups = $this->groupManager->getUserGroupIds($user);
		if (in_array('admin', $groups, true) === true) {
			return true;
		}

		foreach ($roles as $role) {
			if (in_array(self::GROUP_PREFIX . $role, $groups, true) === true) {
				return true;
			}
		}

		return false;
	}//end callerHoldsAny()
}//end class
