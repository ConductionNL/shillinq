<?php

/**
 * Humaniq Billable Hours
 *
 * The hours source of the invoice generator once humaniq keeps the hours
 * (sales-time-and-expense-billing task 2.3, REQ-STEB-001): approved, billable
 * humaniq time entries on a project, read through {@see HumaniqHoursReader}.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

use OCA\Shillinq\Service\HumaniqHoursReader;
use OCP\App\IAppManager;

/**
 * Reads a project's billable hours from humaniq.
 *
 * ABSENT IS NOT ZERO: when humaniq is not installed, or does not answer, the
 * hours are reported unavailable with the reason, never as an empty list.
 * Which hours are already on an invoice is decided by shillinq itself
 * ({@see BillableWorkService}), so {@see unbilled()} and {@see forProject()}
 * read the same entries.
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
 */
class HumaniqBillableHours implements BillableHoursSource {

	/**
	 * The app that keeps the hours.
	 */
	public const HUMANIQ = 'humaniq';

	/**
	 * The reason while humaniq is not installed.
	 */
	public const REASON_NOT_INSTALLED = 'humaniq-not-installed';

	/**
	 * The reason while humaniq is installed but its hours cannot be read.
	 */
	public const REASON_UNREADABLE = 'humaniq-unreadable';

	/**
	 * The approval state of hours that may be billed.
	 */
	private const APPROVED = 'approved';

	/**
	 * Wire collaborators.
	 *
	 * @param HumaniqHoursReader $reader     The humaniq hours read.
	 * @param IAppManager        $appManager Whether humaniq is installed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	public function __construct(
		private readonly HumaniqHoursReader $reader,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The project's approved, billable hours in the period.
	 *
	 * @param string $projectId The project.
	 * @param string $from      The first day, `Y-m-d`, inclusive.
	 * @param string $to        The last day, `Y-m-d`, inclusive.
	 *
	 * @return array{available: bool, reason: string, hours: list<array{id: string, person: string, date: string, hours: float, description: string}>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	public function unbilled(string $projectId, string $from, string $to): array {
		return $this->read(projectId: $projectId, from: $from, to: $to);
	}//end unbilled()

	/**
	 * The project's approved, billable hours in the period, billed or not.
	 *
	 * @param string $projectId The project.
	 * @param string $from      The first day, `Y-m-d`, inclusive.
	 * @param string $to        The last day, `Y-m-d`, inclusive.
	 *
	 * @return array{available: bool, reason: string, hours: list<array{id: string, person: string, date: string, hours: float, description: string}>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	public function forProject(string $projectId, string $from, string $to): array {
		return $this->read(projectId: $projectId, from: $from, to: $to);
	}//end forProject()

	/**
	 * Read humaniq and keep the approved, billable entries that carry hours.
	 *
	 * @param string $projectId The project.
	 * @param string $from      The first day.
	 * @param string $to        The last day.
	 *
	 * @return array{available: bool, reason: string, hours: list<array{id: string, person: string, date: string, hours: float, description: string}>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.3
	 */
	private function read(string $projectId, string $from, string $to): array {
		if ($this->appManager->isEnabledForAnyone(self::HUMANIQ) !== true) {
			return ['available' => false, 'reason' => self::REASON_NOT_INSTALLED, 'hours' => []];
		}

		$entries = $this->reader->entriesForProject(projectId: $projectId, from: $from, until: $to);
		if ($entries === null) {
			return ['available' => false, 'reason' => self::REASON_UNREADABLE, 'hours' => []];
		}

		$hours = [];
		foreach ($entries as $entry) {
			// An entry without a number is not zero hours: it is left out, not billed at 0.
			if (($entry['billable'] ?? false) !== true
				|| ($entry['approvalState'] ?? '') !== self::APPROVED
				|| is_float($entry['hours'] ?? null) === false
			) {
				continue;
			}

			$hours[] = [
				'id' => (string)$entry['uuid'],
				'person' => (string)($entry['personId'] ?? ''),
				'date' => (string)($entry['date'] ?? ''),
				'hours' => $entry['hours'],
				'description' => (string)($entry['description'] ?? ''),
			];
		}

		usort($hours, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

		return ['available' => true, 'reason' => '', 'hours' => $hours];
	}//end read()
}//end class
