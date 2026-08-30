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
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BookingNotificationService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
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
class BookingNotificationControllerTest extends TestCase {

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
	 * Mock AdministrationContextService — the per-object authorisation seam.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

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
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->notificationService = $this->createMock(originalClassName: BookingNotificationService::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->administrationContext = $this->createMock(
			originalClassName: AdministrationContextService::class
		);

		$this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

		$this->controller = $this->buildController(store: $this->buildEmptyObjectServiceStub());
	}//end setUp()

	/**
	 * Build the controller over a seeded in-memory store.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so a test's
	 * store has to be present when the controller is built — parking it on the
	 * container after the fact leaves the controller reading an empty world.
	 *
	 * @param object $store The duck-typed in-memory ObjectService double.
	 *
	 * @return BookingNotificationController
	 */
	private function buildController(object $store): BookingNotificationController {
		return new BookingNotificationController(
			request: $this->request,
			settingsService: $this->settingsService,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
			administrationContext: $this->administrationContext,
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end buildController()

	/**
	 * Build an empty in-memory ObjectService double.
	 *
	 * @return object
	 */
	private function buildEmptyObjectServiceStub(): object {
		return new class {
			/**
			 * Fluent register setter — returns self.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — returns self.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Answer an empty result set.
			 *
			 * @param array<string,mixed> $params Query parameters (unused).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return [];
			}//end findAll()
		};
	}//end buildEmptyObjectServiceStub()

	/**
	 * GetBookingTriggers throws OCSForbiddenException when user is unauthenticated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	public function testGetBookingTriggersForbiddenWhenNotAuthenticated(): void {
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
	public function testGetBookingTriggersReturnsTriggersForAdmin(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);

		// phpcs:disable
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [['id' => 'trig-1', 'eventType' => 'created', 'active' => true]];
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
		// phpcs:enable CustomSn.Functions.NamedParameters

		$response = $this->controller->getBookingTriggers(id: 'booking-123');

		static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$data = $response->getData();
		static::assertArrayHasKey(key: 'triggers', array: $data);
		static::assertCount(expectedCount: 1, haystack: $data['triggers']);
	}//end testGetBookingTriggersReturnsTriggersForAdmin()

	/**
	 * A NON-ADMIN who is a member of the booking's administration is allowed
	 * through — the #[NoAdminRequired] contract is real.
	 *
	 * This is the case that could never happen before: the guard called
	 * ObjectService::findObject(), which does not exist, so the Error was
	 * turned into a blanket 403 for every non-admin. It then compared
	 * `organizerUserId`, which is not a property of the Booking schema, so
	 * fixing only the method name would still have denied everyone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	public function testGetBookingTriggersAllowsNonAdminMemberOfTheAdministration(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);

		// phpcs:disable
		$objectService = new class {
			public function find(string|int $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): object {
				return new class {
					public function jsonSerialize(): array {
						return ['bookingId' => 'booking-123', 'administrationId' => 'adm-1'];
					}
				};
			}
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [['id' => 'trig-1', 'eventType' => 'created', 'active' => true]];
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
		$this->administrationContext->method('canAccess')->willReturn(true);
		// phpcs:enable CustomSn.Functions.NamedParameters

		$response = $this->controller->getBookingTriggers(id: 'booking-123');

		static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
		static::assertArrayHasKey(key: 'triggers', array: $response->getData());
	}//end testGetBookingTriggersAllowsNonAdminMemberOfTheAdministration()

	/**
	 * The same non-admin is DENIED when they are not a member of the booking's
	 * administration. Without this the test above would pass over a guard that
	 * simply lets everyone through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	public function testGetBookingTriggersDeniesNonAdminOutsideTheAdministration(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);

		// phpcs:disable
		$objectService = new class {
			public function find(string|int $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): object {
				return new class {
					public function jsonSerialize(): array {
						return ['bookingId' => 'booking-123', 'administrationId' => 'adm-other'];
					}
				};
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
		$this->administrationContext->method('canAccess')->willReturn(false);
		// phpcs:enable CustomSn.Functions.NamedParameters

		$this->expectException(exception: \OCP\AppFramework\OCS\OCSForbiddenException::class);

		$this->controller->getBookingTriggers(id: 'booking-123');
	}//end testGetBookingTriggersDeniesNonAdminOutsideTheAdministration()

	/**
	 * A booking whose administrationId is absent or empty must DENY, not skip
	 * the check — the defect fixed in #474, where `?? ''` plus a `!== ''`
	 * guard meant canAccess() was never called at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	public function testGetBookingTriggersDeniesWhenAdministrationIdIsEmpty(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);

		// phpcs:disable
		$objectService = new class {
			public function find(string|int $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): object {
				return new class {
					public function jsonSerialize(): array {
						return ['bookingId' => 'booking-123'];
					}
				};
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
		// An empty administrationId must still reach canAccess(), which
		// returns false for it — assert the guard consults the seam rather
		// than short-circuiting past it (the #474 defect).
		$this->administrationContext->expects(static::once())
			->method('canAccess')
			->with('')
			->willReturn(false);
		// phpcs:enable CustomSn.Functions.NamedParameters

		$this->expectException(exception: \OCP\AppFramework\OCS\OCSForbiddenException::class);

		$this->controller->getBookingTriggers(id: 'booking-123');
	}//end testGetBookingTriggersDeniesWhenAdministrationIdIsEmpty()

	/**
	 * UpdateBookingTriggers returns 400 when triggers key is missing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-11
	 */
	public function testUpdateBookingTriggersMissingTriggersKey(): void {
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
	public function testGetNotificationMonitorReturnsCountsForAdmin(): void {
		// phpcs:disable
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [];
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
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
	public function testDisableAllTriggersReturnsDisabledCount(): void {
		// phpcs:disable
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}
			public function setSchema(string $schema): static {
				return $this;
			}
			public function findAll(array $config = []): array {
				return [['id' => 'trig-1', 'active' => true], ['id' => 'trig-2', 'active' => true]];
			}

			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				return $object;
			}
		};
		// phpcs:enable
		// phpcs:disable CustomSn.Functions.NamedParameters
		$this->controller = $this->buildController(store: $objectService);
		// phpcs:enable CustomSn.Functions.NamedParameters

		$this->logger->expects(static::once())->method('warning');

		$response = $this->controller->disableAllTriggers();

		static::assertInstanceOf(expected: JSONResponse::class, actual: $response);
		$data = $response->getData();
		static::assertSame(expected: 2, actual: $data['disabled']);
	}//end testDisableAllTriggersReturnsDisabledCount()
}//end class
