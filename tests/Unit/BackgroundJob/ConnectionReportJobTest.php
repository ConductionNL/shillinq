<?php

/**
 * ConnectionReportJob unit tests.
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
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\ConnectionReportJob;
use OCA\Shillinq\Service\ConnectionReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The daily job hands its run to the reporter and nothing else.
 *
 * @covers \OCA\Shillinq\BackgroundJob\ConnectionReportJob
 */
final class ConnectionReportJobTest extends TestCase {

	/**
	 * One run sends one round of reports.
	 *
	 * @return void
	 */
	public function testARunReportsEveryCalledFamilyOnce(): void {
		$reporter = $this->createMock(originalClassName: ConnectionReportService::class);
		$reporter->expects($this->once())->method('reportAdapterBindings')->willReturn(['mollie' => 'simulated']);

		$job = new ConnectionReportJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			reporter: $reporter,
		);

		$run = new ReflectionMethod(ConnectionReportJob::class, 'run');
		$run->invoke($job, null);
	}//end testARunReportsEveryCalledFamilyOnce()

	/**
	 * The job runs once a day, not on every cron tick.
	 *
	 * Each report is one object write in integriq. A binding changes only with
	 * a deploy, so an hourly job would write 24 rows a day to learn nothing.
	 *
	 * @return void
	 */
	public function testTheJobRunsOnceADay(): void {
		$job = new ConnectionReportJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			reporter: $this->createMock(originalClassName: ConnectionReportService::class),
		);

		$interval = new ReflectionProperty($job, 'interval');

		$this->assertSame(expected: 86400, actual: $interval->getValue($job));
	}//end testTheJobRunsOnceADay()
}//end class
