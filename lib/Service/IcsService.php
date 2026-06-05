<?php

/**
 * ICS Calendar Generation Service
 *
 * Generates RFC 5545 (iCalendar) content for appointment confirmation emails.
 * The output carries a VEVENT with TZID-referenced DTSTART/DTEND, a VTIMEZONE
 * block with DAYLIGHT/STANDARD rules derived from the customer's IANA timezone,
 * METHOD:REQUEST, and SUMMARY/LOCATION/DESCRIPTION properties (REQ-BCF-003,
 * REQ-BCF-009). The service is pure: it returns a string and performs no file
 * I/O — the caller attaches it as a MIME part.
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
use Throwable;

/**
 * Composes RFC 5545 compliant ICS calendar content for appointments.
 */
class IcsService
{
    /**
     * CRLF line terminator required by RFC 5545.
     *
     * @var string
     */
    private const CRLF = "\r\n";

    /**
     * Default timezone used when an appointment carries no customer timezone.
     *
     * @var string
     */
    private const DEFAULT_TZID = 'Europe/Amsterdam';

    /**
     * Generate an RFC 5545 ICS string for an appointment.
     *
     * @param array<string,mixed> $appointment The appointment object array (OR-loaded).
     * @param string|null         $timezone    IANA timezone for display; falls back to
     *                                         the appointment's customerTimezone, then
     *                                         the server default.
     *
     * @return string The ICS document.
     *
     * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-9
     */
    public function generateIcs(array $appointment, ?string $timezone=null): string
    {
        $tzid = $this->resolveTimezone(explicit: $timezone, customer: ($appointment['customerTimezone'] ?? null));
        $tz   = new DateTimeZone($tzid);

        $start = $this->toLocal(value: ($appointment['startTime'] ?? 'now'), tz: $tz);
        $end   = $this->toLocal(value: ($appointment['endTime'] ?? ($appointment['startTime'] ?? 'now')), tz: $tz);

        $uid     = ($appointment['appointmentNumber'] ?? ($appointment['id'] ?? uniqid('apt-', true)));
        $summary = $this->escapeText(value: (string) ($appointment['serviceName'] ?? 'Appointment'));

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Conduction//Shillinq Bookings//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:REQUEST';
        // VTIMEZONE block with DAYLIGHT/STANDARD rules for the customer timezone.
        foreach ($this->buildVtimezone(tzid: $tzid, tz: $tz, start: $start) as $line) {
            $lines[] = $line;
        }

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:'.$uid.'@shillinq.conduction.nl';
        $lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
        $lines[] = 'DTSTART;TZID='.$tzid.':'.$start->format('Ymd\THis');
        $lines[] = 'DTEND;TZID='.$tzid.':'.$end->format('Ymd\THis');
        $lines[] = 'SUMMARY:'.$summary;
        if (empty($appointment['location']) === false) {
            $lines[] = 'LOCATION:'.$this->escapeText(value: (string) $appointment['location']);
        }

        if (empty($appointment['notes']) === false) {
            $lines[] = 'DESCRIPTION:'.$this->escapeText(value: (string) $appointment['notes']);
        }

        if (empty($appointment['providerName']) === false) {
            $lines[] = 'ORGANIZER;CN='.$this->escapeText(value: (string) $appointment['providerName'])
                .':mailto:noreply@shillinq.conduction.nl';
        }

        $lines[] = 'STATUS:TENTATIVE';
        // Self-referential ATTACH so calendar clients can re-open the invite (D7).
        $lines[] = 'ATTACH;FMTTYPE=text/calendar:CID:appointment.ics';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return $this->fold(lines: $lines);
    }//end generateIcs()

    /**
     * Resolve the timezone to use, in priority order.
     *
     * @param string|null $explicit Explicit timezone argument.
     * @param mixed       $customer Customer timezone from the appointment.
     *
     * @return string A valid IANA timezone identifier.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function resolveTimezone(?string $explicit, mixed $customer): string
    {
        foreach ([$explicit, $customer] as $candidate) {
            if (is_string($candidate) === true && $candidate !== ''
                && in_array($candidate, DateTimeZone::listIdentifiers(), true) === true
            ) {
                return $candidate;
            }
        }

        return self::DEFAULT_TZID;
    }//end resolveTimezone()

    /**
     * Parse an ISO 8601 timestamp and convert it into the target timezone.
     *
     * @param string       $value The ISO 8601 timestamp.
     * @param DateTimeZone $tz    Target timezone.
     *
     * @return DateTimeImmutable The local datetime.
     */
    private function toLocal(string $value, DateTimeZone $tz): DateTimeImmutable
    {
        try {
            $parsed = new DateTimeImmutable($value);
        } catch (Throwable) {
            $parsed = new DateTimeImmutable('now');
        }

        return $parsed->setTimezone($tz);
    }//end toLocal()

    /**
     * Build a VTIMEZONE block with STANDARD and DAYLIGHT sub-components.
     *
     * The offsets and transition rules are derived from PHP's timezone
     * database around the appointment date so calendar clients render the
     * correct local time (RFC 5545 §3.6.5, REQ-BCF-009).
     *
     * @param string            $tzid  The IANA timezone identifier.
     * @param DateTimeZone      $tz    The timezone object.
     * @param DateTimeImmutable $start The appointment start (local).
     *
     * @return array<string> The VTIMEZONE lines.
     */
    private function buildVtimezone(string $tzid, DateTimeZone $tz, DateTimeImmutable $start): array
    {
        $lines   = [];
        $lines[] = 'BEGIN:VTIMEZONE';
        $lines[] = 'TZID:'.$tzid;

        // Gather one STANDARD and one DAYLIGHT transition around the event year.
        $windowStart = $start->modify('-1 year')->getTimestamp();
        $windowEnd   = $start->modify('+1 year')->getTimestamp();
        $transitions = $tz->getTransitions($windowStart, $windowEnd);

        $standard = $this->firstTransition(transitions: $transitions, isDst: false);
        $daylight = $this->firstTransition(transitions: $transitions, isDst: true);

        // Always emit a STANDARD component; emit DAYLIGHT only for DST zones.
        if ($standard !== null) {
            foreach ($this->buildTimezoneSub(kind: 'STANDARD', self: $standard, other: ($daylight ?? $standard)) as $l) {
                $lines[] = $l;
            }
        }

        if ($daylight !== null && $standard !== null) {
            foreach ($this->buildTimezoneSub(kind: 'DAYLIGHT', self: $daylight, other: $standard) as $l) {
                $lines[] = $l;
            }
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }//end buildVtimezone()

    /**
     * Return the first transition matching the requested DST flag, or null.
     *
     * @param array<int,array<string,mixed>> $transitions The timezone transitions.
     * @param bool                           $isDst       Whether to find a DST transition.
     *
     * @return array<string,mixed>|null The matching transition, or null.
     */
    private function firstTransition(array $transitions, bool $isDst): ?array
    {
        foreach ($transitions as $transition) {
            if ($transition['isdst'] === $isDst) {
                return $transition;
            }
        }

        return null;
    }//end firstTransition()

    /**
     * Build a STANDARD or DAYLIGHT sub-component for VTIMEZONE.
     *
     * @param string              $kind  STANDARD or DAYLIGHT.
     * @param array<string,mixed> $self  The transition for this component.
     * @param array<string,mixed> $other The opposite transition (for TZOFFSETFROM).
     *
     * @return array<string> The component lines.
     */
    private function buildTimezoneSub(string $kind, array $self, array $other): array
    {
        $from  = $this->formatOffset(seconds: (int) $other['offset']);
        $to    = $this->formatOffset(seconds: (int) $self['offset']);
        $start = (new DateTimeImmutable('@'.$self['ts']))->setTimezone(new DateTimeZone('UTC'));

        return [
            'BEGIN:'.$kind,
            'TZOFFSETFROM:'.$from,
            'TZOFFSETTO:'.$to,
            'TZNAME:'.($self['abbr'] ?? $kind),
            'DTSTART:'.$start->format('Ymd\THis'),
            'END:'.$kind,
        ];
    }//end buildTimezoneSub()

    /**
     * Format a UTC offset in seconds as the RFC 5545 ±HHMM form.
     *
     * @param int $seconds The offset in seconds.
     *
     * @return string The ±HHMM string.
     */
    private function formatOffset(int $seconds): string
    {
        $sign = '+';
        if ($seconds < 0) {
            $sign = '-';
        }

        $seconds = abs($seconds);
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv(($seconds % 3600), 60);

        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }//end formatOffset()

    /**
     * Escape text per RFC 5545 §3.3.11.
     *
     * @param string $value The raw value.
     *
     * @return string The escaped value.
     */
    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace([';', ','], ['\;', '\,'], $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\n', $value);

        return $value;
    }//end escapeText()

    /**
     * Join lines with CRLF and fold any longer than 75 octets (RFC 5545 §3.1).
     *
     * @param array<string> $lines The content lines.
     *
     * @return string The folded ICS document.
     */
    private function fold(array $lines): string
    {
        $out = [];
        foreach ($lines as $line) {
            if (strlen($line) <= 75) {
                $out[] = $line;
                continue;
            }

            $chunk = substr($line, 0, 75);
            $rest  = substr($line, 75);
            $out[] = $chunk;
            while ($rest !== '') {
                $piece = substr($rest, 0, 74);
                $rest  = substr($rest, 74);
                $out[] = ' '.$piece;
            }
        }

        return implode(self::CRLF, $out).self::CRLF;
    }//end fold()
}//end class
