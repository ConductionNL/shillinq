<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use OCA\Shillinq\BackgroundJob\PaymentPlanMonitorJob;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The daily job runs the monitor and is registered (REQ-RPPL-004).
 */
final class PaymentPlanMonitorJobTest extends TestCase {
	/**
	 * The job's run() is the service's monitor().
	 *
	 * @return void
	 */
	public function testTheJobRunsTheMonitor(): void {
		$plans = $this->createMock(PaymentPlanService::class);
		$plans->expects($this->once())->method('monitor')->willReturn(['due' => 0, 'missed' => 0, 'broken' => 0, 'completed' => 0]);
		$job = new PaymentPlanMonitorJob($this->createMock(ITimeFactory::class), $plans, $this->createMock(LoggerInterface::class));

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testTheJobRunsTheMonitor()

	/**
	 * appinfo/info.xml registers the job, so Nextcloud schedules it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>' . PaymentPlanMonitorJob::class . '</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
