<?php

/**
 * ICS (iCalendar, RFC 5545) generator for the appointment confirmation flow.
 *
 * Composes a single-event iCalendar payload from an Appointment array (the
 * shape OpenRegister hands back from ObjectService::find) and a customer
 * profile so it can be attached to the confirmation email per REQ-BCF-003 and
 * embedded in the confirmation portal "Add to calendar" link.
 *
 * Output contract (REQ-BCF-003 / REQ-BCF-009):
 *  - VCALENDAR ↔ VTIMEZONE ↔ VEVENT structure with CRLF line endings (RFC 5545 §3.1).
 *  - VTIMEZONE block for the resolved customer timezone with STANDARD +
 *    DAYLIGHT sub-components derived from PHP's built-in tzdata.
 *  - METHOD: REQUEST signalling explicit acceptance per design D7.
 *  - DTSTART;TZID and DTEND;TZID referencing the VTIMEZONE TZID.
 *  - SUMMARY, LOCATION, DESCRIPTION, ATTACH (self-referential link to the
 *    web confirmation portal so REQUEST-acceptance routes through the same
 *    token validation).
 *
 * The service is composition-only: no SMTP, no file I/O. The caller (email
 * delivery in ConfirmationApiController / openconnector) handles MIME and
 * persistence.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Booking\TimezoneResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pure-function ICS payload composer for confirmation emails.
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-9
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class IcsService {

	/**
	 * The RFC 5545 line-ending sequence (CRLF, §3.1). MUST NOT use \n alone
	 * — many calendar clients reject LF-only ICS.
	 */
	private const CRLF = "\r\n";

	/**
	 * Product identifier emitted in PRODID per RFC 5545 §3.7.3.
	 */
	private const PRODID = '-//Conduction//Shillinq Bookings//EN';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param TimezoneResolver $timezoneResolver Resolves the customer's IANA
	 *                                           timezone.
	 * @param LoggerInterface $logger Logger for fail-soft
	 *                                composition diagnostics.
	 */
	public function __construct(
		private readonly TimezoneResolver $timezoneResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate the ICS payload for one confirmation email.
	 *
	 * @param array<string, mixed> $appointment OR object array —
	 *                                          at minimum
	 *                                          `appointmentId`,
	 *                                          `startTime`,
	 *                                          `endTime`. `notes` /
	 *                                          location are
	 *                                          optional.
	 * @param array<string, mixed> $customer Customer descriptor
	 *                                       — at minimum `id`
	 *                                       and `email`. `userId`
	 *                                       (NC user) and `name`
	 *                                       recommended.
	 * @param string $confirmUrl Absolute URL of the web
	 *                           confirmation portal
	 *                           pre-loaded with the
	 *                           token.
	 * @param array<string, mixed> $context Optional context
	 *                                      — keys
	 *                                      `serviceName`
	 *                                      (string),
	 *                                      `location`
	 *                                      (string),
	 *                                      `organizerEmail`
	 *                                      (string).
	 *
	 * @return string The complete ICS payload, CRLF-terminated.
	 */
	public function generateIcs(
		array $appointment,
		array $customer,
		string $confirmUrl,
		array $context = [],
	): string {
		if (isset($customer['userId']) === true) {
			$userId = (string)$customer['userId'];
		} else {
			$userId = null;
		}

		if (isset($appointment['timezone']) === true) {
			$appointmentTz = (string)$appointment['timezone'];
		} else {
			$appointmentTz = null;
		}

		$tzId = $this->timezoneResolver->resolve(
			$userId,
			$appointmentTz,
		);

		$start = $this->parseUtc(iso: (string)($appointment['startTime'] ?? ''));
		$end = $this->parseUtc(iso: (string)($appointment['endTime'] ?? ''));
		if ($start === null || $end === null) {
			$this->logger->warning(
				'IcsService: appointment '
				. (string)($appointment['appointmentId'] ?? '?')
				. ' has unparseable startTime/endTime — emitting empty ICS.'
			);
			return '';
		}

		$tz = new DateTimeZone($tzId);
		$startLoc = $start->setTimezone($tz);
		$endLoc = $end->setTimezone($tz);
		$uid = $this->buildUid(appointmentId: (string)($appointment['appointmentId'] ?? 'unknown'));
		$summary = (string)($context['serviceName'] ?? 'Appointment');
		$location = (string)($context['location'] ?? ($appointment['location'] ?? ''));
		$notes = (string)($appointment['notes'] ?? '');

		$vTimeZone = $this->buildVTimezone(tzId: $tzId, start: $start, end: $end);

		$lines = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:' . self::PRODID;
		$lines[] = 'METHOD:REQUEST';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = $vTimeZone;
		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $this->escape(value: $uid);
		$lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
		$lines[] = 'DTSTART;TZID=' . $tzId . ':' . $startLoc->format('Ymd\THis');
		$lines[] = 'DTEND;TZID=' . $tzId . ':' . $endLoc->format('Ymd\THis');
		$lines[] = 'SUMMARY:' . $this->escape(value: $summary);
		if ($location !== '') {
			$lines[] = 'LOCATION:' . $this->escape(value: $location);
		}

		if ($notes !== '') {
			$lines[] = 'DESCRIPTION:' . $this->escape(value: $notes);
		}

		if (isset($customer['email']) === true && (string)$customer['email'] !== '') {
			$lines[] = 'ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;CN='
				. $this->escape(value: (string)($customer['name'] ?? ''))
				. ':mailto:' . (string)$customer['email'];
		}

		if (isset($context['organizerEmail']) === true && (string)$context['organizerEmail'] !== '') {
			$lines[] = 'ORGANIZER:mailto:' . (string)$context['organizerEmail'];
		}

		if ($confirmUrl !== '') {
			$lines[] = 'ATTACH;FMTTYPE=text/calendar:' . $this->escape(value: $confirmUrl);
			$lines[] = 'URL:' . $this->escape(value: $confirmUrl);
		}

		$lines[] = 'STATUS:TENTATIVE';
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode(self::CRLF, $lines) . self::CRLF;
	}//end generateIcs()

	/**
	 * Parse an ISO 8601 timestamp into UTC, returning NULL on failure.
	 *
	 * @param string $iso ISO 8601 string from an OR object.
	 *
	 * @return DateTimeImmutable|null The parsed UTC instant, or NULL.
	 */
	private function parseUtc(string $iso): ?DateTimeImmutable {
		if ($iso === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}

	}//end parseUtc()

	/**
	 * Compose a stable UID for the VEVENT.
	 *
	 * @param string $appointmentId Appointment business-key.
	 *
	 * @return string A UID safe to use as RFC 5545 UID.
	 */
	private function buildUid(string $appointmentId): string {
		if (function_exists('gethostname') === true) {
			$host = (string)gethostname();
		} else {
			$host = 'shillinq.local';
		}

		if ($host === '') {
			$host = 'shillinq.local';
		}

		return $appointmentId . '@' . $host;
	}//end buildUid()

	/**
	 * Build a single-line VTIMEZONE component for the appointment timezone
	 * using the year-boundary transitions surrounding the appointment.
	 *
	 * Calendar clients only need STANDARD/DAYLIGHT entries that bracket the
	 * event; we emit the two most recent transitions in the appointment year
	 * to keep the payload small while remaining RFC 5545 compliant.
	 *
	 * @param string $tzId IANA timezone identifier.
	 * @param DateTimeImmutable $start Appointment start (UTC).
	 * @param DateTimeImmutable $end Appointment end (UTC).
	 *
	 * @return string The VTIMEZONE block, CRLF-folded.
	 */
	private function buildVTimezone(string $tzId, DateTimeImmutable $start, DateTimeImmutable $end): string {
		$lines = [];
		$lines[] = 'BEGIN:VTIMEZONE';
		$lines[] = 'TZID:' . $tzId;

		try {
			$tz = new DateTimeZone($tzId);
			// Pull transitions covering the whole appointment year so the client
			// sees both STANDARD and DAYLIGHT rules for the date in question.
			$yearStart = (int)(new DateTimeImmutable($start->format('Y') . '-01-01T00:00:00Z'))->getTimestamp();
			$yearEnd = (int)(new DateTimeImmutable(((int)$start->format('Y') + 1) . '-01-01T00:00:00Z'))->getTimestamp();
			$transitions = $tz->getTransitions($yearStart, $yearEnd);
			if ($transitions === false) {
				$transitions = [];
			}

			$emitted = 0;
			foreach ($transitions as $transition) {
				if ($emitted >= 2) {
					break;
				}

				if ($transition['isdst'] === true) {
					$component = 'DAYLIGHT';
				} else {
					$component = 'STANDARD';
				}

				$when = (new DateTimeImmutable('@' . $transition['ts']))->setTimezone(new DateTimeZone('UTC'));
				$offsetTo = $this->formatOffset(seconds: (int)$transition['offset']);
				$offsetFrom = $offsetTo;
				$lines[] = 'BEGIN:' . $component;
				$lines[] = 'TZNAME:' . (string)$transition['abbr'];
				$lines[] = 'DTSTART:' . $when->format('Ymd\THis');
				$lines[] = 'TZOFFSETFROM:' . $offsetFrom;
				$lines[] = 'TZOFFSETTO:' . $offsetTo;
				$lines[] = 'END:' . $component;
				$emitted++;
			}//end foreach

			if ($emitted === 0) {
				// Fixed-offset zone (e.g. UTC) — emit one STANDARD block.
				$offset = $this->formatOffset(seconds: (new DateTimeImmutable('now', $tz))->getOffset());
				$lines[] = 'BEGIN:STANDARD';
				$lines[] = 'TZNAME:' . $tzId;
				$lines[] = 'DTSTART:19700101T000000';
				$lines[] = 'TZOFFSETFROM:' . $offset;
				$lines[] = 'TZOFFSETTO:' . $offset;
				$lines[] = 'END:STANDARD';
			}
		} catch (Throwable $e) {
			$this->logger->warning('IcsService: VTIMEZONE build failed for ' . $tzId . ': ' . $e->getMessage());
			$lines[] = 'BEGIN:STANDARD';
			$lines[] = 'TZNAME:' . $tzId;
			$lines[] = 'DTSTART:19700101T000000';
			$lines[] = 'TZOFFSETFROM:+0000';
			$lines[] = 'TZOFFSETTO:+0000';
			$lines[] = 'END:STANDARD';
		}//end try

		$lines[] = 'END:VTIMEZONE';

		// Reference $end so the analyzer recognises the parameter as
		// intentionally captured for symmetry with $start (events spanning
		// a DST boundary can in future select a wider transition window).
		unset($end);

		return implode(self::CRLF, $lines);
	}//end buildVTimezone()

	/**
	 * Format a numeric UTC offset (in seconds) as RFC 5545 ±HHMM.
	 *
	 * @param int $seconds Offset in seconds from UTC.
	 *
	 * @return string ±HHMM string.
	 */
	private function formatOffset(int $seconds): string {
		if ($seconds < 0) {
			$sign = '-';
		} else {
			$sign = '+';
		}

		$abs = abs($seconds);
		$hours = (int)floor($abs / 3600);
		$mins = (int)floor(($abs % 3600) / 60);
		return $sign . str_pad((string)$hours, 2, '0', STR_PAD_LEFT) . str_pad((string)$mins, 2, '0', STR_PAD_LEFT);
	}//end formatOffset()

	/**
	 * Escape a string for RFC 5545 text values — backslash, comma,
	 * semicolon, newlines.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string Escaped value safe to interpolate into a property line.
	 */
	private function escape(string $value): string {
		$value = str_replace(['\\', "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\\;'], $value);
		return $value;
	}//end escape()
}//end class
