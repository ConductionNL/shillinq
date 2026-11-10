<?php

/**
 * Finds the app-owned events already on a deadline calendar.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the app-owned, non-cancelled VEVENT UIDs on a calendar, so the stale
 * sweep of ComplianceDeadlineCalendarService, which builds it, never touches
 * user-owned events.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineCalendarSweep {

	/**
	 * UID source prefixes owned by ComplianceDeadlineCalendarService — used to recognise
	 * app-published VEVENTs when computing the stale set. The contract
	 * prefix is shared with {@see ObligationTaskBridge} (one home).
	 *
	 * @var array<int,string>
	 */
	private const UID_PREFIXES = [
		'btw-filing:',
		'icp-filing:',
		'vpb-filing:',
		'payment-run:',
		'ar-invoice:',
		'contract:',
	];

	/**
	 * Construct the sweep.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List the app-owned VEVENT UIDs already present on a calendar.
	 *
	 * Uses ICalendar::search() when available; only UIDs carrying one of
	 * the app's source prefixes are returned so user-owned events are
	 * never touched. Cancelled events are skipped (already removed).
	 *
	 * @param object $calendar The resolved calendar.
	 *
	 * @return array<int,string> App-owned, non-cancelled VEVENT UIDs.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function existingAppUids(object $calendar): array {
		$uids = [];
		foreach ($this->searchVevents(calendar: $calendar) as $result) {
			$uid = $this->liveAppUid(result: $result);
			if ($uid !== null) {
				$uids[] = $uid;
			}
		}

		return array_values(array_unique($uids));
	}//end existingAppUids()

	/**
	 * The calendar's VEVENT search results, or [] when the calendar cannot
	 * search or the search fails.
	 *
	 * @param object $calendar The resolved calendar.
	 *
	 * @return array<mixed>
	 */
	private function searchVevents(object $calendar): array {
		if (method_exists($calendar, 'search') === false) {
			return [];
		}

		try {
			$results = $calendar->search('', ['UID'], ['types' => ['VEVENT']]);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ComplianceDeadlineCalendarService: calendar search unavailable — skipping stale sweep',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		return $results;
	}//end searchVevents()

	/**
	 * The UID of a search result when it is an app-owned, non-cancelled event.
	 *
	 * @param mixed $result One ICalendar::search() result.
	 *
	 * @return string|null
	 */
	private function liveAppUid(mixed $result): ?string {
		if (is_array($result) === false) {
			return null;
		}

		$uid = (string)($result['uid'] ?? '');
		if ($uid === '' || $this->isAppUid(uid: $uid) === false || $this->resultIsCancelled(result: $result) === true) {
			return null;
		}

		return $uid;
	}//end liveAppUid()

	/**
	 * Whether a UID carries one of the app's source prefixes.
	 *
	 * @param string $uid The VEVENT UID.
	 *
	 * @return bool True when the UID is app-owned.
	 */
	private function isAppUid(string $uid): bool {
		foreach (self::UID_PREFIXES as $prefix) {
			if (str_starts_with($uid, $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end isAppUid()

	/**
	 * Whether a calendar search result row represents a cancelled event.
	 *
	 * @param array<string,mixed> $result One ICalendar::search() result.
	 *
	 * @return bool True when every contained object is STATUS:CANCELLED.
	 */
	private function resultIsCancelled(array $result): bool {
		$objects = ($result['objects'] ?? null);
		if (is_array($objects) === false || $objects === []) {
			return false;
		}

		foreach ($objects as $object) {
			if ($this->isCancelledObject(object: $object) === false) {
				return false;
			}
		}

		return true;
	}//end resultIsCancelled()

	/**
	 * Whether one contained calendar object carries STATUS:CANCELLED.
	 *
	 * @param mixed $object One object of a search result.
	 *
	 * @return bool
	 */
	private function isCancelledObject(mixed $object): bool {
		if (is_array($object) === false) {
			return false;
		}

		$status = ($object['STATUS'][0] ?? null);
		return is_string($status) === true && strtoupper($status) === 'CANCELLED';
	}//end isCancelledObject()
}//end class
