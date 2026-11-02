<?php

/**
 * Golden record of ComplianceDeadlineCalendarService's deadline collection and
 * calendar resolution.
 *
 * Each collect scenario feeds one source schema a set of rows (every status,
 * date and identifier branch) and compares the collected deadlines plus the
 * requested schemas with tests/Unit/Service/fixtures/compliance-deadline-collect-golden.json.
 * Each calendar scenario wires one calendar backend shape and compares the
 * resolved calendar and the log lines. SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
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

use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCA\Shillinq\Service\ObligationTaskBridge;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Deadline collection and calendar resolution golden tests.
 *
 * @covers \OCA\Shillinq\Service\ComplianceDeadlineCalendarService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ComplianceDeadlineCollectGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/compliance-deadline-collect-golden.json';

	/**
	 * One collect scenario per source schema, plus the empty and the failing read.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function collectScenarios(): array {
		$cases = [];
		foreach (array_keys(self::rowsBySchema()) as $schema) {
			$cases['collect ' . $schema] = [$schema];
		}

		$cases['collect empty']     = ['empty'];
		$cases['collect readFails'] = ['readFails'];
		$cases['collect bridge']    = ['bridge'];

		return $cases;

	}//end collectScenarios()

	/**
	 * One scenario per calendar backend shape.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function calendarScenarios(): array {
		$cases = [];
		foreach (['noManager', 'getThrows', 'managerNotObject', 'managerWithoutMethod', 'calendarsNotArray', 'emptyList', 'dedicatedLast', 'dedicatedFirst', 'fallbackOnly', 'nothingWritable'] as $mode) {
			$cases['calendar ' . $mode] = [$mode];
		}

		return $cases;

	}//end calendarScenarios()

	/**
	 * The collected deadlines match the golden record.
	 *
	 * @param string $schema The schema whose rows are fed.
	 *
	 * @return void
	 */
	#[DataProvider('collectScenarios')]
	public function testCollectMatchesGolden(string $schema): void {
		$this->assertGolden(key: 'collect ' . $schema, recorded: $this->runCollect(schema: $schema));

	}//end testCollectMatchesGolden()

	/**
	 * The resolved calendar matches the golden record.
	 *
	 * @param string $mode The calendar backend shape.
	 *
	 * @return void
	 */
	#[DataProvider('calendarScenarios')]
	public function testCalendarMatchesGolden(string $mode): void {
		$this->assertGolden(key: 'calendar ' . $mode, recorded: $this->runCalendar(mode: $mode));

	}//end testCalendarMatchesGolden()

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
	 * Run collectDeadlines() with rows for one schema.
	 *
	 * @param string $schema The schema whose rows are fed.
	 *
	 * @return array<string, mixed>
	 */
	private function runCollect(string $schema): array {
		$rows = [];
		if (isset(self::rowsBySchema()[$schema]) === true) {
			$rows = [$schema => self::rowsBySchema()[$schema]];
		}

		$objects = new GoldenDeadlineObjects(rowsBySchema: $rows, fails: $schema === 'readFails');
		$bridge  = $this->createStub(ObligationTaskBridge::class);
		$bridgeRows = [];
		if ($schema === 'bridge') {
			$bridgeRows = [
				['uid' => 'contract:c-1', 'category' => 'contract', 'summary' => 'Opzegtermijn', 'dueDate' => '2026-09-01', 'source' => 'contract', 'objectId' => 'c-1'],
				['uid' => 'contract:c-2', 'category' => 'contract', 'summary' => 'Verlenging', 'dueDate' => '2026-10-01', 'source' => 'contract', 'objectId' => 'c-2'],
			];
		}

		$bridge->method('listOpenObligationDeadlines')->willReturn($bridgeRows);

		$logger  = new GoldenDeadlineLogger();
		$service = $this->service(container: $this->createStub(ContainerInterface::class), bridge: $bridge, objects: $objects, logger: $logger);

		return [
			'deadlines' => $service->collectDeadlines(),
			'schemas'   => $objects->requested,
			'log'       => $logger->lines,
		];

	}//end runCollect()

	/**
	 * Resolve the calendar for one backend shape.
	 *
	 * @param string $mode The calendar backend shape.
	 *
	 * @return array<string, mixed>
	 */
	private function runCalendar(string $mode): array {
		$manager   = new GoldenCalendarManager(calendars: $this->calendars(mode: $mode));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('has')->willReturn($mode !== 'noManager');
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mode, $manager): mixed {
				if ($mode === 'getThrows') {
					throw new RuntimeException('no ' . $id);
				}

				if ($mode === 'managerNotObject') {
					return 'manager';
				}

				if ($mode === 'managerWithoutMethod') {
					return new GoldenUrilessCalendar(label: 'not-a-manager');
				}

				return $manager;
			}
		);

		$logger  = new GoldenDeadlineLogger();
		$service = $this->service(container: $container, bridge: $this->createStub(ObligationTaskBridge::class), objects: new GoldenDeadlineObjects(rowsBySchema: [], fails: false), logger: $logger);
		$method  = new ReflectionMethod(ComplianceDeadlineCalendarService::class, 'resolveUserCalendar');
		$found   = $method->invoke($service, 'alice');

		$label = null;
		if ($found instanceof GoldenCalendar || $found instanceof GoldenUrilessCalendar) {
			$label = $found->label;
		}

		return [
			'calendar'  => $label,
			'principal' => $manager->principals,
			'log'       => $logger->lines,
		];

	}//end runCalendar()

	/**
	 * The calendar list a manager returns for one mode.
	 *
	 * @param string $mode The calendar backend shape.
	 *
	 * @return mixed
	 */
	private function calendars(string $mode): mixed {
		$readOnly  = new GoldenCalendar(uri: 'shillinq-deadlines', label: 'read-only-dedicated');
		$noUri     = new GoldenUrilessCalendar(label: 'writable-no-uri');
		$personal  = new GoldenWritableCalendar(uri: 'personal', label: 'personal');
		$dedicated = new GoldenWritableCalendar(uri: 'shillinq-deadlines', label: 'dedicated');

		return match ($mode) {
			'calendarsNotArray' => 'calendars',
			'emptyList' => [],
			'dedicatedLast' => ['text', $readOnly, $noUri, $personal, $dedicated],
			'dedicatedFirst' => [$dedicated, $personal],
			'fallbackOnly' => [$readOnly, $personal, $noUri],
			'nothingWritable' => [$readOnly, 42],
			default => [$personal],
		};

	}//end calendars()

	/**
	 * Build the service under test.
	 *
	 * @param ContainerInterface $container The container.
	 * @param ObligationTaskBridge $bridge The contract-deadline bridge.
	 * @param GoldenDeadlineObjects $objects The object store double.
	 * @param GoldenDeadlineLogger $logger The recording logger.
	 *
	 * @return ComplianceDeadlineCalendarService
	 */
	private function service(ContainerInterface $container, ObligationTaskBridge $bridge, GoldenDeadlineObjects $objects, GoldenDeadlineLogger $logger): ComplianceDeadlineCalendarService {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('books');

		return new ComplianceDeadlineCalendarService(
			container: $container,
			appConfig: $appConfig,
			config: $this->createStub(IConfig::class),
			userManager: $this->createStub(IUserManager::class),
			notificationMgr: $this->createStub(INotificationManager::class),
			obligationTaskBridge: $bridge,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($objects),
		);

	}//end service()

	/**
	 * Rows per source schema covering every status, date and identifier branch.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function rowsBySchema(): array {
		return [
			'BtwAangifte' => [
				['@self' => ['slug' => 'btw-q1', 'id' => 'x'], 'period' => 'quarter', 'periodYear' => 2026, 'periodNumber' => 1, 'endDate' => '2026-03-31', 'statusCode' => 'draft'],
				['@self' => ['id' => 'btw-m2'], 'period' => 'month', 'periodYear' => 2026, 'periodNumber' => 2, 'endDate' => '2026-02-28'],
				['id' => 'btw-y', 'period' => 'year', 'periodYear' => 2025, 'endDate' => '2025-12-31T10:00:00Z', 'statusCode' => 'draft'],
				['uuid' => 'btw-default-unit', 'periodYear' => 2026, 'periodNumber' => 3, 'endDate' => '2026-09-30', 'statusCode' => 'draft'],
				['@self' => ['slug' => 'btw-filed'], 'endDate' => '2026-03-31', 'statusCode' => 'filed'],
				['@self' => ['slug' => 'btw-no-end'], 'statusCode' => 'draft'],
				['@self' => ['slug' => 'btw-bad-end'], 'endDate' => 'not a date', 'statusCode' => 'draft'],
				['@self' => 'scalar', 'endDate' => '2026-03-31', 'statusCode' => 'draft'],
				['endDate' => '2026-06-30', 'statusCode' => 'draft'],
			],
			'IcpOpgaaf' => [
				['@self' => ['slug' => 'icp-q2'], 'period' => '2026-Q2', 'status' => 'draft'],
				['@self' => ['slug' => 'icp-q4-lower'], 'period' => '2025-q4', 'status' => 'finalized'],
				['@self' => ['slug' => 'icp-m5'], 'period' => '2026-05', 'status' => 'rejected'],
				['@self' => ['slug' => 'icp-m12-default'], 'period' => '2026-12'],
				['@self' => ['slug' => 'icp-m13'], 'period' => '2026-13', 'status' => 'draft'],
				['@self' => ['slug' => 'icp-m00'], 'period' => '2026-00', 'status' => 'draft'],
				['@self' => ['slug' => 'icp-bad'], 'period' => 'Q2-2026', 'status' => 'draft'],
				['@self' => ['slug' => 'icp-none'], 'status' => 'draft'],
				['@self' => ['slug' => 'icp-submitted'], 'period' => '2026-Q1', 'status' => 'submitted'],
				['period' => '2026-Q1', 'status' => 'draft'],
			],
			'TaxDeadline' => [
				['@self' => ['slug' => 'vpb-1'], 'deadlineType' => 'Aangifte', 'fiscalYear' => 2025, 'deadlineDate' => '2026-05-01', 'status' => 'pending'],
				['@self' => ['slug' => 'vpb-2'], 'deadlineDate' => '2026-06-01'],
				['@self' => ['slug' => 'vpb-3'], 'fiscalYear' => 2024, 'deadlineDate' => '2026-07-01 12:00'],
				['@self' => ['slug' => 'vpb-done'], 'deadlineDate' => '2026-05-01', 'status' => 'filed'],
				['@self' => ['slug' => 'vpb-no-date'], 'status' => 'pending'],
				['@self' => ['slug' => 'vpb-bad-date'], 'deadlineDate' => 'soon', 'status' => 'pending'],
				['deadlineDate' => '2026-05-01', 'status' => 'pending'],
			],
			'PaymentRun' => [
				['@self' => ['slug' => 'run-1'], 'runNumber' => 'BR-7', 'executionDate' => '2026-04-10', 'lifecycleState' => 'draft'],
				['@self' => ['slug' => 'run-2'], 'executionDate' => '2026-04-11', 'lifecycleState' => 'approved'],
				['@self' => ['slug' => 'run-3'], 'runNumber' => 'BR-9', 'executionDate' => '2026-04-12', 'status' => 'approved'],
				['@self' => ['slug' => 'run-4'], 'executionDate' => '2026-04-13'],
				['@self' => ['slug' => 'run-5'], 'executionDate' => '2026-04-14', 'lifecycleState' => 'exported', 'status' => 'draft'],
				['@self' => ['slug' => 'run-6'], 'executionDate' => '2026-04-15', 'status' => 'reconciled'],
				['@self' => ['slug' => 'run-7'], 'lifecycleState' => 'draft'],
				['@self' => ['slug' => 'run-8'], 'executionDate' => 'later', 'lifecycleState' => 'draft'],
				['executionDate' => '2026-04-16', 'lifecycleState' => 'draft'],
			],
			'ARInvoice' => [
				['@self' => ['slug' => 'inv-1'], 'invoiceNumber' => 'F-1', 'dueDate' => '2026-05-10', 'lifecycleState' => 'issued'],
				['@self' => ['slug' => 'inv-2'], 'dueDate' => '2026-05-11', 'lifecycleState' => 'overdue'],
				['@self' => ['slug' => 'inv-3'], 'invoiceNumber' => 'F-3', 'dueDate' => '2026-05-12', 'lifecycleState' => 'disputed'],
				['@self' => ['slug' => 'inv-4'], 'invoiceNumber' => 'F-4', 'dueDate' => '2026-05-13'],
				['@self' => ['slug' => 'inv-5'], 'invoiceNumber' => 'F-5', 'dueDate' => '2026-05-14', 'lifecycleState' => 'paid'],
				['@self' => ['slug' => 'inv-6'], 'invoiceNumber' => 'F-6', 'lifecycleState' => 'issued'],
				['@self' => ['slug' => 'inv-7'], 'invoiceNumber' => 'F-7', 'dueDate' => '31-31-2026', 'lifecycleState' => 'issued'],
				['invoiceNumber' => 'F-8', 'dueDate' => '2026-05-15', 'lifecycleState' => 'issued'],
			],
		];

	}//end rowsBySchema()
}//end class

/**
 * Object store double: answers findAll() with the rows of the active schema.
 */
final class GoldenDeadlineObjects {

	/**
	 * Every schema requested, in order.
	 *
	 * @var array<int, string>
	 */
	public array $requested = [];

	/**
	 * The active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows by schema.
	 * @param bool $fails Whether every read throws.
	 */
	public function __construct(
		private readonly array $rowsBySchema,
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
		$this->schema      = $schema;
		$this->requested[] = $schema;
		return $this;
	}//end setSchema()

	/**
	 * The rows of the active schema.
	 *
	 * @param array<string, mixed> $params Query parameters (unused).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $params = []): array {
		if ($this->fails === true) {
			throw new RuntimeException('read failed for ' . $this->schema);
		}

		return ($this->rowsBySchema[$this->schema] ?? []);
	}//end findAll()
}//end class

/**
 * Calendar manager double that records the principal it was asked for.
 */
final class GoldenCalendarManager {

	/**
	 * Every principal asked for.
	 *
	 * @var array<int, string>
	 */
	public array $principals = [];

	/**
	 * Constructor.
	 *
	 * @param mixed $calendars What getCalendarsForPrincipal() returns.
	 */
	public function __construct(private readonly mixed $calendars) {
	}//end __construct()

	/**
	 * The calendars of a principal.
	 *
	 * @param string $principalUri The principal URI.
	 *
	 * @return mixed
	 */
	public function getCalendarsForPrincipal(string $principalUri): mixed {
		$this->principals[] = $principalUri;
		return $this->calendars;
	}//end getCalendarsForPrincipal()
}//end class

/**
 * Read-only calendar double: it has a URI but no createFromString().
 */
class GoldenCalendar {

	/**
	 * Constructor.
	 *
	 * @param string $uri The calendar URI.
	 * @param string $label The label recorded in the golden file.
	 */
	public function __construct(
		private readonly string $uri,
		public readonly string $label,
	) {
	}//end __construct()

	/**
	 * The calendar URI.
	 *
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}//end getUri()
}//end class

/**
 * Writable calendar double with a URI.
 */
final class GoldenWritableCalendar extends GoldenCalendar {

	/**
	 * Accept an upsert.
	 *
	 * @param string $name The object name.
	 * @param string $data The payload.
	 *
	 * @return void
	 */
	public function createFromString(string $name, string $data): void {
	}//end createFromString()
}//end class

/**
 * Writable calendar double without getUri().
 */
final class GoldenUrilessCalendar {

	/**
	 * Constructor.
	 *
	 * @param string $label The label recorded in the golden file.
	 */
	public function __construct(public readonly string $label) {
	}//end __construct()

	/**
	 * Accept an upsert.
	 *
	 * @param string $name The object name.
	 * @param string $data The payload.
	 *
	 * @return void
	 */
	public function createFromString(string $name, string $data): void {
	}//end createFromString()
}//end class

/**
 * Logger that records every line as level: message.
 */
final class GoldenDeadlineLogger extends AbstractLogger {

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
