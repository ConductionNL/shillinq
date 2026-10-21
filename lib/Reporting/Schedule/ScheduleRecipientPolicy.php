<?php

/**
 * Schedule Recipient Policy
 *
 * Decides which recipients a report schedule may deliver to: a user who has
 * access to the schedule's administration, or a group the owner belongs to.
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
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Schedule;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Limits schedule recipients to what the owner may share.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
class ScheduleRecipientPolicy {

	/**
	 * Constructor.
	 *
	 * @param IUserManager                 $userManager     Resolves user recipients.
	 * @param IGroupManager                $groupManager    Resolves group recipients and the owner's groups.
	 * @param AdministrationContextService $administrations Answers administration access per user.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly AdministrationContextService $administrations,
	) {

	}//end __construct()

	/**
	 * The recipients the owner may not deliver this administration's reports to.
	 *
	 * @param string        $ownerId          The schedule owner.
	 * @param string        $administrationId The schedule's administration.
	 * @param array<string> $recipients       user:<id> and group:<id> entries.
	 *
	 * @return list<string> The refused entries, in the given order.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function refused(string $ownerId, string $administrationId, array $recipients): array {
		$refused = [];
		foreach ($recipients as $recipient) {
			if ($this->mayReceive(ownerId: $ownerId, administrationId: $administrationId, recipient: (string)$recipient) === false) {
				$refused[] = (string)$recipient;
			}
		}

		return $refused;

	}//end refused()

	/**
	 * Whether one recipient may receive the owner's reports of an administration.
	 *
	 * @param string $ownerId          The schedule owner.
	 * @param string $administrationId The schedule's administration.
	 * @param string $recipient        user:<id> or group:<id>.
	 *
	 * @return bool True when allowed.
	 */
	private function mayReceive(string $ownerId, string $administrationId, string $recipient): bool {
		$parts = explode(':', $recipient, 2);
		if (count($parts) !== 2 || $parts[1] === '') {
			return false;
		}

		[$kind, $id] = $parts;
		if ($kind === 'user') {
			return $this->userManager->userExists($id) === true
				&& $this->administrations->canUserAccess(userId: $id, administrationId: $administrationId) === true;
		}

		if ($kind === 'group') {
			return $this->groupManager->groupExists($id) === true
				&& $this->groupManager->isInGroup($ownerId, $id) === true;
		}

		return false;

	}//end mayReceive()
}//end class
