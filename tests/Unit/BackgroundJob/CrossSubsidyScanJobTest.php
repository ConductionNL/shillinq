<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\CrossSubsidyScanJob;
use OCA\Shillinq\Kernel\Contracts\CrossSubsidyScanInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-WMO-014: the daily job runs the cross-subsidy scan for today inside
 * OpenRegister's system context, logs the counts and contains a failure, and
 * is registered with the job runner.
 *
 * @covers \OCA\Shillinq\BackgroundJob\CrossSubsidyScanJob
 */
final class CrossSubsidyScanJobTest extends TestCase {

	/**
	 * 2026-11-14T23:30:00Z.
	 */
	private const NOW = 1794699000;

	/**
	 * A job over the scan, with an ObjectService whose system context is recorded.
	 *
	 * @param CrossSubsidyScanInterface $scan   The scan.
	 * @param LoggerInterface           $logger The log.
	 * @param object                    $system Records runAsSystem calls.
	 *
	 * @return CrossSubsidyScanJob
	 */
	private function job(CrossSubsidyScanInterface $scan, LoggerInterface $logger, object $system): CrossSubsidyScanJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($system);
		return new CrossSubsidyScanJob($time, $scan, $container, $logger);

	}//end job()

	/**
	 * An ObjectService stand-in that counts its system-context runs.
	 *
	 * @return object
	 */
	private static function system(): object {
		return new class {
			public int $runs = 0;

			public function runAsSystem(callable $work): void {
				$this->runs++;
				$work();
			}
		};

	}//end system()

	/**
	 * The job runs daily, not in parallel, for today's UTC date, as the system.
	 */
	public function testTheJobScansForTodayAsTheSystem(): void {
		$scan = $this->createMock(CrossSubsidyScanInterface::class);
		$scan->expects($this->once())->method('run')->with('2026-11-14')->willReturn(['activities' => 3, 'alerts' => 1, 'alreadyOpen' => 2]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('CrossSubsidyScanJob: scan done', ['activities' => 3, 'alerts' => 1, 'alreadyOpen' => 2]);
		$logger->expects($this->never())->method('error');
		$system = self::system();

		$job = $this->job($scan, $logger, $system);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(1, $system->runs);
		$this->assertSame(86400, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
		$this->assertFalse($job->getAllowParallelRuns());

	}//end testTheJobScansForTodayAsTheSystem()

	/**
	 * A failing scan is one error.
	 */
	public function testAFailingScanIsOneError(): void {
		$scan = $this->createMock(CrossSubsidyScanInterface::class);
		$scan->method('run')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('CrossSubsidyScanJob: scan failed', ['exception' => 'store down']);
		$logger->expects($this->never())->method('info');

		$job = $this->job($scan, $logger, self::system());
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

	}//end testAFailingScanIsOneError()

	/**
	 * The job is registered in appinfo/info.xml, so Nextcloud schedules it.
	 */
	public function testTheJobIsRegistered(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$jobs = array_map('strval', iterator_to_array($info->{'background-jobs'}->job, false));

		$this->assertContains(CrossSubsidyScanJob::class, $jobs);

	}//end testTheJobIsRegistered()
}//end class
