<?php

/**
 * Unit tests for DeadlineCalendarEvents rewriting an event the calendar already holds.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DeadlineCalendarEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Nextcloud 34's createFromString() only creates; an event the calendar
 * already holds is rewritten through the CalDAV backend instead.
 */
final class DeadlineCalendarEventsRewriteTest extends TestCase {

	private const DEADLINE = [
		'uid' => 'btw-filing:vr-1',
		'category' => 'filing',
		'summary' => 'BTW-aangifte 2026-Q2',
		'dueDate' => '2026-08-31',
		'source' => 'btw-filing',
		'objectId' => 'vr-1',
	];

	/**
	 * A refused create of an event the calendar holds rewrites it in place.
	 *
	 * @return void
	 */
	public function testAnEventTheCalendarHoldsIsRewritten(): void {
		$calendar = new RefusingCalendar();
		$backend  = new RecordingCalDavBackend(held: ['shillinq-btw-filing-vr-1.ics']);
		$events   = new DeadlineCalendarEvents(logger: new NullLogger(), calendarObjects: $backend);

		$published = $events->publish(calendar: $calendar, desired: ['btw-filing:vr-1' => self::DEADLINE]);

		self::assertSame(expected: 1, actual: $published);
		self::assertCount(expectedCount: 1, haystack: $backend->updates);
		self::assertSame(expected: 17, actual: $backend->updates[0][0]);
		self::assertSame(expected: 'shillinq-btw-filing-vr-1.ics', actual: $backend->updates[0][1]);
		self::assertStringContainsString(needle: 'DTSTART;VALUE=DATE:20260831', haystack: $backend->updates[0][2]);

	}//end testAnEventTheCalendarHoldsIsRewritten()

	/**
	 * A closed deadline's event is rewritten as cancelled.
	 *
	 * @return void
	 */
	public function testAClosedDeadlineIsCancelledInPlace(): void {
		$calendar = new RefusingCalendar(searchUids: ['btw-filing:vr-1']);
		$backend  = new RecordingCalDavBackend(held: ['shillinq-btw-filing-vr-1.ics']);
		$events   = new DeadlineCalendarEvents(logger: new NullLogger(), calendarObjects: $backend);

		$removed = $events->cancelUndesired(calendar: $calendar, desired: []);

		self::assertSame(expected: 1, actual: $removed);
		self::assertStringContainsString(needle: 'STATUS:CANCELLED', haystack: $backend->updates[0][2]);

	}//end testAClosedDeadlineIsCancelledInPlace()

	/**
	 * Without a backend, without an event of that name, or when the backend
	 * throws, the write is skipped and nothing is rewritten.
	 *
	 * @return void
	 */
	public function testAWriteThatFailsBothWaysIsSkipped(): void {
		$desired = ['btw-filing:vr-1' => self::DEADLINE];

		$none = new DeadlineCalendarEvents(logger: new NullLogger());
		self::assertSame(expected: 0, actual: $none->publish(calendar: new RefusingCalendar(), desired: $desired));

		$notHeld = new RecordingCalDavBackend(held: []);
		$events  = new DeadlineCalendarEvents(logger: new NullLogger(), calendarObjects: $notHeld);
		self::assertSame(expected: 0, actual: $events->publish(calendar: new RefusingCalendar(), desired: $desired));
		self::assertSame(expected: [], actual: $notHeld->updates);

		$throwing = new RecordingCalDavBackend(held: ['shillinq-btw-filing-vr-1.ics'], fails: true);
		$events   = new DeadlineCalendarEvents(logger: new NullLogger(), calendarObjects: $throwing);
		self::assertSame(expected: 0, actual: $events->publish(calendar: new RefusingCalendar(), desired: $desired));

		$noMethods = new DeadlineCalendarEvents(logger: new NullLogger(), calendarObjects: new \stdClass());
		self::assertSame(expected: 0, actual: $noMethods->publish(calendar: new RefusingCalendar(), desired: $desired));

	}//end testAWriteThatFailsBothWaysIsSkipped()
}//end class

/**
 * A calendar that refuses every create, as Nextcloud 34 does for a UID it holds.
 */
final class RefusingCalendar {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $searchUids The UIDs search() reports as live.
	 */
	public function __construct(
		private readonly array $searchUids = [],
	) {
	}//end __construct()

	/**
	 * The CalDAV calendar id.
	 *
	 * @return string
	 */
	public function getKey(): string {
		return '17';
	}//end getKey()

	/**
	 * Refuse the create.
	 *
	 * @param string $name         The calendar-object name.
	 * @param string $calendarData The payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 */
	public function createFromString(string $name, string $calendarData): void {
		throw new RuntimeException('Calendar object with uid already exists in this calendar collection.');
	}//end createFromString()

	/**
	 * The live events.
	 *
	 * @param string               $pattern          Unused.
	 * @param array<int, string>   $searchProperties Unused.
	 * @param array<string, mixed> $options          Unused.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function search(string $pattern, array $searchProperties = [], array $options = []): array {
		return array_map(static fn (string $uid): array => ['uid' => $uid, 'objects' => []], $this->searchUids);
	}//end search()
}//end class

/**
 * A CalDAV backend double that records rewrites.
 */
final class RecordingCalDavBackend {

	/**
	 * Every updateCalendarObject() call as [calendarId, name, data].
	 *
	 * @var array<int, array{0: int, 1: string, 2: string}>
	 */
	public array $updates = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $held  The object names the calendar holds.
	 * @param bool               $fails Whether every rewrite throws.
	 */
	public function __construct(
		private readonly array $held,
		private readonly bool $fails = false,
	) {
	}//end __construct()

	/**
	 * The stored object, or null.
	 *
	 * @param int    $calendarId The calendar id.
	 * @param string $objectUri  The object name.
	 *
	 * @return array<string, mixed>|null
	 */
	public function getCalendarObject(int $calendarId, string $objectUri): ?array {
		if (in_array($objectUri, $this->held, true) === false) {
			return null;
		}

		return ['uri' => $objectUri];
	}//end getCalendarObject()

	/**
	 * Record the rewrite.
	 *
	 * @param int    $calendarId   The calendar id.
	 * @param string $objectUri    The object name.
	 * @param string $calendarData The payload.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the double fails.
	 */
	public function updateCalendarObject(int $calendarId, string $objectUri, string $calendarData): void {
		if ($this->fails === true) {
			throw new RuntimeException('backend down');
		}

		$this->updates[] = [$calendarId, $objectUri, $calendarData];
	}//end updateCalendarObject()
}//end class
