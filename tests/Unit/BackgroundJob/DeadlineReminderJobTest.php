<?php

/**
 * Unit tests for DeadlineReminderJob.
 *
 * Covers REQ-CDC-007: the daily job delegates publication + reminder
 * dispatch to ComplianceDeadlineCalendarService and never lets a
 * failure escape into cron.
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
 * @spec openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\DeadlineReminderJob;
use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the daily deadline publication + reminder job (REQ-CDC-007).
 */
class DeadlineReminderJobTest extends TestCase {
	/**
	 * Invoke the protected run() method.
	 *
	 * @param DeadlineReminderJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(DeadlineReminderJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

	}//end runJob()

	/**
	 * The job runs both phases: bulk publication and reminder dispatch.
	 *
	 * @return void
	 */
	public function testRunPublishesAndDispatchesReminders(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$time = $this->createMock(ITimeFactory::class);
		$service = $this->createMock(ComplianceDeadlineCalendarService::class);
		$logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$service->expects(self::once())->method('publishAll')->willReturn(2);
		$service->expects(self::once())->method('dispatchDueReminders')->willReturn(3);

		$job = new DeadlineReminderJob(
			time: $time,
			calendarService: $service,
			logger: $logger,
		);

		$this->runJob(job: $job);

	}//end testRunPublishesAndDispatchesReminders()

	/**
	 * A throwing service never crashes cron — the job logs and both
	 * phases are still attempted independently.
	 *
	 * @return void
	 */
	public function testRunNeverThrowsAndStillDispatchesAfterPublishFailure(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$time = $this->createMock(ITimeFactory::class);
		$service = $this->createMock(ComplianceDeadlineCalendarService::class);
		$logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$service->expects(self::once())->method('publishAll')
			->willThrowException(new \RuntimeException('calendar exploded'));
		$service->expects(self::once())->method('dispatchDueReminders')
			->willThrowException(new \RuntimeException('notifications exploded'));

		$job = new DeadlineReminderJob(
			time: $time,
			calendarService: $service,
			logger: $logger,
		);

		$this->runJob(job: $job);
		// Reaching this point without an exception is the assertion.
		self::assertTrue(true);

	}//end testRunNeverThrowsAndStillDispatchesAfterPublishFailure()
}//end class
