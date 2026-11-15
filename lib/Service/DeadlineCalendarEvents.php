<?php

/**
 * Writes and sweeps the app-owned events on a compliance-deadline calendar.
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

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders deadline VEVENTs, upserts them, and cancels app-owned events that
 * are no longer desired, never touching user-owned events. Built by
 * ComplianceDeadlineCalendarService for one publication.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineCalendarEvents {

	/**
	 * The RFC 5545 line-ending sequence (CRLF, §3.1) — same convention as
	 * {@see IcsService}.
	 */
	private const CRLF = "\r\n";


	/**
	 * PRODID for VEVENTs published by ComplianceDeadlineCalendarService.
	 */
	private const PRODID = '-//Conduction//Shillinq Deadlines//EN';


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
	 * The status and sequence lines of a confirmed (0) or cancelled (1)
	 * VEVENT.
	 *
	 * @var array<int,list<string>>
	 */
	private const STATUS_LINES = [
		0 => ['STATUS:CONFIRMED', 'SEQUENCE:0'],
		1 => ['STATUS:CANCELLED', 'SEQUENCE:1'],
	];

	/**
	 * Construct the writer.
	 *
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Upsert one VEVENT per desired deadline.
	 *
	 * @param object                             $calendar The resolved calendar.
	 * @param array<string,array<string,string>> $desired  The desired deadlines by uid.
	 *
	 * @return int The number published.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function publish(object $calendar, array $desired): int {
		$published = 0;
		foreach ($desired as $deadline) {
			$vevent = $this->buildVevent(deadline: $deadline, cancelled: false);
			$calendar->createFromString($this->uidToFilename(uid: $deadline['uid']), $vevent);
			$published++;
		}

		return $published;
	}//end publish()


	/**
	 * Cancel app-owned VEVENTs that are no longer desired (category toggled
	 * off / source reached a closed state).
	 *
	 * @param object                             $calendar The resolved calendar.
	 * @param array<string,array<string,string>> $desired  The desired deadlines by uid.
	 *
	 * @return int The number cancelled.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function cancelUndesired(object $calendar, array $desired): int {
		$removed = 0;
		foreach ($this->existingAppUids(calendar: $calendar) as $uid) {
			if (isset($desired[$uid]) === false) {
				$calendar->createFromString($this->uidToFilename(uid: $uid), $this->cancelledVevent(uid: $uid));
				$removed++;
			}
		}

		return $removed;
	}//end cancelUndesired()


	/**
	 * The cancelling VEVENT for an app-owned uid, dated today.
	 *
	 * @param string $uid The VEVENT UID.
	 *
	 * @return string
	 */
	private function cancelledVevent(string $uid): string {
		return $this->buildVevent(
			deadline: [
				'uid' => $uid,
				'category' => $this->categoryForUid(uid: $uid),
				'summary' => 'Cancelled',
				'dueDate' => (new DateTimeImmutable('today'))->format('Y-m-d'),
				'source' => '',
				'objectId' => '',
			],
			cancelled: true
		);
	}//end cancelledVevent()


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
	 */
	private function existingAppUids(object $calendar): array {
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


	/**
	 * The category a UID belongs to (derived from its source prefix).
	 *
	 * @param string $uid The VEVENT UID.
	 *
	 * @return string One of the CATEGORY_* constants (best-effort).
	 */
	private function categoryForUid(string $uid): string {
		if (str_starts_with($uid, 'payment-run:') === true) {
			return ComplianceDeadlineCalendarService::CATEGORY_PAYMENT_RUN;
		}

		if (str_starts_with($uid, 'ar-invoice:') === true) {
			return ComplianceDeadlineCalendarService::CATEGORY_AR_DUE;
		}

		if (str_starts_with($uid, 'contract:') === true) {
			return ComplianceDeadlineCalendarService::CATEGORY_CONTRACT;
		}

		return ComplianceDeadlineCalendarService::CATEGORY_FILING;
	}//end categoryForUid()


	/**
	 * Build the all-day VEVENT payload for one deadline (RFC 5545, CRLF).
	 *
	 * The UID is the stable {source}:{objectId} key (REQ-CDC-001) so the
	 * same deadline always maps to the same calendar object (idempotent
	 * upsert via a UID-derived filename). Cancellation is expressed as
	 * STATUS:CANCELLED + SEQUENCE:1 (see class docblock).
	 *
	 * @param array<string,string> $deadline The deadline entry.
	 * @param bool $cancelled Whether to emit a cancelled VEVENT.
	 *
	 * @return string The VCALENDAR payload.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	private function buildVevent(array $deadline, bool $cancelled): string {
		$lines = array_merge(
			['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID, 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT'],
			$this->eventLines(deadline: $deadline),
			self::STATUS_LINES[(int)$cancelled],
			['TRANSP:TRANSPARENT', 'END:VEVENT', 'END:VCALENDAR']
		);

		return implode(self::CRLF, $lines) . self::CRLF;
	}//end buildVevent()

	/**
	 * The UID, stamp, date, summary and category lines of one deadline.
	 *
	 * @param array<string,string> $deadline The deadline entry.
	 *
	 * @return list<string>
	 */
	private function eventLines(array $deadline): array {
		$lines = [
			'UID:' . $this->escapeIcal(value: $deadline['uid']),
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'DTSTART;VALUE=DATE:' . str_replace('-', '', $deadline['dueDate']),
			'SUMMARY:' . $this->escapeIcal(value: $deadline['summary']),
		];
		if ($deadline['category'] !== '') {
			$lines[] = 'CATEGORIES:' . $this->escapeIcal(value: $deadline['category']);
		}

		return $lines;
	}//end eventLines()


	/**
	 * Derive the calendar-object filename for a UID (stable, filesystem-safe).
	 *
	 * @param string $uid The VEVENT UID ({source}:{objectId}).
	 *
	 * @return string The .ics object name.
	 */
	private function uidToFilename(string $uid): string {
		$safe = strtolower((string)preg_replace('/[^A-Za-z0-9\\-]+/', '-', $uid));
		return 'shillinq-' . trim($safe, '-') . '.ics';
	}//end uidToFilename()


	/**
	 * Escape a string for inclusion as an iCalendar text value (RFC 5545)
	 * — same convention as {@see IcsService::escape()}.
	 *
	 * @param string $value The raw text value.
	 *
	 * @return string The escaped value.
	 */
	private function escapeIcal(string $value): string {
		return str_replace(
			['\\', "\r\n", "\n", ',', ';'],
			['\\\\', '\\n', '\\n', '\\,', '\\;'],
			$value
		);

	}//end escapeIcal()
}//end class
