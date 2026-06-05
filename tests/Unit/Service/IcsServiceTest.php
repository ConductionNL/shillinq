<?php

/**
 * Unit tests for IcsService (RFC 5545 ICS generation).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IcsService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the generated ICS is RFC 5545 compliant with TZID and VTIMEZONE.
 */
final class IcsServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var IcsService
     */
    private IcsService $service;

    /**
     * A sample Amsterdam-timezone appointment (summer, UTC+2).
     *
     * @var array<string,mixed>
     */
    private array $appointment;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service     = new IcsService();
        $this->appointment = [
            'appointmentNumber' => 'APT-2026-0001',
            'serviceName'       => 'Tax advice consultation',
            'providerName'      => 'Conduction B.V.',
            'location'          => 'Lauriergracht 14h, Amsterdam',
            'notes'             => 'Bring last year accounts',
            'startTime'         => '2026-05-22T12:30:00Z',
            'endTime'           => '2026-05-22T13:00:00Z',
            'customerTimezone'  => 'Europe/Amsterdam',
        ];
    }//end setUp()

    /**
     * The ICS wraps a VCALENDAR/VEVENT with METHOD:REQUEST.
     *
     * @return void
     */
    public function testIcsHasCalendarEnvelopeAndMethodRequest(): void
    {
        $ics = $this->service->generateIcs($this->appointment);
        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        self::assertStringContainsString('VERSION:2.0', $ics);
        self::assertStringContainsString('METHOD:REQUEST', $ics);
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('END:VCALENDAR', $ics);
        // CRLF line terminators (RFC 5545 §3.1).
        self::assertStringContainsString("\r\n", $ics);
    }//end testIcsHasCalendarEnvelopeAndMethodRequest()

    /**
     * DTSTART/DTEND use the TZID-referenced local time, not UTC.
     *
     * 12:30 UTC in summer Amsterdam (UTC+2) is 14:30 local.
     *
     * @return void
     */
    public function testDtStartUsesTzidLocalTime(): void
    {
        $ics = $this->service->generateIcs($this->appointment);
        self::assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:20260522T143000', $ics);
        self::assertStringContainsString('DTEND;TZID=Europe/Amsterdam:20260522T150000', $ics);
        // It must NOT emit a bare UTC DTSTART.
        self::assertStringNotContainsString('DTSTART:20260522T123000Z', $ics);
    }//end testDtStartUsesTzidLocalTime()

    /**
     * A VTIMEZONE block with STANDARD and DAYLIGHT rules is emitted for a DST zone.
     *
     * @return void
     */
    public function testVtimezoneBlockHasDaylightAndStandard(): void
    {
        $ics = $this->service->generateIcs($this->appointment);
        self::assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        self::assertStringContainsString('TZID:Europe/Amsterdam', $ics);
        self::assertStringContainsString('BEGIN:STANDARD', $ics);
        self::assertStringContainsString('BEGIN:DAYLIGHT', $ics);
        self::assertStringContainsString('TZOFFSETTO:+0200', $ics);
        self::assertStringContainsString('TZOFFSETTO:+0100', $ics);
        self::assertStringContainsString('END:VTIMEZONE', $ics);
    }//end testVtimezoneBlockHasDaylightAndStandard()

    /**
     * SUMMARY, LOCATION and DESCRIPTION carry appointment fields.
     *
     * @return void
     */
    public function testEventCarriesSummaryLocationDescription(): void
    {
        $ics = $this->service->generateIcs($this->appointment);
        self::assertStringContainsString('SUMMARY:Tax advice consultation', $ics);
        self::assertStringContainsString('LOCATION:Lauriergracht 14h', $ics);
        self::assertStringContainsString('DESCRIPTION:Bring last year accounts', $ics);
        self::assertStringContainsString('ATTACH;FMTTYPE=text/calendar', $ics);
    }//end testEventCarriesSummaryLocationDescription()

    /**
     * Special characters in text fields are escaped per RFC 5545 §3.3.11.
     *
     * @return void
     */
    public function testTextFieldsAreEscaped(): void
    {
        $appt                = $this->appointment;
        $appt['serviceName'] = 'Advice; with, commas';
        $ics                 = $this->service->generateIcs($appt);
        self::assertStringContainsString('SUMMARY:Advice\; with\, commas', $ics);
    }//end testTextFieldsAreEscaped()

    /**
     * An unknown timezone falls back to the server default (Europe/Amsterdam).
     *
     * @return void
     */
    public function testUnknownTimezoneFallsBackToDefault(): void
    {
        $appt                     = $this->appointment;
        $appt['customerTimezone'] = 'Mars/Olympus_Mons';
        $ics                      = $this->service->generateIcs($appt);
        self::assertStringContainsString('TZID:Europe/Amsterdam', $ics);
    }//end testUnknownTimezoneFallsBackToDefault()

    /**
     * An explicit timezone argument overrides the appointment timezone.
     *
     * @return void
     */
    public function testExplicitTimezoneArgumentWins(): void
    {
        $ics = $this->service->generateIcs($this->appointment, 'America/New_York');
        self::assertStringContainsString('TZID:America/New_York', $ics);
        // 12:30 UTC is 08:30 EDT (UTC-4) in May.
        self::assertStringContainsString('DTSTART;TZID=America/New_York:20260522T083000', $ics);
    }//end testExplicitTimezoneArgumentWins()
}//end class
