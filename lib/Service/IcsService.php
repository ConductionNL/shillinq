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
	 *
	 * @spec openspec/specs/bookings-confirm-flow/spec.md#req-bcf-003
	 */
	public function generateIcs(array $appointment, array $customer, string $confirmUrl, array $context = []): string {
		$tzId = $this->timezoneResolver->resolve(
			$this->optionalString(values: $customer, key: 'userId'),
			$this->optionalString(values: $appointment, key: 'timezone'),
		);

		$window = $this->appointmentWindow(appointment: $appointment);
		if ($window === null) {
			return '';
		}

		$ics   = $window + ['appointment' => $appointment, 'customer' => $customer, 'confirmUrl' => $confirmUrl, 'context' => $context, 'tzId' => $tzId];
		$lines = array_merge($this->calendarHeaderLines(ics: $ics), $this->eventLines(ics: $ics), ['END:VCALENDAR']);

		return implode(self::CRLF, $lines) . self::CRLF;
	}//end generateIcs()

	/**
	 * A value as a string, null when it is not set.
	 *
	 * @param array<string, mixed> $values The values.
	 * @param string $key The key.
	 *
	 * @return string|null
	 */
	private function optionalString(array $values, string $key): ?string {
		if (isset($values[$key]) === false) {
			return null;
		}

		return (string)$values[$key];
	}//end optionalString()

	/**
	 * The appointment's start and end in UTC; null, with a warning, when
	 * either cannot be parsed.
	 *
	 * @param array<string, mixed> $appointment The appointment descriptor.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
	 */
	private function appointmentWindow(array $appointment): ?array {
		$start = $this->parseUtc(iso: (string)($appointment['startTime'] ?? ''));
		$end   = $this->parseUtc(iso: (string)($appointment['endTime'] ?? ''));
		if ($start !== null && $end !== null) {
			return ['start' => $start, 'end' => $end];
		}

		$this->logger->warning(
			'IcsService: appointment '
			. (string)($appointment['appointmentId'] ?? '?')
			. ' has unparseable startTime/endTime — emitting empty ICS.'
		);
		return null;
	}//end appointmentWindow()

	/**
	 * The VCALENDAR opening lines, including the VTIMEZONE block.
	 *
	 * @param array<string, mixed> $ics The invitation inputs.
	 *
	 * @return array<int, string>
	 */
	private function calendarHeaderLines(array $ics): array {
		return [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'METHOD:REQUEST',
			'CALSCALE:GREGORIAN',
			$this->buildVTimezone(tzId: $ics['tzId'], start: $ics['start'], end: $ics['end']),
		];
	}//end calendarHeaderLines()

	/**
	 * The VEVENT block.
	 *
	 * @param array<string, mixed> $ics The invitation inputs.
	 *
	 * @return array<int, string>
	 */
	private function eventLines(array $ics): array {
		return array_merge(
			$this->eventTimingLines(ics: $ics),
			$this->propertyLine(name: 'LOCATION', value: (string)($ics['context']['location'] ?? ($ics['appointment']['location'] ?? ''))),
			$this->propertyLine(name: 'DESCRIPTION', value: (string)($ics['appointment']['notes'] ?? '')),
			$this->attendeeLines(customer: $ics['customer']),
			$this->organizerLines(context: $ics['context']),
			$this->confirmLines(confirmUrl: $ics['confirmUrl']),
			['STATUS:TENTATIVE', 'TRANSP:OPAQUE', 'END:VEVENT'],
		);
	}//end eventLines()

	/**
	 * The VEVENT opening: uid, stamp, local start and end, summary.
	 *
	 * @param array<string, mixed> $ics The invitation inputs.
	 *
	 * @return array<int, string>
	 */
	private function eventTimingLines(array $ics): array {
		$tz = new DateTimeZone($ics['tzId']);

		return [
			'BEGIN:VEVENT',
			'UID:' . $this->escape(value: $this->buildUid(appointmentId: (string)($ics['appointment']['appointmentId'] ?? 'unknown'))),
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'DTSTART;TZID=' . $ics['tzId'] . ':' . $ics['start']->setTimezone($tz)->format('Ymd\THis'),
			'DTEND;TZID=' . $ics['tzId'] . ':' . $ics['end']->setTimezone($tz)->format('Ymd\THis'),
			'SUMMARY:' . $this->escape(value: (string)($ics['context']['serviceName'] ?? 'Appointment')),
		];
	}//end eventTimingLines()

	/**
	 * One escaped property line, none when the value is empty.
	 *
	 * @param string $name The property name.
	 * @param string $value The property value.
	 *
	 * @return array<int, string>
	 */
	private function propertyLine(string $name, string $value): array {
		if ($value === '') {
			return [];
		}

		return [$name . ':' . $this->escape(value: $value)];
	}//end propertyLine()

	/**
	 * The ATTENDEE line, when the customer has an email address.
	 *
	 * @param array<string, mixed> $customer The customer descriptor.
	 *
	 * @return array<int, string>
	 */
	private function attendeeLines(array $customer): array {
		if (isset($customer['email']) === false || (string)$customer['email'] === '') {
			return [];
		}

		return [
			'ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;CN='
			. $this->escape(value: (string)($customer['name'] ?? ''))
			. ':mailto:' . (string)$customer['email'],
		];
	}//end attendeeLines()

	/**
	 * The ORGANIZER line, when the context names an organizer email.
	 *
	 * @param array<string, mixed> $context The invitation context.
	 *
	 * @return array<int, string>
	 */
	private function organizerLines(array $context): array {
		if (isset($context['organizerEmail']) === false || (string)$context['organizerEmail'] === '') {
			return [];
		}

		return ['ORGANIZER:mailto:' . (string)$context['organizerEmail']];
	}//end organizerLines()

	/**
	 * The ATTACH and URL lines for the confirmation portal, when there is one.
	 *
	 * @param string $confirmUrl The confirmation URL.
	 *
	 * @return array<int, string>
	 */
	private function confirmLines(string $confirmUrl): array {
		if ($confirmUrl === '') {
			return [];
		}

		return [
			'ATTACH;FMTTYPE=text/calendar:' . $this->escape(value: $confirmUrl),
			'URL:' . $this->escape(value: $confirmUrl),
		];
	}//end confirmLines()

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
