<?php

/**
 * Administration Management Access
 *
 * Who may change an administration's own configuration and members: an owner
 * (`eigenaar`) of that administration, or a Nextcloud admin.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IGroupManager;

/**
 * Decides whether the signed-in user manages an administration.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
class AdministrationManagementAccess {

	/**
	 * Constructor.
	 *
	 * @param AdministrationContextService $administrationContext The caller's memberships.
	 * @param IGroupManager $groupManager Nextcloud admin lookup.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AdministrationContextService $administrationContext,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in user may manage the administration.
	 *
	 * An empty administration id is the instance-wide default, which only a
	 * Nextcloud admin manages.
	 *
	 * @param string $administrationId The administration id, or '' for the default.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function canManage(string $administrationId): bool {
		$userId = $this->administrationContext->currentUserId();
		if ($userId === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->administrationContext->isOwnerOf(administrationId: $administrationId);
	}//end canManage()
}//end class
