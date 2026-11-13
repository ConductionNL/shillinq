<?php

/**
 * ReorderProposalJob unit tests.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\ReorderProposalJob;
use OCA\Shillinq\Service\ReorderProposalService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The daily job hands the run moment to the reorder pass, logs its report,
 * and never lets a failure escape to the job runner.
 *
 * @covers \OCA\Shillinq\BackgroundJob\ReorderProposalJob
 */
final class ReorderProposalJobTest extends TestCase {

	/**
	 * A run passes the clock's moment and logs the pass's report.
	 *
	 * @return void
	 */
	public function testARunProposesAtTheClocksMomentAndLogsTheReport(): void {
		$now  = new DateTimeImmutable('2026-11-13T06:00:00+00:00');
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn($now);

		$report    = ['rules' => 3, 'proposed' => 1, 'resolved' => 0, 'unchanged' => 2, 'skipped' => 0, 'failed' => 0];
		$proposals = $this->createMock(originalClassName: ReorderProposalService::class);
		$proposals->expects($this->once())->method('proposeAll')->with($now)->willReturn($report);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('ReorderProposalJob: daily reorder pass done', $report);
		$logger->expects($this->never())->method('error');

		$job = new ReorderProposalJob(time: $time, proposals: $proposals, logger: $logger);
		(new ReflectionMethod(ReorderProposalJob::class, 'run'))->invoke($job, null);
	}//end testARunProposesAtTheClocksMomentAndLogsTheReport()

	/**
	 * A failing pass is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingPassIsLogged(): void {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-13T06:00:00+00:00'));

		$proposals = $this->createMock(originalClassName: ReorderProposalService::class);
		$proposals->method('proposeAll')->willThrowException(new RuntimeException('store down'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ReorderProposalJob: daily reorder pass failed', ['exception' => 'store down']);

		$job = new ReorderProposalJob(time: $time, proposals: $proposals, logger: $logger);
		(new ReflectionMethod(ReorderProposalJob::class, 'run'))->invoke($job, null);
	}//end testAFailingPassIsLogged()

	/**
	 * The job runs once a day, one run at a time.
	 *
	 * @return void
	 */
	public function testTheJobRunsOnceADayAlone(): void {
		$job = new ReorderProposalJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			proposals: $this->createMock(originalClassName: ReorderProposalService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		self::assertSame(86400, (new ReflectionProperty($job, 'interval'))->getValue($job));
		self::assertFalse($job->getAllowParallelRuns());
	}//end testTheJobRunsOnceADayAlone()

	/**
	 * appinfo/info.xml registers the job, so Nextcloud schedules it.
	 *
	 * @return void
	 */
	public function testInfoXmlListsTheJob(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');

		self::assertStringContainsString('<job>OCA\Shillinq\BackgroundJob\ReorderProposalJob</job>', $info);
	}//end testInfoXmlListsTheJob()
}//end class
