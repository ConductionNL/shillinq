<?php

/**
 * Golden test for DeadlineReminderJob::run.
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

use OCA\Shillinq\BackgroundJob\DeadlineReminderJob;
use OCA\Shillinq\Service\ComplianceDeadlineCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the two steps of a run, each guarded on its own, and every log line in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DeadlineReminderJobGoldenTest extends TestCase {

	/**
	 * Recorded calls and log lines in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run the job.
	 *
	 * @param int|null $published Users published; null makes publishing throw.
	 * @param int|null $raised    Reminders raised; null makes dispatching throw.
	 *
	 * @return void
	 */
	private function runJob(int|null $published, int|null $raised): void {
		$calendar = $this->createMock(ComplianceDeadlineCalendarService::class);
		$calendar->method('publishAll')->willReturnCallback(
			function () use ($published): int {
				$this->calls[] = ['publishAll'];
				if ($published === null) {
					throw new RuntimeException('caldav down');
				}

				return $published;
			}
		);
		$calendar->method('dispatchDueReminders')->willReturnCallback(
			function () use ($raised): int {
				$this->calls[] = ['dispatchDueReminders'];
				if ($raised === null) {
					throw new RuntimeException('notify down');
				}

				return $raised;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$job = new DeadlineReminderJob($this->createMock(ITimeFactory::class), $calendar, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * Both steps run and log their count; a failing step logs one error and the other step still runs.
	 *
	 * @return void
	 */
	public function testRuns(): void {
		$this->runJob(published: 3, raised: 0);
		$this->runJob(published: null, raised: 2);
		$this->runJob(published: 1, raised: null);
		$expected = [
			['publishAll'],
			['debug', 'DeadlineReminderJob: published deadline VEVENTs for 3 user(s)', []],
			['dispatchDueReminders'],
			['debug', 'DeadlineReminderJob: raised 0 deadline reminder(s)', []],
			['publishAll'],
			['error', 'DeadlineReminderJob: deadline publication failed', ['exception' => 'caldav down']],
			['dispatchDueReminders'],
			['debug', 'DeadlineReminderJob: raised 2 deadline reminder(s)', []],
			['publishAll'],
			['debug', 'DeadlineReminderJob: published deadline VEVENTs for 1 user(s)', []],
			['dispatchDueReminders'],
			['error', 'DeadlineReminderJob: reminder dispatch failed', ['exception' => 'notify down']],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRuns()
}//end class
