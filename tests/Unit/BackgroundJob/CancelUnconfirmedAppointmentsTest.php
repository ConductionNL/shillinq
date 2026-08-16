<?php

/**
 * Unit tests for CancelUnconfirmedAppointments background job.
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
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\CancelUnconfirmedAppointments;
use OCA\Shillinq\Service\AppointmentConfirmationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Verifies the job delegates the cancellation sweep to the service.
 */
final class CancelUnconfirmedAppointmentsTest extends TestCase {
	/**
	 * The job delegates run() to AppointmentConfirmationService::cancelExpired().
	 *
	 * @return void
	 */
	public function testRunInvokesCancelExpired(): void {
		$service = $this->createMock(AppointmentConfirmationService::class);
		$service->expects($this->once())->method('cancelExpired')->willReturn(2);

		$job = new CancelUnconfirmedAppointments(
			$this->createMock(ITimeFactory::class),
			$service,
			$this->createMock(LoggerInterface::class),
		);

		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end testRunInvokesCancelExpired()

	/**
	 * A zero-cancellation run still completes cleanly.
	 *
	 * @return void
	 */
	public function testRunWithNoCancellationsIsSafe(): void {
		$service = $this->createMock(AppointmentConfirmationService::class);
		$service->method('cancelExpired')->willReturn(0);

		$job = new CancelUnconfirmedAppointments(
			$this->createMock(ITimeFactory::class),
			$service,
			$this->createMock(LoggerInterface::class),
		);

		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->addToAssertionCount(1);
	}//end testRunWithNoCancellationsIsSafe()
}//end class
