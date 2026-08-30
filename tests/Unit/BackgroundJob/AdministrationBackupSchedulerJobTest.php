<?php

/**
 * Unit tests for AdministrationBackupSchedulerJob.
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
 * Tests the per-administration backup scheduling rule (REQ-MA-007).
 *
 * Covers the pure isDue() / evaluateDueAdministrations() / buildBackupRunRecord()
 * helpers — no live OpenRegister is required.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationBackupSchedulerJobTest extends TestCase {

	/**
	 * The job under test.
	 *
	 * @var AdministrationBackupSchedulerJob
	 */
	private AdministrationBackupSchedulerJob $job;

	/**
	 * Build the job with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$time = $this->createMock(ITimeFactory::class);
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->job = new AdministrationBackupSchedulerJob(
			time: $time,
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * A daily administration with no prior backup is due immediately.
	 *
	 * @return void
	 */
	public function testDailyNeverBackedUpIsDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'dagelijks',
			'status' => 'actief',
		];

		self::assertTrue($this->job->isDue(administration: $administration, now: $now));

	}//end testDailyNeverBackedUpIsDue()

	/**
	 * A daily administration backed up less than 24h ago is NOT due.
	 *
	 * @return void
	 */
	public function testDailyWithinIntervalIsNotDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'dagelijks',
			'lastBackupCompletedAt' => '2026-06-08T01:00:00+00:00',
		];

		self::assertFalse($this->job->isDue(administration: $administration, now: $now));

	}//end testDailyWithinIntervalIsNotDue()

	/**
	 * A daily administration backed up more than 24h ago IS due.
	 *
	 * @return void
	 */
	public function testDailyBeyondIntervalIsDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'dagelijks',
			'lastBackupCompletedAt' => '2026-06-07T07:00:00+00:00',
		];

		self::assertTrue($this->job->isDue(administration: $administration, now: $now));

	}//end testDailyBeyondIntervalIsDue()

	/**
	 * A weekly administration backed up 6 days ago is NOT yet due.
	 *
	 * @return void
	 */
	public function testWeeklyWithinIntervalIsNotDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'wekelijks',
			'lastBackupCompletedAt' => '2026-06-02T08:00:00+00:00',
		];

		self::assertFalse($this->job->isDue(administration: $administration, now: $now));

	}//end testWeeklyWithinIntervalIsNotDue()

	/**
	 * A weekly administration backed up 8 days ago IS due.
	 *
	 * @return void
	 */
	public function testWeeklyBeyondIntervalIsDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'wekelijks',
			'lastBackupCompletedAt' => '2026-05-31T08:00:00+00:00',
		];

		self::assertTrue($this->job->isDue(administration: $administration, now: $now));

	}//end testWeeklyBeyondIntervalIsDue()

	/**
	 * An on-request administration is NOT due without a nextBackupAt.
	 *
	 * @return void
	 */
	public function testOnRequestWithoutNextIsNotDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'aanvragen',
		];

		self::assertFalse($this->job->isDue(administration: $administration, now: $now));

	}//end testOnRequestWithoutNextIsNotDue()

	/**
	 * An on-request administration with nextBackupAt in the past IS due.
	 *
	 * @return void
	 */
	public function testOnRequestPastNextIsDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'aanvragen',
			'nextBackupAt' => '2026-06-07T08:00:00+00:00',
		];

		self::assertTrue($this->job->isDue(administration: $administration, now: $now));

	}//end testOnRequestPastNextIsDue()

	/**
	 * An unknown schedule slug never fires automatically (defensive default).
	 *
	 * @return void
	 */
	public function testUnknownScheduleIsNotDue(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'jaarlijks',
		];

		self::assertFalse($this->job->isDue(administration: $administration, now: $now));

	}//end testUnknownScheduleIsNotDue()

	/**
	 * evaluateDueAdministrations() filters each administration independently — no
	 * cross-administratie leakage (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testEvaluateDueFiltersIndependently(): void {
		$now = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administrations = [
			[
				'id' => 'adm-werk-001',
				'backupSchedule' => 'dagelijks',
				'lastBackupCompletedAt' => '2026-06-08T01:00:00+00:00',
			],
			[
				'id' => 'adm-beheer-001',
				'backupSchedule' => 'dagelijks',
			],
			[
				'id' => 'adm-finance-001',
				'backupSchedule' => 'aanvragen',
				'nextBackupAt' => '2026-06-09T00:00:00+00:00',
			],
		];

		$due = $this->job->evaluateDueAdministrations(
			administrations: $administrations,
			now: $now
		);

		self::assertCount(1, $due);
		self::assertSame('adm-beheer-001', $due[0]['id']);

	}//end testEvaluateDueFiltersIndependently()

	/**
	 * Archived administrations are flagged as snapshot-only (REQ-MA-007).
	 *
	 * @return void
	 */
	public function testArchivedIsSnapshotOnly(): void {
		$archived = [
			'id' => 'adm-werk-001',
			'status' => 'gearchiveerd',
		];
		$active = [
			'id' => 'adm-beheer-001',
			'status' => 'actief',
		];

		self::assertTrue($this->job->isReadOnlyAdministration(administration: $archived));
		self::assertFalse($this->job->isReadOnlyAdministration(administration: $active));

	}//end testArchivedIsSnapshotOnly()

	/**
	 * buildBackupRunRecord carries exactly one administrationId and the right metadata.
	 *
	 * @return void
	 */
	public function testBuildBackupRunRecord(): void {
		$started = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$completed = new DateTimeImmutable('2026-06-08T08:00:05+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'administrationCode' => 'WERK-001',
			'backupSchedule' => 'dagelijks',
			'status' => 'actief',
		];

		$record = $this->job->buildBackupRunRecord(
			administration: $administration,
			startedAt: $started,
			completedAt: $completed,
			status: 'success',
			sizeBytes: 4096
		);

		self::assertSame('adm-werk-001', $record['administrationId']);
		self::assertSame('WERK-001', $record['administrationCode']);
		self::assertSame('dagelijks', $record['schedule']);
		self::assertSame('success', $record['status']);
		self::assertFalse($record['snapshotOnly']);
		self::assertSame(4096, $record['sizeBytes']);
		self::assertNotNull($record['nextBackupAt']);

	}//end testBuildBackupRunRecord()

	/**
	 * On-request schedules produce a null next-backup timestamp.
	 *
	 * @return void
	 */
	public function testOnRequestHasNoNextTimestamp(): void {
		$completed = new DateTimeImmutable('2026-06-08T08:00:00+00:00');
		$administration = [
			'id' => 'adm-werk-001',
			'backupSchedule' => 'aanvragen',
		];

		self::assertNull(
			$this->job->nextBackupTimestamp(
				administration: $administration,
				completedAt: $completed
			)
		);

	}//end testOnRequestHasNoNextTimestamp()
}//end class
