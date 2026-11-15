<?php

/**
 * Unit tests for the background jobs that hand one pass to a service.
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

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\DepreciationRunJob;
use OCA\Shillinq\BackgroundJob\ScheduledReportJob;
use OCA\Shillinq\BackgroundJob\TaxDeadlineReminderJob;
use OCA\Shillinq\Reporting\Schedule\ScheduledReportRunner;
use OCA\Shillinq\Service\Asset\DepreciationRun;
use OCA\Shillinq\Service\TaxNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each job runs on its interval, hands the time factory's "now" to its
 * service, logs the counts, and contains a failing pass as one error.
 *
 * @covers \OCA\Shillinq\BackgroundJob\TaxDeadlineReminderJob
 * @covers \OCA\Shillinq\BackgroundJob\ScheduledReportJob
 * @covers \OCA\Shillinq\BackgroundJob\DepreciationRunJob
 */
class PassThroughJobsTest extends TestCase {

	/**
	 * The time factory's now, as a unix timestamp (2026-05-16T10:00:00Z).
	 */
	private const NOW = 1778925600;

	/**
	 * The time factory mock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory $time;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(self::NOW);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run a job's protected run().
	 *
	 * @param Job $job The job.
	 *
	 * @return void
	 */
	private function runJob(Job $job): void {
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The intervals, time sensitivity and parallelism each job declares.
	 *
	 * @return void
	 */
	public function testTheJobsScheduleThemselves(): void {
		$tax     = new TaxDeadlineReminderJob($this->time, $this->createMock(TaxNotificationService::class), $this->logger);
		$reports = new ScheduledReportJob($this->time, $this->createMock(ScheduledReportRunner::class), $this->logger);
		$depr    = new DepreciationRunJob($this->time, $this->createMock(DepreciationRun::class), $this->logger);

		$this->assertSame(86400, $tax->getInterval());
		$this->assertSame(3600, $reports->getInterval());
		$this->assertTrue($reports->isTimeSensitive());
		$this->assertFalse($reports->getAllowParallelRuns());
		$this->assertSame(86400, $depr->getInterval());
		$this->assertFalse($depr->isTimeSensitive());
		$this->assertFalse($depr->getAllowParallelRuns());
	}//end testTheJobsScheduleThemselves()

	/**
	 * The tax job dispatches the due reminders and logs how many.
	 *
	 * @return void
	 */
	public function testTheTaxJobDispatchesDueReminders(): void {
		$service = $this->createMock(TaxNotificationService::class);
		$service->expects($this->once())->method('dispatchDueReminders')->with()->willReturn(3);
		$this->logger->expects($this->once())->method('debug')->with('TaxDeadlineReminderJob: dispatched 3 deadline reminder(s)');
		$this->logger->expects($this->never())->method('error');

		$this->runJob(new TaxDeadlineReminderJob($this->time, $service, $this->logger));
	}//end testTheTaxJobDispatchesDueReminders()

	/**
	 * A failing reminder pass is one error.
	 *
	 * @return void
	 */
	public function testAFailingTaxPassIsOneError(): void {
		$service = $this->createMock(TaxNotificationService::class);
		$service->method('dispatchDueReminders')->willThrowException(new RuntimeException('mail down'));
		$this->logger->expects($this->once())->method('error')->with('TaxDeadlineReminderJob: failed to dispatch deadline reminders', ['exception' => 'mail down']);

		$this->runJob(new TaxDeadlineReminderJob($this->time, $service, $this->logger));
	}//end testAFailingTaxPassIsOneError()

	/**
	 * The report job runs the reports due at the time factory's now and logs the counts.
	 *
	 * @return void
	 */
	public function testTheReportJobRunsWhatIsDueNow(): void {
		$runner = $this->createMock(ScheduledReportRunner::class);
		$runner->expects($this->once())
			->method('runDue')
			->with($this->callback(static fn (DateTimeImmutable $now): bool => $now->getTimestamp() === self::NOW))
			->willReturn(['due' => 2, 'sent' => 1]);
		$this->logger->expects($this->once())->method('info')->with('ScheduledReportJob: pass done', ['due' => 2, 'sent' => 1]);

		$this->runJob(new ScheduledReportJob($this->time, $runner, $this->logger));
	}//end testTheReportJobRunsWhatIsDueNow()

	/**
	 * A failing report pass is one error.
	 *
	 * @return void
	 */
	public function testAFailingReportPassIsOneError(): void {
		$runner = $this->createMock(ScheduledReportRunner::class);
		$runner->method('runDue')->willThrowException(new RuntimeException('no store'));
		$this->logger->expects($this->once())->method('error')->with('ScheduledReportJob: pass failed', ['exception' => 'no store']);
		$this->logger->expects($this->never())->method('info');

		$this->runJob(new ScheduledReportJob($this->time, $runner, $this->logger));
	}//end testAFailingReportPassIsOneError()

	/**
	 * The depreciation job runs for today as the time factory says, and logs the counts.
	 *
	 * @return void
	 */
	public function testTheDepreciationJobRunsForToday(): void {
		$pass = $this->createMock(DepreciationRun::class);
		$pass->expects($this->once())
			->method('run')
			->with($this->callback(static fn (DateTimeImmutable $today): bool => $today->format('Y-m-d\TH:i:sP') === '2026-05-16T10:00:00+00:00'))
			->willReturn(['assets' => 4, 'posted' => 4]);
		$this->logger->expects($this->once())->method('info')->with('DepreciationRunJob: pass done', ['assets' => 4, 'posted' => 4]);

		$this->runJob(new DepreciationRunJob($this->time, $pass, $this->logger));
	}//end testTheDepreciationJobRunsForToday()

	/**
	 * A failing depreciation pass is one error.
	 *
	 * @return void
	 */
	public function testAFailingDepreciationPassIsOneError(): void {
		$pass = $this->createMock(DepreciationRun::class);
		$pass->method('run')->willThrowException(new RuntimeException('period closed'));
		$this->logger->expects($this->once())->method('error')->with('DepreciationRunJob: pass failed', ['exception' => 'period closed']);

		$this->runJob(new DepreciationRunJob($this->time, $pass, $this->logger));
	}//end testAFailingDepreciationPassIsOneError()
}//end class
