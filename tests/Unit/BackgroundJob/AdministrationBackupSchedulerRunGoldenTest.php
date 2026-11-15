<?php

/**
 * Golden tests for AdministrationBackupSchedulerJob::run().
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\AdministrationBackupSchedulerJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins what one scheduler run reads, writes and logs, and the isDue branches
 * on unparseable timestamps, before the run is split into named steps.
 * Timestamps are compared to each other, never to a pinned clock.
 */
final class AdministrationBackupSchedulerRunGoldenTest extends TestCase {
	/**
	 * Recorded calls, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * Saved records.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Build the job over an Administration store.
	 *
	 * @param array<int, mixed>|null $pages Administration pages in order ('throw' fails a page); null = no OpenRegister.
	 * @param array<int, string>     $fail  Administration ids whose save throws.
	 *
	 * @return AdministrationBackupSchedulerJob
	 */
	private function job(?array $pages, array $fail = []): AdministrationBackupSchedulerJob {
		$test = $this;
		$store = new class($test, (array)$pages, $fail) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param AdministrationBackupSchedulerRunGoldenTest $test  The test.
			 * @param array<int, mixed>                          $pages Pages.
			 * @param array<int, string>                         $fail  Failing ids.
			 */
			public function __construct(
				private $test,
				private array $pages,
				private array $fail,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				$this->test->calls[] = 'register:' . $r;
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $query The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $query): array {
				$this->test->calls[] = 'findAll:' . $this->schema . ':' . json_encode($query);
				$page = array_shift($this->pages);
				if ($page === 'throw') {
					throw new \RuntimeException('read failed');
				}

				return ($page ?? []);
			}//end findAll()

			/**
			 * @param array<string,mixed> $record The record.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $record): array {
				$this->test->calls[] = 'save:' . $this->schema . ':' . $record['administrationId'];
				if (in_array($record['administrationId'], $this->fail, true) === true) {
					throw new \RuntimeException('write failed');
				}

				$this->test->saved[] = $record;
				return $record;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		if ($pages === null) {
			$container->method('get')->willThrowException(new \RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->willReturn($store);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ':' . $message . json_encode($context);
				}
			);
		}

		return new AdministrationBackupSchedulerJob(
			time: $this->createMock(ITimeFactory::class),
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);
	}//end job()

	/**
	 * Run the job once.
	 *
	 * @param AdministrationBackupSchedulerJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(AdministrationBackupSchedulerJob $job): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * An entity exposing getObject().
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return object
	 */
	private static function entity(array $data): object {
		return new class($data) {
			/**
			 * @param array<string,mixed> $data The data.
			 */
			public function __construct(
				private array $data,
			) {
			}//end __construct()

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()
		};
	}//end entity()

	/**
	 * Without OpenRegister the run logs and stops.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegister(): void {
		$this->runJob(job: $this->job(pages: null));

		$this->assertSame(
			[
				'info:Shillinq: AdministrationBackupSchedulerJob started[]',
				'warning:Shillinq AdministrationBackupSchedulerJob: OpenRegister not available, skipping.{"exception":"no OpenRegister"}',
			],
			$this->calls
		);
	}//end testWithoutOpenRegister()

	/**
	 * A full page leads to the next; arrays and getObject() entities count, other rows do not;
	 * due administrations are saved with their status and a failing save is logged.
	 *
	 * @return void
	 */
	public function testRunPagesRecordsAndLogs(): void {
		$first = array_fill(0, 98, ['id' => 'x', 'backupSchedule' => 'aanvragen']);
		$first[] = ['id' => 'a1', 'administrationCode' => 'A1', 'backupSchedule' => 'dagelijks'];
		$first[] = self::entity(data: ['id' => 'a2', 'backupSchedule' => 'wekelijks', 'status' => 'gearchiveerd']);
		$second = ['junk', self::entity(data: ['id' => 'a3', 'backupSchedule' => 'dagelijks']), (object)['id' => 'no']];

		$this->runJob(job: $this->job(pages: [$first, $second], fail: ['a3']));

		$this->assertSame(
			[
				'info:Shillinq: AdministrationBackupSchedulerJob started[]',
				'register:shillinq',
				'findAll:Administration:{"limit":100,"offset":0}',
				'register:shillinq',
				'findAll:Administration:{"limit":100,"offset":100}',
				'register:shillinq',
				'save:AdministrationBackupRun:a1',
				'register:shillinq',
				'save:AdministrationBackupRun:a2',
				'register:shillinq',
				'save:AdministrationBackupRun:a3',
				'error:Shillinq AdministrationBackupSchedulerJob: failed to persist backup run{"administrationId":"a3","exception":"write failed"}',
				'info:Shillinq AdministrationBackupSchedulerJob: 101 administrations evaluated, 3 due, 2 persisted[]',
			],
			$this->calls
		);

		$this->assertSame(['a1', 'a2'], array_column($this->saved, 'administrationId'));
		$this->assertSame(['success', 'snapshot-only'], array_column($this->saved, 'status'));
		$this->assertSame([false, true], array_column($this->saved, 'snapshotOnly'));
		$this->assertSame('A1', $this->saved[0]['administrationCode']);
		$this->assertNull($this->saved[0]['sizeBytes']);
		$this->assertSame($this->saved[0]['startedAt'], $this->saved[0]['completedAt']);
		$completed = new DateTimeImmutable($this->saved[0]['completedAt']);
		$this->assertSame($completed->modify('+86400 seconds')->format(DATE_ATOM), $this->saved[0]['nextBackupAt']);
		$this->assertSame($completed->modify('+604800 seconds')->format(DATE_ATOM), $this->saved[1]['nextBackupAt']);
	}//end testRunPagesRecordsAndLogs()

	/**
	 * A failing page read is logged and ends the paging with what was read so far.
	 *
	 * @return void
	 */
	public function testFailingPageIsLogged(): void {
		$this->runJob(job: $this->job(pages: ['throw']));

		$this->assertSame(
			[
				'info:Shillinq: AdministrationBackupSchedulerJob started[]',
				'register:shillinq',
				'findAll:Administration:{"limit":100,"offset":0}',
				'error:Shillinq AdministrationBackupSchedulerJob: failed to fetch administrations{"offset":0,"exception":"read failed"}',
				'info:Shillinq AdministrationBackupSchedulerJob: 0 administrations evaluated, 0 due, 0 persisted[]',
			],
			$this->calls
		);
	}//end testFailingPageIsLogged()

	/**
	 * Unparseable timestamps: an on-request one is not due, a last-completed one is due.
	 *
	 * @return void
	 */
	public function testUnparseableTimestamps(): void {
		$job = $this->job(pages: []);
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');

		$this->assertFalse($job->isDue(administration: ['backupSchedule' => 'aanvragen', 'nextBackupAt' => 'not a date'], now: $now));
		$this->assertTrue($job->isDue(administration: ['backupSchedule' => 'dagelijks', 'lastBackupCompletedAt' => 'not a date'], now: $now));
		$this->assertTrue($job->isDue(administration: ['backupSchedule' => 'aanvragen', 'nextBackupAt' => '2026-06-08T08:00:00+00:00'], now: $now));
		$this->assertTrue($job->isDue(administration: ['backupSchedule' => 'dagelijks', 'lastBackupCompletedAt' => '2026-06-07T08:00:00+00:00'], now: $now));
		$this->assertFalse($job->isDue(administration: ['backupSchedule' => 'dagelijks', 'lastBackupCompletedAt' => '2026-06-07T08:00:01+00:00'], now: $now));
	}//end testUnparseableTimestamps()
}//end class
