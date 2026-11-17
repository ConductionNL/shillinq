<?php

/**
 * Unit tests for SmsReminderJob.
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
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\SmsReminderJob;
use OCA\Shillinq\Service\Sms\SmsReminderRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job runs the reminders at the factory's time and is listed in info.xml.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.3
 */
#[CoversClass(SmsReminderJob::class)]
final class SmsReminderJobTest extends TestCase {

	/**
	 * Invoke the protected run().
	 *
	 * @param SmsReminderJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(SmsReminderJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * A time factory at a fixed moment.
	 *
	 * @return ITimeFactory
	 */
	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1796288400);
		return $time;
	}//end time()

	/**
	 * The run hands the factory's time to the runner and logs the report.
	 *
	 * @return void
	 */
	public function testTheRunUsesTheFactoryTimeAndLogsTheReport(): void {
		$report = ['channels' => 1, 'notConnected' => 0, 'sent' => 2, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
		$runner = $this->createMock(SmsReminderRunner::class);
		$runner->expects(self::once())->method('run')
			->with(self::callback(static fn (DateTimeImmutable $now): bool => $now->getTimestamp() === 1796288400))
			->willReturn($report);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('Shillinq: SmsReminderJob complete', $report);

		self::runJob(job: new SmsReminderJob(time: $this->time(), runner: $runner, logger: $logger));
	}//end testTheRunUsesTheFactoryTimeAndLogsTheReport()

	/**
	 * A failing run is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingRunIsLogged(): void {
		$runner = $this->createMock(SmsReminderRunner::class);
		$runner->method('run')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('Shillinq: SmsReminderJob failed', ['exception' => 'down']);
		$logger->expects(self::never())->method('info');

		self::runJob(job: new SmsReminderJob(time: $this->time(), runner: $runner, logger: $logger));
	}//end testAFailingRunIsLogged()

	/**
	 * The job runs every fifteen minutes and info.xml registers it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredWithItsInterval(): void {
		$job = new SmsReminderJob(time: $this->time(), runner: $this->createMock(SmsReminderRunner::class), logger: $this->createMock(LoggerInterface::class));

		self::assertSame(900, $job->getInterval());
		$info = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		self::assertStringContainsString('<job>OCA\Shillinq\BackgroundJob\SmsReminderJob</job>', $info);
	}//end testTheJobIsRegisteredWithItsInterval()
}//end class
