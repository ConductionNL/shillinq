<?php

/**
 * Golden record of CalendarController::listBookings and ::createBooking.
 *
 * Each scenario runs one request for one acting user and records the
 * response, the administrations the access check was asked about, every
 * object-service read and write, the conflict checks, the transaction calls,
 * the lifecycle transitions and every log line. The access check answers
 * true only for the acting user's own administration. Generated booking ids
 * are recorded as bk-ID. The record is
 * tests/Unit/Controller/fixtures/calendar-bookings-golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\CalendarController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Calendar bookings golden tests.
 *
 * @covers \OCA\Shillinq\Controller\CalendarController
 * @covers \OCA\Shillinq\Service\Booking\CalendarRows
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CalendarBookingsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/calendar-bookings-golden.json';

	private const BOOKING = [
		'title' => ' Intake ',
		'startTime' => '2026-06-01T09:00:00Z',
		'endTime' => '2026-06-01T09:30:00Z',
		'attendee' => ' jan@example.org ',
	];

	/**
	 * Every scenario.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function scenarios(): array {
		$cases = [];
		foreach (array_keys(self::worlds()) as $name) {
			$cases[$name] = [$name];
		}

		return $cases;

	}//end scenarios()

	/**
	 * The endpoint outcome matches the golden record.
	 *
	 * @param string $name The scenario.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testEndpointMatchesGolden(string $name): void {
		$world = self::worlds()[$name] + [
			'action' => 'list', 'calendarId' => ' cal-1 ', 'params' => [], 'user' => 'alice', 'admin' => false,
			'or' => true, 'calendar' => 'own', 'bookings' => 'mixed', 'conflicts' => [], 'conflictsFail' => false, 'save' => 'echo',
		];
		$trace = [];

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($world['params'][$key] ?? $default)
		);

		$context = $this->createStub(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($world['user']);
		$context->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use (&$trace, $world): bool {
				$trace[] = 'canAccess ' . $world['user'] . ' -> ' . $administrationId;
				return $world['user'] === 'alice' && $administrationId === 'adm-1';
			}
		);

		$groups = $this->createStub(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(
			static function (string $uid) use (&$trace, $world): bool {
				$trace[] = 'isAdmin ' . $uid;
				return $world['admin'];
			}
		);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturnOnConsecutiveCalls(...match ($world['or']) {
			'drops' => [true, false],
			default => [$world['or'], $world['or']],
		});
		$settings->method('getRegisterSlug')->willReturn('books');

		$objects   = new GoldenCalendarObjects(calendar: $world['calendar'], bookings: $world['bookings'], save: $world['save']);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$conflicts = $this->createStub(ConflictDetectionService::class);
		$conflicts->method('lockResource')->willReturnCallback(
			static function (string $resourceId) use (&$trace): bool {
				$trace[] = 'lock ' . $resourceId;
				return true;
			}
		);
		$conflicts->method('checkConflicts')->willReturnCallback(
			static function (string $resourceId, string $proposedStart, string $proposedEnd) use (&$trace, $world): array {
				$trace[] = 'conflicts ' . $resourceId . ' ' . $proposedStart . ' ' . $proposedEnd;
				if ($world['conflictsFail'] === true) {
					throw new RuntimeException('conflict lookup failed');
				}

				return $world['conflicts'];
			}
		);

		$inTransaction = false;
		$guard         = $this->createStub(TransactionalGuard::class);
		$guard->method('beginTransaction')->willReturnCallback(
			static function () use (&$trace, &$inTransaction): void {
				$trace[] = 'begin';
				$inTransaction = true;
			}
		);
		$guard->method('commit')->willReturnCallback(
			static function () use (&$trace, &$inTransaction): void {
				$trace[] = 'commit';
				$inTransaction = false;
			}
		);
		$guard->method('rollBack')->willReturnCallback(
			static function () use (&$trace, &$inTransaction): void {
				$trace[] = 'rollBack';
				$inTransaction = false;
			}
		);
		$guard->method('inTransaction')->willReturnCallback(
			static function () use (&$inTransaction): bool {
				return $inTransaction;
			}
		);

		$transitions = $this->createStub(ObjectTransitionRunner::class);
		$transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use (&$trace, $world): void {
				$trace[] = 'transition ' . $objectId . ' ' . $action;
				if ($world['save'] === 'transitionFails') {
					throw new RuntimeException('engine down');
				}
			}
		);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'l10n:' . $text);

		$logger     = new GoldenCalendarLogger();
		$controller = new CalendarController(
			request: $request,
			container: $container,
			settings: $settings,
			conflicts: $conflicts,
			context: $context,
			guard: $guard,
			logger: $logger,
			l10n: $l10n,
			groupManager: $groups,
			transitions: $transitions,
		);

		try {
			if ($world['action'] === 'create') {
				$response = $controller->createBooking($world['calendarId']);
			} else {
				$response = $controller->listBookings($world['calendarId']);
			}

			$outcome = ['status' => $response->getStatus(), 'data' => $response->getData()];
		} catch (\Throwable $e) {
			$outcome = ['thrown' => get_class($e)];
		}

		$recorded = json_decode((string) preg_replace('/bk-\d{14}-[0-9a-f]{4}/', 'bk-ID', (string) json_encode($outcome + [
			'trace' => $trace,
			'reads' => $objects->reads,
			'saved' => $objects->saved,
			'log' => $logger->lines,
		])), true);
		$this->assertGolden(key: $name, recorded: $recorded);

	}//end testEndpointMatchesGolden()

	/**
	 * Compare one recording with the golden file (or record it).
	 *
	 * @param string $key The scenario key.
	 * @param array<string, mixed> $recorded What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(string $key, array $recorded): void {
		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			$golden[$key] = $recorded;
			ksort($golden);
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			file_put_contents(self::GOLDEN, json_encode($golden, $flags) . "\n");
		}

		self::assertSame(json_encode($golden[$key] ?? null), json_encode($recorded));

	}//end assertGolden()

	/**
	 * The scenarios: list by default, create where the action says so.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function worlds(): array {
		$range  = ['start' => '2026-06-01T00:00:00Z', 'end' => '2026-06-02T00:00:00Z'];
		$create = ['action' => 'create', 'params' => self::BOOKING];
		return [
			'list in range' => ['params' => $range],
			'list from an offset start' => ['params' => ['start' => '2026-06-01T10:00:00+02:00', 'end' => '2026-06-01T12:00:00+02:00']],
			'list start only' => ['params' => ['start' => '2026-06-01T00:00:00Z']],
			'list zero-length range' => ['params' => ['start' => '2026-06-01T00:00:00Z', 'end' => '2026-06-01T00:00:00Z']],
			'list end only' => ['params' => ['start' => '2026-06-01T00:00:00Z', 'end' => '2026-06-01T09:00:00Z']],
			'list empty range' => ['params' => ['start' => '2026-06-02T00:00:00Z', 'end' => '2026-06-01T00:00:00Z']],
			'list bad start' => ['params' => ['start' => 'whenever', 'end' => '2026-06-02T00:00:00Z']],
			'list bad end' => ['params' => ['start' => '2026-06-01T00:00:00Z', 'end' => 'later']],
			'list anonymous' => ['user' => null, 'params' => $range],
			'list empty calendar id' => ['calendarId' => '  ', 'params' => $range],
			'list calendar unknown' => ['calendar' => 'none', 'params' => $range],
			'list calendar of another administration' => ['calendar' => 'other', 'params' => $range],
			'list as Nextcloud admin' => ['calendar' => 'other', 'admin' => true, 'params' => $range],
			'list another user' => ['user' => 'mallory', 'params' => $range],
			'list calendar read fails' => ['calendar' => 'throws', 'params' => $range],
			'list without OpenRegister' => ['or' => false, 'params' => $range],
			'list booking read fails' => ['bookings' => 'throws', 'params' => $range],
			'create' => $create,
			'create and confirm' => ['action' => 'create', 'params' => self::BOOKING + ['confirm' => 'yes']],
			'create overriding a conflict' => [
				'action' => 'create',
				'params' => self::BOOKING + ['overrideConflict' => '1'],
				'conflicts' => [['bookingId' => 'b-1']],
			],
			'create into a conflict' => ['conflicts' => [['bookingId' => 'b-1']]] + $create,
			'create anonymous' => ['user' => null] + $create,
			'create empty calendar id' => ['calendarId' => ''] + $create,
			'create calendar unknown' => ['calendar' => 'none'] + $create,
			'create calendar of another administration' => ['calendar' => 'other'] + $create,
			'create as Nextcloud admin' => ['calendar' => 'other', 'admin' => true] + $create,
			'create another user' => ['user' => 'mallory'] + $create,
			'create calendar without administration' => ['calendar' => 'unscoped', 'admin' => true] + $create,
			'create missing title' => ['params' => ['title' => ' '] + self::BOOKING] + $create,
			'create missing attendee' => ['params' => ['attendee' => ''] + self::BOOKING] + $create,
			'create bad end' => ['params' => ['endTime' => 'later'] + self::BOOKING] + $create,
			'create too short' => ['params' => ['endTime' => '2026-06-01T09:14:59Z'] + self::BOOKING] + $create,
			'create exactly fifteen minutes' => ['params' => ['endTime' => '2026-06-01T09:15:00Z'] + self::BOOKING] + $create,
			'create without OpenRegister' => ['or' => false, 'calendar' => 'own'] + $create,
			'create conflict lookup fails' => ['conflictsFail' => true] + $create,
			'create save fails' => ['save' => 'throws'] + $create,
			'create save returns an entity' => ['save' => 'entity'] + $create,
			'create confirm fails' => ['save' => 'transitionFails', 'params' => self::BOOKING + ['confirm' => 'true']] + $create,
			'create on a resource-less calendar' => ['calendar' => 'noResource'] + $create,
			'create when OpenRegister drops after the lookup' => ['or' => 'drops'] + $create,
			'create and confirm an entity' => ['save' => 'entity', 'params' => self::BOOKING + ['confirm' => 'on']] + $create,
		];

	}//end worlds()
}//end class

/**
 * Object service double: Calendar and Booking reads, recorded saves.
 */
final class GoldenCalendarObjects {

	/**
	 * Every read as schema and filters.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Every save as schema and object.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * The current register and schema.
	 *
	 * @var array<string, string>
	 */
	private array $current = [];

	/**
	 * Constructor.
	 *
	 * @param string $calendar The calendar shape.
	 * @param string $bookings The bookings shape.
	 * @param string $save The save outcome.
	 */
	public function __construct(
		private readonly string $calendar,
		private readonly string $bookings,
		private readonly string $save,
	) {
	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		$this->current = ['register' => $register];
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema name.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->current['schema'] = $schema;
		return $this;
	}//end setSchema()

	/**
	 * The rows of the current schema.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = []): array {
		$this->reads[] = $this->current + ['config' => $config];
		if ($this->current['schema'] === 'Calendar') {
			return $this->calendars();
		}

		if ($this->bookings === 'throws') {
			throw new RuntimeException('booking read failed');
		}

		return [
			['bookingId' => 'b-3', 'startTime' => '2026-06-01T15:00:00Z', 'endTime' => '2026-06-01T16:00:00Z', 'title' => 'Late'],
			['bookingId' => 'b-1', 'startTime' => '2026-06-01T08:00:00Z', 'endTime' => '2026-06-01T09:00:00Z', 'status' => 'confirmed'],
			['bookingId' => 'b-2', 'startTime' => '2026-05-31T23:00:00Z', 'endTime' => '2026-06-01T00:00:00Z'],
			['bookingId' => 'b-4', 'startTime' => '2026-06-01T10:30:00+02:00', 'endTime' => '2026-06-01T11:30:00+02:00'],
			['bookingId' => 'b-5', 'startTime' => '', 'endTime' => '2026-06-01T11:30:00Z'],
			['bookingId' => 'b-6', 'startTime' => 'garbage', 'endTime' => '2026-06-01T11:30:00Z'],
			['bookingId' => 'b-9', 'startTime' => '2026-06-01T10:00:00Z', 'endTime' => ''],
			new GoldenCalendarEntity(['bookingId' => 'b-7', 'startTime' => '2026-06-01T23:30:00Z', 'endTime' => '2026-06-02T00:30:00Z']),
			['bookingId' => 'b-8', 'startTime' => '2026-06-02T00:00:00Z', 'endTime' => '2026-06-02T01:00:00Z'],
		];
	}//end findAll()

	/**
	 * The calendar rows for the configured shape.
	 *
	 * @return array<int, mixed>
	 */
	private function calendars(): array {
		$row = ['calendarId' => 'cal-1', 'resource' => 'room-1', 'administrationId' => 'adm-1'];
		return match ($this->calendar) {
			'none' => [],
			'throws' => throw new RuntimeException('calendar read failed'),
			'other' => [['administrationId' => 'adm-2'] + $row],
			'unscoped' => [['calendarId' => 'cal-1', 'resource' => 'room-1']],
			'noResource' => [['calendarId' => 'cal-1', 'administrationId' => 'adm-1']],
			default => [new GoldenCalendarEntity($row)],
		};
	}//end calendars()

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $object The object.
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, string $register, string $schema): mixed {
		$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
		return match ($this->save) {
			'throws' => throw new RuntimeException('disk full'),
			'entity' => new GoldenCalendarEntity(['@self' => ['id' => 'uuid-9'], 'bookingId' => 'or-9', 'status' => 'pending', 'createdAt' => 'now']),
			default => ['id' => 'uuid-1'] + $object,
		};
	}//end saveObject()
}//end class

/**
 * An entity that serialises to its row.
 */
final class GoldenCalendarEntity {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $row The row.
	 */
	public function __construct(private readonly array $row) {
	}//end __construct()

	/**
	 * The row.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->row;
	}//end jsonSerialize()
}//end class

/**
 * Logger that records every line as level: message context.
 */
final class GoldenCalendarLogger extends AbstractLogger {

	/**
	 * The recorded lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record one log line.
	 *
	 * @param mixed $level The level.
	 * @param string|Stringable $message The message.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message . ' ' . json_encode($context);
	}//end log()
}//end class
