<?php

/**
 * WBSO RBAC Resolver
 *
 * Resolves the WBSO bookkeeping roles (REQ-WBSO-005) from Nextcloud group
 * membership: `bookkeeper`, `auditor`, `administrator`. The mapping is
 * controlled in lib/Settings/shillinq_register.json's
 * x-openregister-rbac block; this PHP-side resolver mirrors the same
 * group → role correspondence for the three new API controllers so they can
 * surface a 403 response with a meaningful audit-trail entry before touching
 * OpenRegister.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-30
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Maps Nextcloud groups + admin status to the WBSO roles (REQ-WBSO-005).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-30
 */
class WbsoRbacResolver {

	/**
	 * Nextcloud group → WBSO role.
	 *
	 * @var array<string,string>
	 */
	public const GROUP_TO_ROLE = [
		'shillinq_bookkeeper' => 'bookkeeper',
		'shillinq_auditor' => 'auditor',
		'shillinq_admin' => 'administrator',
		'bookkeeper' => 'bookkeeper',
		'auditor' => 'auditor',
	];

	/**
	 * Construct the resolver.
	 *
	 * @param IUserSession $userSession Active session.
	 * @param IGroupManager $groupManager Group membership lookups.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Return the WBSO roles the currently-authenticated user holds.
	 *
	 * Returns an empty array when no user is authenticated.
	 *
	 * @return array<int,string>
	 */
	public function currentRoles(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [];
		}

		$roles = [];
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			$roles[] = 'administrator';
		}

		foreach (array_keys(self::GROUP_TO_ROLE) as $group) {
			if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
				$role = self::GROUP_TO_ROLE[$group];
				if (in_array($role, $roles, true) === false) {
					$roles[] = $role;
				}
			}
		}

		// Authenticated users without any explicit role default to bookkeeper
		// for read scope; write permissions still require an explicit role.
		if ($roles === []) {
			$roles[] = 'bookkeeper';
		}

		return $roles;
	}//end currentRoles()

	/**
	 * Check whether the current user holds any of the listed roles.
	 *
	 * @param array<int,string> $allowed Roles allowed for the action.
	 *
	 * @return bool
	 */
	public function hasAny(array $allowed): bool {
		$roles = $this->currentRoles();
		foreach ($roles as $role) {
			if (in_array($role, $allowed, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasAny()

	/**
	 * Convenience: true iff the user can write at all (bookkeeper or admin).
	 *
	 * @return bool
	 */
	public function canCreate(): bool {
		return $this->hasAny(allowed: ['bookkeeper', 'administrator']);
	}//end canCreate()
}//end class
