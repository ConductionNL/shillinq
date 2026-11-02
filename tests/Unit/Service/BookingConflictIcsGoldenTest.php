<?php

/**
 * Golden record of booking conflict detection and the ICS invitation.
 *
 * Each conflict scenario feeds ConflictDetectionService one proposed window
 * and one set of stored bookings, and records the conflicts or the exception,
 * the object-service reads and the log lines. Each ICS scenario feeds
 * IcsService one appointment, customer, confirm URL and context, and records
 * the payload lines (DTSTAMP as NOW), the timezone lookups and the log lines.
 * The record is tests/Unit/Service/fixtures/booking-conflict-ics-golden.json;
 * SHILLINQ_GOLDEN_RECORD=1 rewrites it.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\TimezoneResolver;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\IcsService;
use OCA\Shillinq\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Conflict detection and ICS golden tests.
 *
 * @covers \OCA\Shillinq\Service\ConflictDetectionService
 * @covers \OCA\Shillinq\Service\IcsService
 * @uses   \OCA\Shillinq\Service\Booking\TimezoneResolver
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BookingConflictIcsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/booking-conflict-ics-golden.json';

	/**
	 * The default timezone before the test, restored afterwards.
	 *
	 * @var string
	 */
	private string $defaultTimezone = 'UTC';

	/**
	 * Pin the server default timezone the resolver falls back to.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->defaultTimezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

	}//end setUp()

	/**
	 * Restore the server default timezone.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->defaultTimezone);
		parent::tearDown();

	}//end tearDown()

	/**
	 * Every proposed window against the stored bookings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function conflictScenarios(): array {
		$cases = [];
		foreach (array_keys(self::proposals()) as $name) {
			$cases['conflict ' . $name] = [$name];
		}

		return $cases;

	}//end conflictScenarios()

	/**
	 * Every ICS input shape.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function icsScenarios(): array {
		$cases = [];
		foreach (array_keys(self::invitations()) as $name) {
			$cases['ics ' . $name] = [$name];
		}

		return $cases;

	}//end icsScenarios()

	/**
	 * The conflicts match the golden record.
	 *
	 * @param string $name The proposal.
	 *
	 * @return void
	 */
	#[DataProvider('conflictScenarios')]
	public function testConflictsMatchGolden(string $name): void {
		$proposal = self::proposals()[$name];
		$objects  = new GoldenBookingObjects(rows: self::bookings(), fails: $name === 'lookupFails');
		$logger   = new GoldenBookingLogger();

		$settings = $this->createStub(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($name !== 'noOpenRegister');
		$settings->method('getRegisterSlug')->willReturn('books');

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$service = new ConflictDetectionService(
			container: $container,
			settings: $settings,
			guard: $this->createStub(TransactionalGuard::class),
			logger: $logger,
		);

		try {
			$outcome = ['conflicts' => $service->checkConflicts(...$proposal)];
		} catch (Throwable $e) {
			$outcome = ['exception' => get_class($e) . ': ' . $e->getMessage()];
		}

		$outcome['reads'] = $objects->reads;
		$outcome['log']   = $logger->lines;
		$this->assertGolden(key: 'conflict ' . $name, recorded: $outcome);

	}//end testConflictsMatchGolden()

	/**
	 * The ICS payload matches the golden record.
	 *
	 * @param string $name The invitation.
	 *
	 * @return void
	 */
	#[DataProvider('icsScenarios')]
	public function testIcsMatchesGolden(string $name): void {
		$lookups = [];
		$config  = $this->createStub(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $userId, string $app, string $key, string $default) use (&$lookups): string {
				$lookups[] = $userId . '/' . $app . '/' . $key;
				if ($userId === 'broken') {
					throw new RuntimeException('config down');
				}

				return ['ams' => 'Europe/Amsterdam', 'bad' => 'Mars/Base'][$userId] ?? $default;
			}
		);

		$logger  = new GoldenBookingLogger();
		$service = new IcsService(timezoneResolver: new TimezoneResolver(config: $config, logger: $logger), logger: $logger);
		$ics     = $service->generateIcs(...self::invitations()[$name]);
		$ics     = (string) preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:NOW', $ics);

		$this->assertGolden(
			key: 'ics ' . $name,
			recorded: ['lines' => explode("\r\n", $ics), 'lookups' => $lookups, 'log' => $logger->lines]
		);

	}//end testIcsMatchesGolden()

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
	 * The proposed windows: [resourceId, start, end, excludeBookingId?].
	 *
	 * @return array<string, array<int, string|null>>
	 */
	private static function proposals(): array {
		return [
			'overlapping' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z'],
			'offsetInput' => [' room-1 ', '2026-05-21T12:30:00+02:00', '2026-05-21T13:30:00+02:00'],
			'excludeEdited' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z', 'b-1'],
			'excludeEmpty' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z', ''],
			'adjacentOnly' => ['room-1', '2026-05-21T08:00:00Z', '2026-05-21T09:00:00Z'],
			'noOverlap' => ['room-1', '2026-05-22T08:00:00Z', '2026-05-22T09:00:00Z'],
			'emptyResource' => ['  ', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z'],
			'emptyStart' => ['room-1', '', '2026-05-21T11:00:00Z'],
			'badEnd' => ['room-1', '2026-05-21T10:00:00Z', 'tomorrow-ish'],
			'endBeforeStart' => ['room-1', '2026-05-21T11:00:00Z', '2026-05-21T10:00:00Z'],
			'zeroLength' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T10:00:00Z'],
			'noOpenRegister' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z'],
			'lookupFails' => ['room-1', '2026-05-21T10:00:00Z', '2026-05-21T11:00:00Z'],
		];

	}//end proposals()

	/**
	 * The stored bookings the object service returns.
	 *
	 * @return array<int, mixed>
	 */
	private static function bookings(): array {
		return [
			[
				'bookingId' => 'b-1', 'calendar' => 'cal', 'resource' => 'room-1', 'title' => 'Intake',
				'startTime' => '2026-05-21T09:30:00Z', 'endTime' => '2026-05-21T10:30:00Z', 'status' => 'confirmed',
			],
			['bookingId' => 'b-2', 'startTime' => '2026-05-21T10:15:00+02:00', 'endTime' => '2026-05-21T12:45:00+02:00'],
			['bookingId' => 'b-3', 'startTime' => '2026-05-21T10:30:00Z', 'endTime' => '2026-05-21T10:45:00Z', 'status' => 'cancelled'],
			['bookingId' => 'b-4', 'startTime' => '2026-05-21T09:00:00Z', 'endTime' => '2026-05-21T10:00:00Z', 'status' => 'pending'],
			['bookingId' => 'b-5', 'startTime' => '2026-05-21T11:00:00Z', 'endTime' => '2026-05-21T12:00:00Z'],
			['bookingId' => 'b-6', 'startTime' => '', 'endTime' => '2026-05-21T12:00:00Z'],
			['bookingId' => 'b-7', 'startTime' => '2026-05-21T10:10:00Z'],
			['bookingId' => 'b-8', 'startTime' => 'garbage', 'endTime' => '2026-05-21T12:00:00Z'],
			['startTime' => '2026-05-21T10:20:00Z', 'endTime' => '2026-05-21T10:40:00Z', 'resource' => 'room-9'],
			['bookingId' => 'b-10', 'startTime' => '2026-05-21T10:20:00Z', 'endTime' => 'garbage'],
			new GoldenSerialisedBooking(['bookingId' => 'b-11', 'startTime' => '2026-05-21T10:50:00Z', 'endTime' => '2026-05-21T11:10:00Z']),
			'not a booking',
		];

	}//end bookings()

	/**
	 * The ICS inputs: [appointment, customer, confirmUrl, context?].
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function invitations(): array {
		$appointment = [
			'appointmentId' => 'apt-1', 'startTime' => '2026-03-29T00:30:00Z', 'endTime' => '2026-03-29T01:30:00Z',
			'notes' => "Bring ID; parking, row 2\nthanks", 'location' => 'Desk 4',
		];
		$customer    = ['userId' => 'ams', 'email' => 'jan@example.org', 'name' => 'Jan, de Vries'];
		$context     = ['serviceName' => 'Paspoort; aanvraag', 'location' => 'Stadhuis', 'organizerEmail' => 'balie@example.org'];

		return [
			'full' => [$appointment, $customer, 'https://example.org/confirm?t=1,2', $context],
			'minimal' => [['startTime' => '2026-07-01T08:00:00Z', 'endTime' => '2026-07-01T08:15:00Z'], [], ''],
			'appointmentTimezone' => [$appointment + ['timezone' => 'America/New_York'], $customer, '', ['serviceName' => 'Intake']],
			'invalidAppointmentTimezone' => [$appointment + ['timezone' => 'Nowhere/Land'], ['userId' => 'bad'], ''],
			'brokenConfig' => [$appointment, ['userId' => 'broken', 'email' => ''], 'x', ['organizerEmail' => '']],
			'emptyUser' => [$appointment, ['userId' => '', 'email' => 'a@b.c'], ''],
			'badStart' => [['appointmentId' => 'apt-2', 'startTime' => 'nope', 'endTime' => '2026-07-01T08:15:00Z'], $customer, ''],
			'missingEnd' => [['startTime' => '2026-07-01T08:00:00Z'], $customer, ''],
			'noLocationFallback' => [
				['appointmentId' => 'apt-3', 'startTime' => '2026-11-01T09:00:00Z', 'endTime' => '2026-11-01T10:00:00Z', 'location' => 'Balie 2'],
				['email' => 'x@y.z'],
				'',
			],
		];

	}//end invitations()
}//end class

/**
 * Object store double that records every read.
 */
final class GoldenBookingObjects {

	/**
	 * Every read as register, schema and findAll config.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * The current read.
	 *
	 * @var array<string, mixed>
	 */
	private array $current = [];

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $rows The stored bookings.
	 * @param bool $fails Whether the read throws.
	 */
	public function __construct(
		private readonly array $rows,
		private readonly bool $fails,
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
	 * The stored bookings.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, mixed>
	 */
	public function findAll(array $config = []): array {
		$this->reads[] = $this->current + ['config' => $config];
		if ($this->fails === true) {
			throw new RuntimeException('database gone');
		}

		return $this->rows;
	}//end findAll()
}//end class

/**
 * A stored booking that serialises to its row.
 */
final class GoldenSerialisedBooking {

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
final class GoldenBookingLogger extends AbstractLogger {

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
