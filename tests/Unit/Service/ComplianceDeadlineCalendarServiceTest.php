<?php

/**
 * Unit tests for ComplianceDeadlineCalendarService.
 *
 * Covers REQ-CDC-001 (idempotent fail-soft VEVENT publication),
 * REQ-CDC-002 (BTW/ICP/VPB filing deadlines from period data + removal
 * on submitted), REQ-CDC-003 (payment-run dates + removal on executed),
 * REQ-CDC-004 (AR due dates opt-in, default off, removal on paid),
 * REQ-CDC-005 (contract category delegated to ObligationTaskBridge),
 * REQ-CDC-006 (category toggles remove events) and REQ-CDC-007
 * (exactly-one reminder per deadline per user within lead time).
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
 * @spec openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCA\Shillinq\Service\ObligationTaskBridge;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplianceDeadlineCalendarService (REQ-CDC-001..007).
 */
class ComplianceDeadlineCalendarServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IConfig backed by an in-memory user-preference store.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * In-memory user-preference store: [userId][key] => value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $userPrefs = [];

	/**
	 * Mock IUserManager (callForSeenUsers iterates $this->seenUsers).
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * The seen user ids iterated by callForSeenUsers.
	 *
	 * @var array<int, string>
	 */
	private array $seenUsers = ['alice'];

	/**
	 * Mock notification manager; notify() calls are recorded.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager&MockObject $notificationMgr;

	/**
	 * Recorded [user, objectId] pairs for every notify() call.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $notified = [];

	/**
	 * Per-notification captured meta keyed by spl_object_id.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $notificationMeta = [];

	/**
	 * Mock ObligationTaskBridge (contract category delegation seam).
	 *
	 * @var ObligationTaskBridge&MockObject
	 */
	private ObligationTaskBridge&MockObject $bridge;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The in-memory ObjectService stub of the current test.
	 *
	 * @var object|null
	 */
	private ?object $objectServiceStub = null;

	/**
	 * The in-memory calendar stub of the current test.
	 *
	 * @var object|null
	 */
	private ?object $calendarStub = null;

	/**
	 * Set up shared mocks with a stateful user-preference store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationMgr = $this->createMock(INotificationManager::class);
		$this->bridge = $this->createMock(ObligationTaskBridge::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->userPrefs = [];
		$this->notified = [];
		$this->notificationMeta = [];
		$this->seenUsers = ['alice'];

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, string $default = ''): string {
				return ($this->userPrefs[$userId][$key] ?? $default);
			}
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appName, string $key, string $value): void {
				$this->userPrefs[$userId][$key] = $value;
			}
		);

		$this->userManager->method('callForSeenUsers')->willReturnCallback(
			function (callable $callback): void {
				foreach ($this->seenUsers as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			}
		);

		$this->notificationMgr->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				$key = spl_object_id($notification);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setObject')->willReturnCallback(
					function (string $type, string $id) use ($notification, $key): INotification {
						$this->notificationMeta[$key]['objectId'] = $id;
						return $notification;
					}
				);
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use ($notification, $key): INotification {
						$this->notificationMeta[$key]['userId'] = $user;
						return $notification;
					}
				);
				return $notification;
			}
		);
		$this->notificationMgr->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$meta = ($this->notificationMeta[spl_object_id($notification)] ?? []);
				$this->notified[] = [
					(string)($meta['userId'] ?? ''),
					(string)($meta['objectId'] ?? ''),
				];
			}
		);

		$this->bridge->method('listOpenObligationDeadlines')->willReturn([]);

	}//end setUp()

	/**
	 * Build the service wired to in-memory ObjectService + calendar stubs.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Seed OR records by schema.
	 * @param bool $withCalendar Whether a calendar backend resolves.
	 * @param array<int, string> $existingUids Pre-existing app VEVENT UIDs on the calendar.
	 *
	 * @return ComplianceDeadlineCalendarService
	 */
	private function buildService(
		array $recordsBySchema = [],
		bool $withCalendar = true,
		array $existingUids = [],
	): ComplianceDeadlineCalendarService {
		$this->objectServiceStub = $this->buildObjectServiceStub(recordsBySchema: $recordsBySchema);
		$this->calendarStub = $this->buildCalendarStub(existingUids: $existingUids);
		$calendarManager = $this->buildCalendarManagerStub(calendar: $this->calendarStub);

		$this->container->method('has')->willReturnCallback(
			static function (string $id) use ($withCalendar): bool {
				if ($id === 'OCP\\Calendar\\IManager') {
					return $withCalendar;
				}

				return true;
			}
		);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($calendarManager, $withCalendar) {
				if ($id === 'OCP\\Calendar\\IManager') {
					if ($withCalendar === false) {
						throw new \RuntimeException('no calendar backend');
					}

					return $calendarManager;
				}

				return $this->objectServiceStub;
			}
		);

		return new ComplianceDeadlineCalendarService(
			container: $this->container,
			appConfig: $this->appConfig,
			config: $this->config,
			userManager: $this->userManager,
			notificationMgr: $this->notificationMgr,
			obligationTaskBridge: $this->bridge,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->objectServiceStub),
		);

	}//end buildService()

	/**
	 * Build a read-only ObjectService stub that records requested schemas.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $recordsBySchema;

			/**
			 * Every schema requested via setSchema(), in order.
			 *
			 * @var array<int, string>
			 */
			public array $requestedSchemas = [];

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter (recorded).
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				$this->requestedSchemas[] = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the stubbed records of the active schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return array_values($this->recordsBySchema[$this->currentSchema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build a write-recording calendar stub (createFromString + search).
	 *
	 * @param array<int, string> $existingUids Pre-existing app VEVENT UIDs.
	 *
	 * @return object
	 */
	private function buildCalendarStub(array $existingUids): object {
		return new class($existingUids) {
			/**
			 * Every createFromString() call as [name, calendarData].
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public array $writes = [];

			/**
			 * The pre-existing UIDs surfaced via search().
			 *
			 * @var array<int, string>
			 */
			private array $existingUids;

			/**
			 * Constructor.
			 *
			 * @param array<int, string> $existingUids Pre-existing UIDs.
			 */
			public function __construct(array $existingUids) {
				$this->existingUids = $existingUids;

			}//end __construct()

			/**
			 * The dedicated app-calendar URI.
			 *
			 * @return string
			 */
			public function getUri(): string {
				return 'shillinq-deadlines';
			}//end getUri()

			/**
			 * Record an upsert write.
			 *
			 * @param string $name Calendar-object name.
			 * @param string $calendarData The VCALENDAR payload.
			 *
			 * @return void
			 */
			public function createFromString(string $name, string $calendarData): void {
				$this->writes[] = [$name, $calendarData];

			}//end createFromString()

			/**
			 * Surface the pre-existing UIDs in ICalendar::search() shape.
			 *
			 * @param string $pattern Search pattern (unused).
			 * @param array<int, string> $searchProperties Searched properties (unused).
			 * @param array<string, mixed> $options Search options (unused).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function search(string $pattern, array $searchProperties = [], array $options = []): array {
				$results = [];
				foreach ($this->existingUids as $uid) {
					$results[] = [
						'uid' => $uid,
						'objects' => [['STATUS' => ['CONFIRMED']]],
					];
				}

				return $results;
			}//end search()
		};

	}//end buildCalendarStub()

	/**
	 * Build a calendar-manager stub returning one calendar for any principal.
	 *
	 * @param object $calendar The calendar stub.
	 *
	 * @return object
	 */
	private function buildCalendarManagerStub(object $calendar): object {
		return new class($calendar) {
			/**
			 * The provided calendar.
			 *
			 * @var object
			 */
			private object $calendar;

			/**
			 * Constructor.
			 *
			 * @param object $calendar The calendar stub.
			 */
			public function __construct(object $calendar) {
				$this->calendar = $calendar;

			}//end __construct()

			/**
			 * Return the single stub calendar for every principal.
			 *
			 * @param string $principalUri Principal URI.
			 * @param array<int, string> $calendarUris Optional URI filter.
			 *
			 * @return array<int, object>
			 */
			public function getCalendarsForPrincipal(string $principalUri, array $calendarUris = []): array {
				return [$this->calendar];
			}//end getCalendarsForPrincipal()
		};

	}//end buildCalendarManagerStub()

	/**
	 * A draft VATReturn row for 2026-Q1 (endDate 2026-03-31).
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function vatReturn(array $overrides = []): array {
		return array_merge(
			[
				'@self' => ['slug' => 'vatreturn-2026-Q1'],
				'period' => 'quarter',
				'periodYear' => 2026,
				'periodNumber' => 1,
				'endDate' => '2026-03-31',
				'statusCode' => 'draft',
			],
			$overrides
		);

	}//end vatReturn()

	/**
	 * REQ-CDC-001 scenario 1 — publishing the same BTW deadline twice
	 * upserts ONE calendar object (same UID-derived filename) carrying
	 * the derived due date.
	 *
	 * @return void
	 */
	public function testDeadlineIsPublishedAsIdempotentVevent(): void {
		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$this->vatReturn()]],
		);

		$first = $service->publishForUser(userId: 'alice');
		$second = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $first['status']);
		self::assertSame('ok', $second['status']);

		$writes = $this->calendarStub->writes;
		self::assertCount(2, $writes, 'two publish runs → two upsert writes');

		// Idempotent: both runs target the SAME calendar-object name.
		self::assertSame($writes[0][0], $writes[1][0]);
		self::assertStringContainsString('UID:btw-filing:vatreturn-2026-Q1', $writes[0][1]);
		// BTW due date = last day of the month following the period end.
		self::assertStringContainsString('DTSTART;VALUE=DATE:20260430', $writes[0][1]);
		self::assertStringContainsString('SUMMARY:BTW-aangifte 2026-Q1', $writes[0][1]);
		self::assertStringContainsString('STATUS:CONFIRMED', $writes[0][1]);

	}//end testDeadlineIsPublishedAsIdempotentVevent()

	/**
	 * REQ-CDC-001 — ObjectService::findAll() yields ObjectEntity objects,
	 * never arrays. Before normalisation the calendar read them with array
	 * syntax, threw "Cannot use object of type ... as array", and the
	 * surrounding catch reported a handled fail-soft degradation while
	 * publishing NOTHING. A real ObjectEntity must publish exactly what the
	 * equivalent array row publishes.
	 *
	 * Positive control: the assertions below are the same ones
	 * testDeadlineIsPublishedAsIdempotentVevent() makes on an array row, so
	 * a regression to pass-through would produce zero writes, not a
	 * differently-shaped one.
	 *
	 * @return void
	 */
	public function testObjectEntityRowsAreNormalisedBeforePublication(): void {
		$entity = new ObjectEntity();
		$entity->setObject($this->vatReturn());

		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$entity]],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);

		$writes = $this->calendarStub->writes;
		self::assertCount(1, $writes, 'the ObjectEntity row must reach the calendar');
		self::assertStringContainsString('UID:btw-filing:vatreturn-2026-Q1', $writes[0][1]);
		self::assertStringContainsString('DTSTART;VALUE=DATE:20260430', $writes[0][1]);
		self::assertStringContainsString('STATUS:CONFIRMED', $writes[0][1]);

	}//end testObjectEntityRowsAreNormalisedBeforePublication()

	/**
	 * REQ-CDC-001 — a row exposing jsonSerialize() is normalised through it
	 * (the house idiom's first branch), without needing getObject().
	 *
	 * @return void
	 */
	public function testRowsExposingJsonSerializeAreNormalised(): void {
		$row = $this->vatReturn();

		$service = $this->buildService(
			recordsBySchema: [
				'BtwAangifte' => [
					new class($row) {

						/**
						 * The payload returned by jsonSerialize().
						 *
						 * @var array<string,mixed>
						 */
						private array $payload;

						/**
						 * Constructor.
						 *
						 * @param array<string,mixed> $payload The payload.
						 */
						public function __construct(array $payload) {
							$this->payload = $payload;

						}//end __construct()

						/**
						 * Serialise to the array shape the callers expect.
						 *
						 * @return array<string,mixed>
						 */
						public function jsonSerialize(): array {
							return $this->payload;
						}//end jsonSerialize()
					},
				],
			],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);
		self::assertCount(1, $this->calendarStub->writes);
		self::assertStringContainsString(
			'UID:btw-filing:vatreturn-2026-Q1',
			$this->calendarStub->writes[0][1]
		);

	}//end testRowsExposingJsonSerializeAreNormalised()

	/**
	 * REQ-CDC-001 — jsonSerialize() is not required to return an array
	 * (OpenRegister's own entity has returned scalars for partial reads).
	 * When it does not, normalisation must fall through to getObject()
	 * rather than appending the non-array and fataling downstream.
	 *
	 * @return void
	 */
	public function testNonArrayJsonSerializeFallsBackToGetObject(): void {
		$row = $this->vatReturn();

		$service = $this->buildService(
			recordsBySchema: [
				'BtwAangifte' => [
					new class($row) {

						/**
						 * The payload returned by getObject().
						 *
						 * @var array<string,mixed>
						 */
						private array $payload;

						/**
						 * Constructor.
						 *
						 * @param array<string,mixed> $payload The payload.
						 */
						public function __construct(array $payload) {
							$this->payload = $payload;

						}//end __construct()

						/**
						 * Deliberately NOT an array — the fall-through trigger.
						 *
						 * @return string
						 */
						public function jsonSerialize(): string {
							return 'not-an-array';
						}//end jsonSerialize()

						/**
						 * The array shape the callers expect.
						 *
						 * @return array<string,mixed>
						 */
						public function getObject(): array {
							return $this->payload;
						}//end getObject()
					},
				],
			],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);
		self::assertStringContainsString(
			'UID:btw-filing:vatreturn-2026-Q1',
			$this->calendarStub->writes[0][1]
		);

	}//end testNonArrayJsonSerializeFallsBackToGetObject()

	/**
	 * REQ-CDC-001 — a row matching NEITHER normalisation shape is skipped
	 * with a warning rather than appended. A dropped row is recoverable; a
	 * fatal in a CRUD path is not. The warning is what makes the drop
	 * visible, so its absence is the failure this asserts against.
	 *
	 * @return void
	 */
	public function testUnsupportedRowShapeIsSkippedWithAWarning(): void {
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function ($message, $context = []) use (&$warnings): void {
				$warnings[] = [(string)$message, $context];
			}
		);

		$service = $this->buildService(
			recordsBySchema: [
				'BtwAangifte' => [
					new class {

						/**
						 * Neither jsonSerialize() nor getObject() — this shape
						 * cannot be normalised and must not be appended.
						 *
						 * @return string
						 */
						public function describe(): string {
							return 'unsupported';
						}//end describe()
					},
				],
			],
		);

		$result = $service->publishForUser(userId: 'alice');

		// Fail-soft: the run completes, it just publishes nothing.
		self::assertSame('ok', $result['status']);
		self::assertSame(0, $result['published']);
		self::assertSame([], $this->calendarStub->writes);

		$skipped = array_filter(
			$warnings,
			static function (array $entry): bool {
				return str_contains($entry[0], 'unsupported row type from ObjectService::findAll');
			}
		);
		self::assertCount(1, $skipped, 'the dropped row must be logged, not swallowed');
		$context = array_values($skipped)[0][1];
		self::assertSame('BtwAangifte', $context['schema']);

	}//end testUnsupportedRowShapeIsSkippedWithAWarning()

	/**
	 * REQ-CDC-001 scenario 2 — no calendar backend: publication logs,
	 * returns 'failed' and does NOT throw; sources are never touched.
	 *
	 * @return void
	 */
	public function testNoCalendarBackendNeverBlocksTheSource(): void {
		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$this->vatReturn()]],
			withCalendar: false,
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('failed', $result['status']);
		self::assertSame(0, $result['published']);
		// Fail-fast before any source read: the OR stub was never queried.
		self::assertSame([], $this->objectServiceStub->requestedSchemas);

	}//end testNoCalendarBackendNeverBlocksTheSource()

	/**
	 * REQ-CDC-002 — a submitted BTW filing's VEVENT is removed
	 * (overwritten as STATUS:CANCELLED through the public upsert seam).
	 *
	 * @return void
	 */
	public function testSubmittedFilingRemovesItsVevent(): void {
		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$this->vatReturn(['statusCode' => 'submitted'])]],
			existingUids: ['btw-filing:vatreturn-2026-Q1'],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(0, $result['published']);
		self::assertSame(1, $result['removed']);

		$writes = $this->calendarStub->writes;
		self::assertCount(1, $writes);
		self::assertStringContainsString('UID:btw-filing:vatreturn-2026-Q1', $writes[0][1]);
		self::assertStringContainsString('STATUS:CANCELLED', $writes[0][1]);

	}//end testSubmittedFilingRemovesItsVevent()

	/**
	 * REQ-CDC-002 — ICP and VPB filing deadlines are derived from the
	 * existing period data (ICP: last day of month after the period;
	 * VPB: the TaxDeadline's own deadlineDate).
	 *
	 * @return void
	 */
	public function testIcpAndVpbFilingDeadlinesAreDerivedFromPeriodData(): void {
		$service = $this->buildService(
			recordsBySchema: [
				'IcpOpgaaf' => [
					[
						'@self' => ['slug' => 'icp-2026-Q1'],
						'period' => '2026-Q1',
						'status' => 'draft',
					],
					[
						'@self' => ['slug' => 'icp-2026-05'],
						'period' => '2026-05',
						'status' => 'submitted',
					],
				],
				'TaxDeadline' => [
					[
						'@self' => ['slug' => 'vpb-2026-voorlopig'],
						'deadlineDate' => '2026-06-01',
						'deadlineType' => 'voorlopige-aanslag',
						'fiscalYear' => 2026,
						'status' => 'pending',
					],
				],
			],
		);

		$deadlines = $service->collectDeadlines();
		$byUid = [];
		foreach ($deadlines as $deadline) {
			$byUid[$deadline['uid']] = $deadline;
		}

		// Open ICP opgaaf: Q1 end 2026-03-31 → due 2026-04-30.
		self::assertArrayHasKey('icp-filing:icp-2026-Q1', $byUid);
		self::assertSame('2026-04-30', $byUid['icp-filing:icp-2026-Q1']['dueDate']);
		self::assertSame('ICP-opgaaf 2026-Q1', $byUid['icp-filing:icp-2026-Q1']['summary']);

		// Submitted ICP opgaaf is NOT an open deadline.
		self::assertArrayNotHasKey('icp-filing:icp-2026-05', $byUid);

		// VPB deadline carries its own date.
		self::assertArrayHasKey('vpb-filing:vpb-2026-voorlopig', $byUid);
		self::assertSame('2026-06-01', $byUid['vpb-filing:vpb-2026-voorlopig']['dueDate']);

	}//end testIcpAndVpbFilingDeadlinesAreDerivedFromPeriodData()

	/**
	 * REQ-CDC-003 — a scheduled payment run appears on the calendar on
	 * its execution date; an exported (executed) run is removed.
	 *
	 * @return void
	 */
	public function testPaymentRunPublishedAndRemovedOnceExecuted(): void {
		$service = $this->buildService(
			recordsBySchema: [
				'PaymentRun' => [
					[
						'@self' => ['slug' => 'run-2026-02'],
						'runNumber' => 'RUN-2026-02',
						'executionDate' => '2026-02-28',
						'lifecycleState' => 'approved',
					],
					[
						'@self' => ['slug' => 'run-2026-01'],
						'runNumber' => 'RUN-2026-01',
						'executionDate' => '2026-01-31',
						'lifecycleState' => 'exported',
					],
				],
			],
			existingUids: ['payment-run:run-2026-01'],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);
		self::assertSame(1, $result['removed']);

		$confirmed = null;
		$cancelled = null;
		foreach ($this->calendarStub->writes as $write) {
			if (str_contains($write[1], 'STATUS:CANCELLED') === true) {
				$cancelled = $write[1];
			} else {
				$confirmed = $write[1];
			}
		}

		self::assertNotNull($confirmed);
		self::assertStringContainsString('UID:payment-run:run-2026-02', $confirmed);
		self::assertStringContainsString('DTSTART;VALUE=DATE:20260228', $confirmed);
		self::assertStringContainsString('SUMMARY:Betaalrun RUN-2026-02', $confirmed);

		self::assertNotNull($cancelled);
		self::assertStringContainsString('UID:payment-run:run-2026-01', $cancelled);

	}//end testPaymentRunPublishedAndRemovedOnceExecuted()

	/**
	 * REQ-CDC-004 scenario 1 — AR due dates are hidden by default (the
	 * category is opt-in).
	 *
	 * @return void
	 */
	public function testArDueDatesHiddenByDefault(): void {
		$service = $this->buildService(
			recordsBySchema: [
				'ARInvoice' => [
					[
						'@self' => ['slug' => 'inv-1001'],
						'invoiceNumber' => 'INV-1001',
						'dueDate' => '2026-03-15',
						'lifecycleState' => 'issued',
					],
				],
			],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(0, $result['published']);
		self::assertSame([], $this->calendarStub->writes);

	}//end testArDueDatesHiddenByDefault()

	/**
	 * REQ-CDC-004 scenario 2 — enabling the AR category publishes open
	 * due dates; a paid invoice's VEVENT is removed.
	 *
	 * @return void
	 */
	public function testEnablingArCategoryPublishesAndPaidRemoves(): void {
		$this->userPrefs['alice']['deadline_calendar_ar-due'] = '1';

		$service = $this->buildService(
			recordsBySchema: [
				'ARInvoice' => [
					[
						'@self' => ['slug' => 'inv-1001'],
						'invoiceNumber' => 'INV-1001',
						'dueDate' => '2026-03-15',
						'lifecycleState' => 'issued',
					],
					[
						'@self' => ['slug' => 'inv-0900'],
						'invoiceNumber' => 'INV-0900',
						'dueDate' => '2026-01-15',
						'lifecycleState' => 'paid',
					],
				],
			],
			existingUids: ['ar-invoice:inv-0900'],
		);

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);
		self::assertSame(1, $result['removed']);

		$joined = implode("\n---\n", array_column($this->calendarStub->writes, 1));
		self::assertStringContainsString('UID:ar-invoice:inv-1001', $joined);
		self::assertStringContainsString('DTSTART;VALUE=DATE:20260315', $joined);

	}//end testEnablingArCategoryPublishesAndPaidRemoves()

	/**
	 * REQ-CDC-005 — the contract category is delegated to the extended
	 * ObligationTaskBridge; the service never re-reads ContractObligation.
	 *
	 * @return void
	 */
	public function testContractDeadlinesAreDelegatedToTheBridge(): void {
		// Fresh bridge mock with an explicit expectation.
		$this->bridge = $this->createMock(ObligationTaskBridge::class);
		$this->bridge->expects(self::atLeastOnce())
			->method('listOpenObligationDeadlines')
			->willReturn(
				[
					[
						'uid' => 'contract:obligation-crm-sla-review',
						'category' => 'contract',
						'summary' => 'Quarterly SLA review',
						'dueDate' => '2026-06-01',
						'source' => 'contract',
						'objectId' => 'obligation-crm-sla-review',
					],
				]
			);

		$service = $this->buildService();

		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(1, $result['published']);
		self::assertStringContainsString(
			'UID:contract:obligation-crm-sla-review',
			$this->calendarStub->writes[0][1]
		);

		// The service itself must NOT query ContractObligation (single home).
		self::assertNotContains('ContractObligation', $this->objectServiceStub->requestedSchemas);

	}//end testContractDeadlinesAreDelegatedToTheBridge()

	/**
	 * REQ-CDC-006 — toggling the payment-run category off removes its
	 * published VEVENTs and publishes no new ones.
	 *
	 * @return void
	 */
	public function testTogglingCategoryOffRemovesItsEvents(): void {
		$service = $this->buildService(
			recordsBySchema: [
				'PaymentRun' => [
					[
						'@self' => ['slug' => 'run-2026-02'],
						'runNumber' => 'RUN-2026-02',
						'executionDate' => '2026-02-28',
						'lifecycleState' => 'approved',
					],
				],
			],
			existingUids: ['payment-run:run-2026-02'],
		);

		$service->setCategoryEnabled(userId: 'alice', category: 'payment-run', enabled: false);
		$result = $service->publishForUser(userId: 'alice');

		self::assertSame('ok', $result['status']);
		self::assertSame(0, $result['published']);
		self::assertSame(1, $result['removed']);
		self::assertStringContainsString('STATUS:CANCELLED', $this->calendarStub->writes[0][1]);

	}//end testTogglingCategoryOffRemovesItsEvents()

	/**
	 * REQ-CDC-007 scenario 1 — a filing deadline 7 days out with a
	 * 10-day lead time raises EXACTLY ONE notification, also across a
	 * second (daily) run.
	 *
	 * @return void
	 */
	public function testReminderFiresExactlyOnceWithinLeadTime(): void {
		$today = new DateTimeImmutable('2026-04-23');
		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$this->vatReturn()]],
		);
		// BTW due 2026-04-30 = 7 days from 2026-04-23; filing lead = 10.

		$first = $service->dispatchDueReminders(now: $today);
		$second = $service->dispatchDueReminders(now: $today->modify('+1 day'));

		self::assertSame(1, $first);
		self::assertSame(0, $second, 'second daily run must not re-notify');
		self::assertCount(1, $this->notified);
		self::assertSame('alice', $this->notified[0][0]);
		self::assertSame('btw-filing:vatreturn-2026-Q1', $this->notified[0][1]);

	}//end testReminderFiresExactlyOnceWithinLeadTime()

	/**
	 * REQ-CDC-007 scenario 2 — a disabled category raises no reminder
	 * (AR due dates are off by default).
	 *
	 * @return void
	 */
	public function testDisabledCategorySuppressesReminder(): void {
		$today = new DateTimeImmutable('2026-03-12');
		$service = $this->buildService(
			recordsBySchema: [
				'ARInvoice' => [
					[
						'@self' => ['slug' => 'inv-1001'],
						'invoiceNumber' => 'INV-1001',
						'dueDate' => '2026-03-15',
						'lifecycleState' => 'issued',
					],
				],
			],
		);

		$count = $service->dispatchDueReminders(now: $today);

		self::assertSame(0, $count);
		self::assertSame([], $this->notified);

	}//end testDisabledCategorySuppressesReminder()

	/**
	 * A deadline beyond the lead time raises no reminder yet.
	 *
	 * @return void
	 */
	public function testReminderRespectsLeadTimeWindow(): void {
		$today = new DateTimeImmutable('2026-04-01');
		$service = $this->buildService(
			recordsBySchema: ['BtwAangifte' => [$this->vatReturn()]],
		);
		// Due 2026-04-30 = 29 days out > 10-day filing lead.

		self::assertSame(0, $service->dispatchDueReminders(now: $today));
		self::assertSame([], $this->notified);

	}//end testReminderRespectsLeadTimeWindow()
}//end class
