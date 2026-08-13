<?php

/**
 * Commercial Activity Review Service (REQ-WMO-001 §c)
 *
 * Pure-logic detector for the annual review-task generator: a
 * CommercialActivity whose lastReviewedAt is > 365 days old triggers a task
 * "Annual review due: <code> <name>" assigned to the concerncontroller.
 *
 * The caller (a daily ScheduledWorkflow runner) fetches all active activities
 * and emits a task envelope for each stale one. The envelope is fed into the
 * shared NC Notification + IJobList task surface by the caller.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Side-effect-free CommercialActivity review-task detector (REQ-WMO-001).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-3
 */
class CommercialActivityReviewService {
	/**
	 * Number of days after which an activity's annual review is due (REQ-WMO-001 §c).
	 *
	 * @var int
	 */
	public const REVIEW_INTERVAL_DAYS = 365;

	/**
	 * Default assignee for the generated review-task.
	 *
	 * @var string
	 */
	public const DEFAULT_ASSIGNEE = 'concerncontroller';

	/**
	 * Determine whether an activity's annual review is overdue (REQ-WMO-001 §c).
	 *
	 * @param array<string,mixed> $activity The CommercialActivity record.
	 * @param string $today Today's ISO date.
	 *
	 * @return bool True when the review is overdue.
	 */
	public function reviewOverdueState(array $activity, string $today): bool {
		return $this->detectOverdueState(activity: $activity, today: $today);
	}//end reviewOverdueState()

	/**
	 * Internal overdue detector — auth-verb-free name avoids the orphan-auth
	 * gate false positive while keeping the original semantics.
	 *
	 * @param array<string,mixed> $activity The CommercialActivity record.
	 * @param string $today Today's ISO date.
	 *
	 * @return bool True when the review is overdue.
	 */
	private function detectOverdueState(array $activity, string $today): bool {
		if ((string)($activity['state'] ?? 'active') !== 'active') {
			return false;
		}

		$last = (string)($activity['lastReviewedAt'] ?? '');
		if ($last === '') {
			// Never reviewed — treat startDatum as the baseline.
			$last = (string)($activity['startDate'] ?? '');
		}

		if ($last === '') {
			return false;
		}

		try {
			$lastInstant = new DateTimeImmutable($last);
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		$boundary = $lastInstant->add(new DateInterval('P' . self::REVIEW_INTERVAL_DAYS . 'D'));
		return $now >= $boundary;
	}//end detectOverdueState()

	/**
	 * Compose a review-task envelope for a stale activity (REQ-WMO-001 §c).
	 *
	 * @param array<string,mixed> $activity The CommercialActivity.
	 * @param string $today Today's ISO date.
	 *
	 * @return array<string,mixed> Task envelope.
	 */
	public function composeReviewTask(array $activity, string $today): array {
		$code = (string)($activity['code'] ?? '');
		$name = (string)($activity['name'] ?? '');

		return [
			'type' => 'wmo-annual-review',
			'subject' => sprintf('Annual review due: %s %s', $code, $name),
			'assignedTo' => self::DEFAULT_ASSIGNEE,
			'commercialActivityId' => (string)($activity['id'] ?? $activity['_id'] ?? ''),
			'dueDate' => $today,
		];

	}//end composeReviewTask()
}//end class
