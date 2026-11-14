<?php

/**
 * Unit tests for BookingNotificationController's failure and refusal paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\BookingNotificationController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A store that fails yields a logged 500 that does not leak the cause; a
 * malformed trigger is refused with 400; a booking that cannot be found or
 * looked up denies the non-admin caller.
 *
 * @covers \OCA\Shillinq\Controller\BookingNotificationController
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BookingNotificationControllerGapsTest extends TestCase {

	/**
	 * Request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * User session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Administration membership seam double.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

	/**
	 * OpenRegister object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up the doubles and the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request               = $this->createMock(IRequest::class);
		$this->groupManager          = $this->createMock(IGroupManager::class);
		$this->userSession           = $this->createMock(IUserSession::class);
		$this->logger                = $this->createMock(LoggerInterface::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->objectService         = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return BookingNotificationController
	 */
	private function controller(): BookingNotificationController {
		return new BookingNotificationController(
			request: $this->request,
			settingsService: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
			objectService: $this->objectService,
		);
	}//end controller()

	/**
	 * Sign in a user, admin or not.
	 *
	 * @param bool $admin Whether the user is an admin.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($admin === true ? 'admin' : 'alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
	}//end signIn()

	/**
	 * Expect one error log with the given message and a cause of 'db down'.
	 *
	 * @param string $message The logged message.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $message): void {
		$this->logger->expects(self::once())
			->method('error')
			->with($message, ['exception' => 'db down']);
	}//end expectErrorLogged()

	/**
	 * A failing store on read answers 500 with a generic message.
	 *
	 * @return void
	 */
	public function testGetBookingTriggersAnswers500WhenTheStoreFails(): void {
		$this->signIn(admin: true);
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged(message: 'Shillinq: getBookingTriggers failed');

		$response = $this->controller()->getBookingTriggers(id: 'booking-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Failed to load triggers.'], $response->getData());
	}//end testGetBookingTriggersAnswers500WhenTheStoreFails()

	/**
	 * A trigger that is not an object is refused with 400 and nothing is saved.
	 *
	 * @return void
	 */
	public function testUpdateBookingTriggersRefusesANonObjectTrigger(): void {
		$this->signIn(admin: true);
		$this->request->method('getParams')->willReturn(['triggers' => ['not-an-object']]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->updateBookingTriggers(id: 'booking-1');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'Each trigger must be an object.'], $response->getData());
	}//end testUpdateBookingTriggersRefusesANonObjectTrigger()

	/**
	 * A failing save answers 500 with a generic message.
	 *
	 * @return void
	 */
	public function testUpdateBookingTriggersAnswers500WhenTheSaveFails(): void {
		$this->signIn(admin: true);
		$this->request->method('getParams')->willReturn(['triggers' => [['active' => false]]]);
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(['active' => false, 'bookingId' => 'booking-1'], [], 'shillinq', 'BookingNotificationTrigger')
			->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged(message: 'Shillinq: updateBookingTriggers failed');

		$response = $this->controller()->updateBookingTriggers(id: 'booking-1');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Failed to update triggers.'], $response->getData());
	}//end testUpdateBookingTriggersAnswers500WhenTheSaveFails()

	/**
	 * A failing store answers the monitor with 500.
	 *
	 * @return void
	 */
	public function testGetNotificationMonitorAnswers500WhenTheStoreFails(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged(message: 'Shillinq: getNotificationMonitor failed');

		$response = $this->controller()->getNotificationMonitor();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Failed to load monitor data.'], $response->getData());
	}//end testGetNotificationMonitorAnswers500WhenTheStoreFails()

	/**
	 * A failing save during the global off-switch answers 500.
	 *
	 * @return void
	 */
	public function testDisableAllTriggersAnswers500WhenTheSaveFails(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 't-1', 'active' => true]]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged(message: 'Shillinq: disableAllTriggers failed');
		$this->logger->expects(self::never())->method('warning');

		$response = $this->controller()->disableAllTriggers();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Failed to disable triggers.'], $response->getData());
	}//end testDisableAllTriggersAnswers500WhenTheSaveFails()

	/**
	 * A booking that does not exist denies the non-admin without consulting
	 * the membership seam.
	 *
	 * @return void
	 */
	public function testNonAdminIsDeniedWhenTheBookingIsNotFound(): void {
		$this->signIn(admin: false);
		$this->objectService->expects(self::once())
			->method('find')
			->with('booking-404')
			->willReturn(null);
		$this->administrationContext->expects(self::never())->method('canAccess');
		$this->objectService->expects(self::never())->method('findAll');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Booking not found.');

		$this->controller()->getBookingTriggers(id: 'booking-404');
	}//end testNonAdminIsDeniedWhenTheBookingIsNotFound()

	/**
	 * A booking lookup that throws is logged as a warning and denies with a
	 * generic message.
	 *
	 * @return void
	 */
	public function testNonAdminIsDeniedWhenTheBookingLookupFails(): void {
		$this->signIn(admin: false);
		$this->objectService->method('find')->willThrowException(new RuntimeException('lookup broke'));
		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'Shillinq: booking authorization lookup failed',
				['bookingId' => 'booking-1', 'exception' => 'lookup broke']
			);
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Authorization failed.');

		$this->controller()->updateBookingTriggers(id: 'booking-1');
	}//end testNonAdminIsDeniedWhenTheBookingLookupFails()
}//end class
