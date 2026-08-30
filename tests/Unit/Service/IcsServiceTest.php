<?php

/**
 * Unit tests for IcsService.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCA\Shillinq\Service\IcsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behavioural tests for the RFC 5545 ICS composer (REQ-BCF-003/009).
 */
final class IcsServiceTest extends TestCase {

	/**
	 * Construct an IcsService with a stub TimezoneResolver pinned to a
	 * specific IANA timezone for deterministic output.
	 *
	 * @param string $tz IANA timezone id.
	 *
	 * @return IcsService
	 */
	private function buildService(string $tz = 'Europe/Amsterdam'): IcsService {
		$config = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturn($tz);

		$resolver = new TimezoneResolver(config: $config, logger: new NullLogger());
		return new IcsService(timezoneResolver: $resolver, logger: new NullLogger());
	}//end buildService()

	/**
	 * The generated ICS contains the canonical RFC 5545 envelope.
	 */
	public function testEmitsCanonicalEnvelope(): void {
		$ics = $this->buildService()->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
			],
			customer: ['id' => 'cust-1', 'userId' => 'jan', 'email' => 'jan@example.nl', 'name' => 'Jan'],
			confirmUrl: 'https://example.nl/index.php/apps/shillinq/confirm/apt-001?token=xyz',
			context: ['serviceName' => 'Intake consultation'],
		);

		self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
		self::assertStringContainsString('END:VCALENDAR', $ics);
		self::assertStringContainsString('METHOD:REQUEST', $ics);
		self::assertStringContainsString('PRODID:', $ics);
		self::assertStringContainsString('BEGIN:VEVENT', $ics);
		self::assertStringContainsString('END:VEVENT', $ics);

	}//end testEmitsCanonicalEnvelope()

	/**
	 * Lines are CRLF-terminated per RFC 5545 §3.1.
	 */
	public function testLinesAreCrlfTerminated(): void {
		$ics = $this->buildService()->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
			],
			customer: ['email' => 'jan@example.nl'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
		);

		self::assertStringContainsString("\r\n", $ics);

	}//end testLinesAreCrlfTerminated()

	/**
	 * DTSTART/DTEND include the resolved TZID.
	 */
	public function testEmitsTzidOnDtstartAndDtend(): void {
		$ics = $this->buildService('Europe/Amsterdam')->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
			],
			customer: ['userId' => 'jan', 'email' => 'jan@example.nl'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
			context: ['serviceName' => 'Service A'],
		);

		self::assertStringContainsString('DTSTART;TZID=Europe/Amsterdam:', $ics);
		self::assertStringContainsString('DTEND;TZID=Europe/Amsterdam:', $ics);

	}//end testEmitsTzidOnDtstartAndDtend()

	/**
	 * VTIMEZONE block is emitted with the resolved TZID.
	 */
	public function testEmitsVtimezoneBlock(): void {
		$ics = $this->buildService('Europe/Amsterdam')->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
			],
			customer: ['userId' => 'jan', 'email' => 'jan@example.nl'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
		);

		self::assertStringContainsString('BEGIN:VTIMEZONE', $ics);
		self::assertStringContainsString('TZID:Europe/Amsterdam', $ics);
		self::assertStringContainsString('END:VTIMEZONE', $ics);

	}//end testEmitsVtimezoneBlock()

	/**
	 * SUMMARY, ATTACH and URL properties reference the supplied context.
	 */
	public function testEmbedsContextProperties(): void {
		$ics = $this->buildService()->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
				'notes' => 'Wheelchair access required.',
			],
			customer: ['userId' => 'jan', 'email' => 'jan@example.nl', 'name' => 'Jan'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
			context: ['serviceName' => 'Intake consultation', 'location' => 'Room A', 'organizerEmail' => 'ops@example.nl'],
		);

		self::assertStringContainsString('SUMMARY:Intake consultation', $ics);
		self::assertStringContainsString('LOCATION:Room A', $ics);
		self::assertStringContainsString('DESCRIPTION:Wheelchair access required.', $ics);
		self::assertStringContainsString('ATTACH;FMTTYPE=text/calendar:https://example.nl/confirm/apt-001?token=xyz', $ics);
		self::assertStringContainsString('URL:https://example.nl/confirm/apt-001?token=xyz', $ics);
		self::assertStringContainsString('ATTENDEE', $ics);
		self::assertStringContainsString('ORGANIZER:mailto:ops@example.nl', $ics);

	}//end testEmbedsContextProperties()

	/**
	 * Special characters (comma, semicolon, newline) in text fields are
	 * escaped per RFC 5545 §3.3.11.
	 */
	public function testEscapesSpecialCharacters(): void {
		$ics = $this->buildService()->generateIcs(
			appointment: [
				'appointmentId' => 'apt-001',
				'startTime' => '2026-05-22T12:30:00Z',
				'endTime' => '2026-05-22T13:00:00Z',
				'notes' => "Please, bring;\nyour ID.",
			],
			customer: ['email' => 'jan@example.nl'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
			context: ['serviceName' => 'Intake; consultation, plus'],
		);

		self::assertStringContainsString('SUMMARY:Intake\\; consultation\\, plus', $ics);
		self::assertStringContainsString('DESCRIPTION:Please\\, bring\\;\\nyour ID.', $ics);

	}//end testEscapesSpecialCharacters()

	/**
	 * Unparseable startTime / endTime returns an empty ICS — the caller
	 * decides whether to dispatch.
	 */
	public function testReturnsEmptyOnUnparseableTimes(): void {
		$ics = $this->buildService()->generateIcs(
			appointment: ['appointmentId' => 'apt-001', 'startTime' => 'not a date', 'endTime' => 'also not'],
			customer: ['email' => 'jan@example.nl'],
			confirmUrl: 'https://example.nl/confirm/apt-001?token=xyz',
		);
		self::assertSame('', $ics);

	}//end testReturnsEmptyOnUnparseableTimes()

}//end class
