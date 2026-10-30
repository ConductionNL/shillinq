<?php

/**
 * Report Recipient Policy
 *
 * A scheduled report is produced as its owner and delivered to its
 * recipients, so a recipient receives what the owner may read. A recipient is
 * therefore allowed only when they may read the administration themselves: a
 * user with access to the administration, or a group the owner belongs to whose
 * every member has that access.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Schedule;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IGroupManager;

/**
 * Splits a schedule's recipients into those the owner may share with and the rest.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
 */
class ReportRecipientPolicy {

	/**
	 * Construct the policy.
	 *
	 * @param AdministrationContextService $administrations Who may read an administration.
	 * @param IGroupManager|null           $groupManager    Group lookups; without it no group is allowed.
	 */
	public function __construct(
		private readonly AdministrationContextService $administrations,
		private readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()

	/**
	 * The recipients that are not allowed.
	 *
	 * @param string             $ownerId          The schedule's owner.
	 * @param string             $administrationId The administration reported on.
	 * @param array<int, string> $recipients       `user:<id>` and `group:<id>` entries.
	 *
	 * @return array<int, string> The refused entries, empty when all are allowed.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
	 */
	public function refused(string $ownerId, string $administrationId, array $recipients): array {
		$refused = [];
		foreach ($recipients as $recipient) {
			if ($this->allowed(ownerId: $ownerId, administrationId: $administrationId, recipient: (string)$recipient) === false) {
				$refused[] = (string)$recipient;
			}
		}

		return $refused;
	}//end refused()

	/**
	 * Whether one recipient is allowed.
	 *
	 * @param string $ownerId          The schedule's owner.
	 * @param string $administrationId The administration reported on.
	 * @param string $recipient        A `user:<id>` or `group:<id>` entry.
	 *
	 * @return bool
	 */
	private function allowed(string $ownerId, string $administrationId, string $recipient): bool {
		if (str_starts_with($recipient, 'user:') === true) {
			return $this->administrations->canUserAccess(userId: substr($recipient, 5), administrationId: $administrationId);
		}

		if (str_starts_with($recipient, 'group:') === false || $this->groupManager === null) {
			return false;
		}

		$group = $this->groupManager->get(substr($recipient, 6));
		if ($group === null || $this->groupManager->isInGroup($ownerId, $group->getGID()) === false) {
			return false;
		}

		foreach ($group->getUsers() as $member) {
			if ($this->administrations->canUserAccess(userId: $member->getUID(), administrationId: $administrationId) === false) {
				return false;
			}
		}

		return true;
	}//end allowed()
}//end class
