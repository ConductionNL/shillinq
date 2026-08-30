<?php

/**
 * Unit tests for ViesOutageRetryJob.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\ViesOutageRetryJob;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\ViesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the daily VIES revalidation job: pending outages are re-queried, and an
 * outage older than 14 days escalates to the bookkeeper (REQ-ICP-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ViesOutageRetryJobTest extends TestCase {
	/**
	 * Run the protected run() method via a thin reflection helper.
	 *
	 * @param ViesOutageRetryJob $job The job.
	 * @param array<string,mixed> $argument The job argument.
	 *
	 * @return void
	 */
	private function invokeRun(ViesOutageRetryJob $job, array $argument): void {
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, $argument);

	}//end invokeRun()

	/**
	 * A pending outage younger than 14 days triggers a fresh VIES re-validation.
	 *
	 * @return void
	 */
	public function testRunRevalidatesPendingOutage(): void {
		$filing = $this->createMock(IcpFilingService::class);
		$filing->method('pendingOutages')->willReturn(
			[['vatId' => 'BE0123456789', 'viesValidationId' => 'v1', 'ageDays' => 3, 'escalate' => false]]
		);

		$vies = $this->createMock(ViesService::class);
		$vies->expects($this->once())
			->method('validate')
			->with('adm-1', 'BE0123456789')
			->willReturn(['outage' => false, 'valid' => true] + $this->emptyOutcome());

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->expects($this->never())->method('notify');

		$job = new ViesOutageRetryJob(
			time: $this->createMock(ITimeFactory::class),
			filingService: $filing,
			viesService: $vies,
			notifications: $notifications,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invokeRun($job, ['administrationId' => 'adm-1']);

	}//end testRunRevalidatesPendingOutage()

	/**
	 * A pending outage older than 14 days escalates rather than re-validating.
	 *
	 * @return void
	 */
	public function testRunEscalatesStaleOutage(): void {
		$filing = $this->createMock(IcpFilingService::class);
		$filing->method('pendingOutages')->willReturn(
			[['vatId' => 'BE0999999999', 'viesValidationId' => 'v9', 'ageDays' => 20, 'escalate' => true]]
		);

		$vies = $this->createMock(ViesService::class);
		$vies->expects($this->never())->method('validate');

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->expects($this->once())->method('notify')->with($notification);

		$job = new ViesOutageRetryJob(
			time: $this->createMock(ITimeFactory::class),
			filingService: $filing,
			viesService: $vies,
			notifications: $notifications,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invokeRun($job, ['administrationId' => 'adm-1']);

	}//end testRunEscalatesStaleOutage()

	/**
	 * A missing administrationId argument is a no-op (defensive).
	 *
	 * @return void
	 */
	public function testRunNoOpWithoutAdministration(): void {
		$filing = $this->createMock(IcpFilingService::class);
		$filing->expects($this->never())->method('pendingOutages');

		$job = new ViesOutageRetryJob(
			time: $this->createMock(ITimeFactory::class),
			filingService: $filing,
			viesService: $this->createMock(ViesService::class),
			notifications: $this->createMock(INotificationManager::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invokeRun($job, []);

	}//end testRunNoOpWithoutAdministration()

	/**
	 * Build an empty validation-outcome tail for stub returns.
	 *
	 * @return array<string,mixed>
	 */
	private function emptyOutcome(): array {
		return [
			'vatId' => '',
			'requestId' => '',
			'validationTimestamp' => '',
			'validUntil' => '',
			'name' => '',
			'address' => '',
			'saved' => true,
			'reusedPrior' => false,
		];

	}//end emptyOutcome()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
