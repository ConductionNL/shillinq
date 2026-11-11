<?php

/**
 * Characterisation tests for the cashflow horizon jobs.
 *
 * CalibrationBatchJob and CrisisModeRefreshJob both walk the active cashflow
 * horizons. These tests pin, for both, the queries in order, the objects
 * written, the field reads from array and entity horizons, and every log line.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
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

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\CalibrationBatchJob;
use OCA\Shillinq\BackgroundJob\CrisisModeRefreshJob;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the cashflow horizon jobs.
 */
final class CashflowHorizonJobsCharacterisationTest extends TestCase {

	/**
	 * Log lines as level|message.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * The fake object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new class {
			/**
			 * Calls in order.
			 *
			 * @var array<int,string>
			 */
			public array $calls = [];

			/**
			 * Objects saved, as schema and object.
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>}>
			 */
			public array $saved = [];

			/**
			 * findAll() answers per schema, queued.
			 *
			 * @var array<string,array<int,array<int,mixed>>>
			 */
			public array $answers = [];

			/**
			 * Current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Scope to a register.
			 *
			 * @param string $r Register.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				$this->calls[] = 'register:' . $r;
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $s Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				$this->calls[] = 'schema:' . $s;
				$this->schema = $s;
				return $this;
			}

			/**
			 * List objects.
			 *
			 * @param array<string,mixed> $opts Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $opts): array {
				$this->calls[] = 'findAll:' . $this->schema . ':' . json_encode($opts);
				$queue = ($this->answers[$this->schema] ?? []);
				$answer = array_shift($queue);
				$this->answers[$this->schema] = $queue;
				return ($answer ?? []);
			}

			/**
			 * Save an object.
			 *
			 * @param array<string,mixed> $object Object.
			 *
			 * @return void
			 */
			public function saveObject(array $object): void {
				$this->calls[] = 'save:' . $this->schema;
				$this->saved[] = [$this->schema, $object];
			}
		};

	}//end setUp()

	/**
	 * The shared collaborators.
	 *
	 * @param bool $available Whether OpenRegister is available.
	 *
	 * @return array{0:ITimeFactory,1:SettingsService,2:ContainerInterface,3:LoggerInterface}
	 */
	private function collaborators(bool $available): array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterSlug')->willReturn('books');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objects);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = $level . '|' . $message;
				}
			);
		}

		return [$this->createMock(ITimeFactory::class), $settings, $container, $logger];

	}//end collaborators()

	/**
	 * Run a job's protected run().
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private static function runJob(object $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

	}//end runJob()

	/**
	 * A horizon entity read through getters.
	 *
	 * @param string|null $horizonId The horizon id.
	 * @param string|null $administrationId The administration.
	 *
	 * @return object
	 */
	private static function entity(?string $horizonId, ?string $administrationId): object {
		return new class($horizonId, $administrationId) {
			/**
			 * Constructor.
			 *
			 * @param string|null $horizonId Horizon.
			 * @param string|null $administrationId Administration.
			 */
			public function __construct(private ?string $horizonId, private ?string $administrationId) {
			}

			/**
			 * The horizon id.
			 *
			 * @return string|null
			 */
			public function getHorizonId(): ?string {
				return $this->horizonId;
			}

			/**
			 * The administration.
			 *
			 * @return string|null
			 */
			public function getAdministrationId(): ?string {
				return $this->administrationId;
			}
		};

	}//end entity()

	/**
	 * Without OpenRegister both jobs log and stop.
	 *
	 * @return void
	 */
	public function testSkipWithoutOpenRegister(): void {
		self::runJob(new CalibrationBatchJob(...$this->collaborators(false)));
		self::runJob(new CrisisModeRefreshJob(...$this->collaborators(false)));

		self::assertSame(['info|CalibrationBatchJob: OR unavailable, skipping.', 'info|CrisisModeRefreshJob: OR unavailable, skipping.'], $this->logs);
		self::assertSame([], $this->objects->calls);

	}//end testSkipWithoutOpenRegister()

	/**
	 * The calibration run over array and entity horizons.
	 *
	 * @return void
	 */
	public function testCalibrationRun(): void {
		$this->objects->answers['CashflowForecastHorizon'] = [[
			['horizonId' => 'h1', 'administrationId' => 'adm-1'],
			self::entity('h2', 'adm-2'),
			self::entity('h3', null),
			['administrationId' => 'adm-4'],
			['horizonId' => 'h5', 'administrationId' => 'adm-5'],
			new \stdClass(),
		]];
		$this->objects->answers['CashflowCalibrationReport'] = [[], [], [['reportId' => 'exists']]];

		self::runJob(new CalibrationBatchJob(...$this->collaborators(true)));

		$period = $this->objects->saved[0][1]['calibrationPeriod'];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $period);
		$lookup = static fn (string $id): string => 'findAll:CashflowCalibrationReport:' . json_encode(['filters' => ['reportId' => 'calib-' . $id . '-' . $period], 'limit' => 1]);
		self::assertSame(
			[
				'register:books', 'schema:CashflowForecastHorizon', 'findAll:CashflowForecastHorizon:{"filters":{"lifecycleState":"active"}}',
				'register:books', 'schema:CashflowCalibrationReport', $lookup('h1'),
				'register:books', 'schema:CashflowCalibrationReport', 'save:CashflowCalibrationReport',
				'register:books', 'schema:CashflowCalibrationReport', $lookup('h2'),
				'register:books', 'schema:CashflowCalibrationReport', 'save:CashflowCalibrationReport',
				'register:books', 'schema:CashflowCalibrationReport', $lookup('h5'),
			],
			$this->objects->calls
		);
		$first = $this->objects->saved[0][1];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $first['generatedAt']);
		self::assertSame($first['generatedAt'], $this->objects->saved[1][1]['generatedAt']);
		unset($first['generatedAt']);
		self::assertSame(
			[
				'reportId' => 'calib-h1-' . $period,
				'horizonId' => 'h1',
				'calibrationPeriod' => $period,
				'ar_mape' => 0.0,
				'ap_mape' => 0.0,
				'recurring_mape' => 0.0,
				'tax_mape' => 0.0,
				'administrationId' => 'adm-1',
			],
			$first
		);
		self::assertSame(['info|CalibrationBatchJob: created 2 calibration report(s) for period ' . $period . '.'], $this->logs);

	}//end testCalibrationRun()

	/**
	 * The crisis refresh over horizons with and without a negative week.
	 *
	 * @return void
	 */
	public function testCrisisRun(): void {
		$this->objects->answers['CashflowForecastHorizon'] = [[
			['horizonId' => 'h1'],
			self::entity('h2', null),
			['lifecycleState' => 'active'],
			['horizonId' => 'h4'],
		]];
		$week = static fn (mixed $balance): object => new class($balance) {
			/**
			 * Constructor.
			 *
			 * @param mixed $balance Closing balance.
			 */
			public function __construct(private mixed $balance) {
			}

			/**
			 * The closing balance.
			 *
			 * @return mixed
			 */
			public function getClosingBalance(): mixed {
				return $this->balance;
			}
		};
		$this->objects->answers['CashflowWeek'] = [
			[['closingBalance' => 10], ['closingBalance' => null], ['closingBalance' => '-0.01'], ['closingBalance' => -5]],
			[$week(0), $week(null), new \stdClass()],
			[$week('-3')],
		];

		self::runJob(new CrisisModeRefreshJob(...$this->collaborators(true)));

		$weeks = static fn (string $id): string => 'findAll:CashflowWeek:' . json_encode(['filters' => ['horizonId' => $id], 'limit' => 4, 'order' => ['weekNumber' => 'ASC']]);
		self::assertSame(
			[
				'register:books', 'schema:CashflowForecastHorizon', 'findAll:CashflowForecastHorizon:{"filters":{"lifecycleState":"active"}}',
				'register:books', 'schema:CashflowWeek', $weeks('h1'),
				'register:books', 'schema:CashflowForecastHorizon', 'save:CashflowForecastHorizon',
				'register:books', 'schema:CashflowWeek', $weeks('h2'),
				'register:books', 'schema:CashflowWeek', $weeks('h4'),
				'register:books', 'schema:CashflowForecastHorizon', 'save:CashflowForecastHorizon',
			],
			$this->objects->calls
		);
		self::assertSame(['horizonId', 'rolledOn'], array_keys($this->objects->saved[0][1]));
		self::assertSame(['h1', 'h4'], [$this->objects->saved[0][1]['horizonId'], $this->objects->saved[1][1]['horizonId']]);
		self::assertSame(['info|CrisisModeRefreshJob: refreshed 2 horizon(s).'], $this->logs);

	}//end testCrisisRun()

	/**
	 * A failure anywhere in the run is logged with the job's name.
	 *
	 * @return void
	 */
	public function testFailureIsLogged(): void {
		$this->objects->answers['CashflowForecastHorizon'] = [[['horizonId' => 'h1', 'administrationId' => 'a']], [['horizonId' => 'h1']]];
		$this->objects->answers['CashflowCalibrationReport'] = [[]];
		$this->objects->answers['CashflowWeek'] = [[['closingBalance' => -1]]];
		$broken = new class($this->objects) {
			/**
			 * Constructor.
			 *
			 * @param object $inner The fake.
			 */
			public function __construct(private object $inner) {
			}

			/**
			 * Forward a call, failing every save.
			 *
			 * @param string $name Method.
			 * @param array<int,mixed> $args Arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				if ($name === 'saveObject') {
					throw new \RuntimeException('disk full');
				}

				$result = $this->inner->$name(...$args);
				if ($result === $this->inner) {
					return $this;
				}

				return $result;
			}
		};
		$this->objects = $broken;

		self::runJob(new CalibrationBatchJob(...$this->collaborators(true)));
		self::runJob(new CrisisModeRefreshJob(...$this->collaborators(true)));

		self::assertSame(['error|CalibrationBatchJob failed: disk full', 'error|CrisisModeRefreshJob failed: disk full'], $this->logs);

	}//end testFailureIsLogged()
}//end class
