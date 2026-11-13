<?php

/**
 * HorizonRollingJob run tests.
 *
 * The weekly run against an in-memory object service: which horizons it
 * rolls to this ISO Monday, which it skips, how it pages through the active
 * horizons and what it does when a fetch or a save fails.
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
 * @spec openspec/changes/archive/2026-06-14-zzp-cashflow-13wk/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\BackgroundJob\HorizonRollingJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The run rolls every active horizon not yet on this Monday, page by page.
 *
 * @covers \OCA\Shillinq\BackgroundJob\HorizonRollingJob
 * @uses   \OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem
 */
final class HorizonRollingJobRunTest extends TestCase {

	/**
	 * The logger, to read what the run reported.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Info messages the run logged.
	 *
	 * @var array<int, string>
	 */
	private array $infos = [];

	/**
	 * Error messages the run logged, with their context.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $errors = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('info')->willReturnCallback(function (string $message): void {
			$this->infos[] = $message;
		});
		$this->logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->errors[] = [$message, $context];
		});
	}//end setUp()

	/**
	 * This week's ISO Monday in UTC, as the job computes it.
	 *
	 * @return string
	 */
	private static function monday(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
	}//end monday()

	/**
	 * An in-memory object service serving pages of horizon entities and recording saves.
	 *
	 * @param array<int, array<int, mixed>|\Throwable> $pages     What each findAll call answers.
	 * @param array<string, \Throwable>               $saveFails Uuids whose save throws.
	 * @param bool                                    $system    Whether it offers runAsSystem.
	 *
	 * @return object
	 */
	private function store(array $pages, array $saveFails = [], bool $system = false): object {
		$store = new class($pages, $saveFails) {
			/** @var array<int, array<string, mixed>> */
			public array $queries = [];

			/** @var array<int, array{0: array<string, mixed>, 1: string, 2: string, 3: string}> */
			public array $saved = [];

			/** @var array<int, string> */
			public array $context = [];

			public int $systemRuns = 0;

			/**
			 * @param array<int, array<int, mixed>|\Throwable> $pages     Pages.
			 * @param array<string, \Throwable>               $saveFails Failing saves.
			 */
			public function __construct(private array $pages, private array $saveFails) {
			}

			public function setRegister(string $register): void {
				$this->context[] = 'register:' . $register;
			}

			public function setSchema(string $schema): void {
				$this->context[] = 'schema:' . $schema;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $query): array {
				$this->queries[] = $query;
				$page = (array_shift($this->pages) ?? []);
				if ($page instanceof \Throwable) {
					throw $page;
				}

				// OpenRegister answers entities; wrap each row as one.
				return array_map(
					static fn (mixed $row): mixed => is_array($row) === false ? $row : new class($row) {
						/**
						 * @param array<string, mixed> $row The row.
						 */
						public function __construct(private array $row) {
						}

						/**
						 * @return array<string, mixed>
						 */
						public function getObject(): array {
							return $this->row;
						}
					},
					$page
				);
			}

			/**
			 * @param array<string, mixed> $object The object.
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): void {
				if (isset($this->saveFails[$uuid]) === true) {
					throw $this->saveFails[$uuid];
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
			}
		};

		if ($system === false) {
			return $store;
		}

		return new class($store) {
			public function __construct(public object $inner) {
			}

			public function runAsSystem(callable $work): void {
				$this->inner->systemRuns++;
				$work();
			}

			/**
			 * @param array<int, mixed> $args The arguments.
			 */
			public function __call(string $name, array $args): mixed {
				return $this->inner->{$name}(...$args);
			}
		};
	}//end store()

	/**
	 * Run the job against the given object service.
	 *
	 * @param object $objectService The object service.
	 * @param string $register      The configured register slug.
	 *
	 * @return void
	 */
	private function runJob(object $objectService, string $register = 'books'): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$job = new HorizonRollingJob($time, $container, $appConfig, $this->logger);
		(new ReflectionMethod(HorizonRollingJob::class, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Horizons not yet on this Monday are rolled and saved under their id; rolled and id-less ones are left.
	 *
	 * @return void
	 */
	public function testItRollsOnlyHorizonsNotYetOnThisMonday(): void {
		$monday = self::monday();
		$store = $this->store([[
			['id' => 'h-1', 'horizonStart' => '2020-01-06', 'horizonEnd' => '2020-04-05', 'lifecycleState' => 'active', 'note' => 'kept'],
			['uuid' => 'h-2', 'horizonStart' => '2020-01-06', 'lifecycleState' => 'active'],
			['id' => 'h-3', 'horizonStart' => $monday],
			['horizonStart' => '2020-01-06'],
			['id' => '', 'horizonStart' => '2020-01-06'],
			'not a horizon',
		]]);

		$this->runJob($store);

		self::assertSame(['h-1', 'h-2'], array_column($store->saved, 3));
		[$first, $register, $schema] = $store->saved[0];
		self::assertSame(['books', 'CashflowForecastHorizon'], [$register, $schema]);
		self::assertSame($monday, $first['horizonStart']);
		self::assertSame((new DateTimeImmutable($monday))->modify('+90 days')->format('Y-m-d'), $first['horizonEnd']);
		self::assertSame(['active', 'kept'], [$first['lifecycleState'], $first['note']]);
		self::assertArrayHasKey('rolledOn', $first);
		self::assertSame(
			[
				'filters' => ['register' => 'books', 'schema' => 'CashflowForecastHorizon', 'lifecycleState' => 'active'],
				'limit' => 100,
				'offset' => 0,
			],
			$store->queries[0]
		);
		self::assertSame('Shillinq HorizonRollingJob: rolled 2 horizon(s), skipped 1, 0 error(s)', end($this->infos));
	}//end testItRollsOnlyHorizonsNotYetOnThisMonday()

	/**
	 * A full page asks for the next page at the next offset; a short page ends the fetch.
	 *
	 * @return void
	 */
	public function testItPagesThroughTheActiveHorizons(): void {
		$full = [];
		for ($i = 0; $i < 100; $i++) {
			$full[] = ['id' => 'h-' . $i, 'horizonStart' => '2020-01-06'];
		}

		$store = $this->store([$full, [['id' => 'h-last', 'horizonStart' => '2020-01-06']]]);

		$this->runJob($store);

		self::assertSame([0, 100], array_column($store->queries, 'offset'));
		self::assertCount(101, $store->saved);
		self::assertSame('Shillinq HorizonRollingJob: rolled 101 horizon(s), skipped 0, 0 error(s)', end($this->infos));
	}//end testItPagesThroughTheActiveHorizons()

	/**
	 * A failing save is logged with its uuid and counted; the other horizons still roll.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsCountedAndTheRestStillRoll(): void {
		$store = $this->store(
			[[['id' => 'h-1', 'horizonStart' => '2020-01-06'], ['id' => 'h-2', 'horizonStart' => '2020-01-06']]],
			['h-1' => new RuntimeException('locked')]
		);

		$this->runJob($store);

		self::assertSame(['h-2'], array_column($store->saved, 3));
		self::assertSame(
			[['Shillinq HorizonRollingJob: failed to roll horizon', ['uuid' => 'h-1', 'exception' => 'locked']]],
			$this->errors
		);
		self::assertSame('Shillinq HorizonRollingJob: rolled 1 horizon(s), skipped 0, 1 error(s)', end($this->infos));
	}//end testAFailingSaveIsCountedAndTheRestStillRoll()

	/**
	 * A failing fetch ends the fetch, keeps what was read and is logged with its offset.
	 *
	 * @return void
	 */
	public function testAFailingFetchKeepsWhatWasRead(): void {
		$full = [];
		for ($i = 0; $i < 100; $i++) {
			$full[] = ['id' => 'h-' . $i, 'horizonStart' => '2020-01-06'];
		}

		$store = $this->store([$full, new RuntimeException('timeout')]);

		$this->runJob($store);

		self::assertCount(100, $store->saved);
		self::assertSame(
			[['Shillinq HorizonRollingJob: failed to fetch horizons', ['offset' => 100, 'exception' => 'timeout']]],
			$this->errors
		);
	}//end testAFailingFetchKeepsWhatWasRead()

	/**
	 * Where the object service can elevate, the roll runs inside runAsSystem.
	 *
	 * @return void
	 */
	public function testTheRollRunsAsSystemWhenOffered(): void {
		$store = $this->store([[['id' => 'h-1', 'horizonStart' => '2020-01-06']]], [], true);

		$this->runJob($store);

		self::assertSame(1, $store->inner->systemRuns);
		self::assertSame(['h-1'], array_column($store->inner->saved, 3));
	}//end testTheRollRunsAsSystemWhenOffered()
}//end class
