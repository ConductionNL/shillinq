<?php

/**
 * Unit tests for BookingNotificationController.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BookingNotificationController;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingNotificationController.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-16
 */
class BookingNotificationControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock BookingNotificationService.
     *
     * @var BookingNotificationService&MockObject
     */
    private BookingNotificationService&MockObject $notificationService;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The controller under test.
     *
     * @var BookingNotificationController
     */
    private BookingNotificationController $controller;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(originalClassName: IRequest::class);
        $this->notificationService = $this->createMock(originalClassName: BookingNotificationService::class);
        $this->settingsService     = $this->createMock(originalClassName: SettingsService::class);
        $this->container           = $this->createMock(originalClassName: ContainerInterface::class);
        $this->groupManager        = $this->createMock(originalClassName: IGroupManager::class);
        $this->userSession         = $this->createMock(originalClassName: IUserSession::class);
        $this->logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

        $this->controller = new BookingNotificationController(
            request: $this->request,
            settingsService: $this->settingsService,
            container: $this->container,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * GetBookingTriggers throws OCSForbiddenException when user is unauthenticated.
     *
     * @return void
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    public function testGetBookingTriggersForbiddenWhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(exception: \OCP\AppFramework\OCS\OCSForbiddenException::class);

        $this->controller->getBookingTriggers(id: 'booking-123');
    }//end testGetBookingTriggersForbiddenWhenNotAuthenticated()

    /**
     * GetBookingTriggers returns triggers when admin user requests.
     *
     * @return void
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    public function testGetBookingTriggersReturnsTriggersForAdmin(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        // phpcs:disable
        $objectService = new class {
            public function setRegister(string $register): static { return $this; }
            public function setSchema(string $schema): static { return $this; }
            public function findAll(array $config=[]): array
            {
                return [['id' => 'trig-1', 'eventType' => 'created', 'active' => true]];
            }
        };
        // phpcs:enable
        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->container->method('get')->willReturn($objectService);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $response = $this->controller->getBookingTriggers(id: 'booking-123');

        static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $data = $response->getData();
        static::assertArrayHasKey(key: 'triggers', array: $data);
        static::assertCount(expectedCount: 1, haystack: $data['triggers']);
    }//end testGetBookingTriggersReturnsTriggersForAdmin()

    /**
     * UpdateBookingTriggers returns 400 when triggers key is missing.
     *
     * @return void
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    public function testUpdateBookingTriggersMissingTriggersKey(): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->request->method('getParams')->willReturn([]);

        $response = $this->controller->updateBookingTriggers(id: 'booking-123');

        static::assertSame(expected: 400, actual: $response->getStatus());
    }//end testUpdateBookingTriggersMissingTriggersKey()

    /**
     * GetNotificationMonitor returns counts for admin.
     *
     * @return void
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    public function testGetNotificationMonitorReturnsCountsForAdmin(): void
    {
        // phpcs:disable
        $objectService = new class {
            public function setRegister(string $register): static { return $this; }
            public function setSchema(string $schema): static { return $this; }
            public function findAll(array $config=[]): array
            {
                return [];
            }
        };
        // phpcs:enable
        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->container->method('get')->willReturn($objectService);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $response = $this->controller->getNotificationMonitor();

        static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $data = $response->getData();
        static::assertArrayHasKey(key: 'counts', array: $data);
        static::assertArrayHasKey(key: 'today', array: $data['counts']);
    }//end testGetNotificationMonitorReturnsCountsForAdmin()

    /**
     * DisableAllTriggers returns disabled count.
     *
     * @return void
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
     */
    public function testDisableAllTriggersReturnsDisabledCount(): void
    {
        // phpcs:disable
        $objectService = new class {
            public function setRegister(string $register): static { return $this; }
            public function setSchema(string $schema): static { return $this; }
            public function findAll(array $config=[]): array
            {
                return [['id' => 'trig-1', 'active' => true], ['id' => 'trig-2', 'active' => true]];
            }

            public function saveObject(array $object, string $register, string $schema): array
            {
                return $object;
            }
        };
        // phpcs:enable
        // phpcs:disable CustomSn.Functions.NamedParameters
        $this->container->method('get')->willReturn($objectService);
        // phpcs:enable CustomSn.Functions.NamedParameters

        $this->logger->expects(static::once())->method('warning');

        $response = $this->controller->disableAllTriggers();

        static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
        $data = $response->getData();
        static::assertSame(expected: 2, actual: $data['disabled']);
    }//end testDisableAllTriggersReturnsDisabledCount()
}//end class
