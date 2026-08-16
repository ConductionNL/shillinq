<?php

/**
 * Unit tests for WidgetApiController.
 *
 * Covers the auth guard happy path and the 401/429 negative paths.
 * Endpoint-level integration coverage (200 services list, 201 appointment,
 * 409 double-book, 304 ETag) is exercised in tests/integration via Newman
 * against the live OR-backed register per ADR-008.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WidgetApiController;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WidgetApiController.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-15
 */
class WidgetApiControllerTest extends TestCase {

	/**
	 * IRequest stub.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Auth service stub.
	 *
	 * @var WidgetAuthService&MockObject
	 */
	private WidgetAuthService&MockObject $auth;

	/**
	 * Slot service stub.
	 *
	 * @var SlotService&MockObject
	 */
	private SlotService&MockObject $slots;

	/**
	 * Settings stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Container stub.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The widget booking write path the controller delegates to.
	 *
	 * @var WidgetService&MockObject
	 */
	private WidgetService&MockObject $widgetService;

	/**
	 * Logger stub.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->auth = $this->createMock(WidgetAuthService::class);
		$this->slots = $this->createMock(SlotService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->widgetService = $this->createMock(WidgetService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return WidgetApiController
	 */
	private function makeController(): WidgetApiController {
		return new WidgetApiController(
			request: $this->request,
			auth: $this->auth,
			widgetService: $this->widgetService,
			slots: $this->slots,
			settings: $this->settings,
			container: $this->container,
			logger: $this->logger,
		);

	}//end makeController()

	/**
	 * Requests without an Authorization header are 401 Unauthorized.
	 *
	 * @return void
	 */
	public function testServicesReturnsUnauthorisedWithoutHeader(): void {
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getHeader')->willReturn('');

		$response = $this->makeController()->services();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testServicesReturnsUnauthorisedWithoutHeader()

	/**
	 * Requests with an invalid bearer token are 401 Unauthorized.
	 *
	 * @return void
	 */
	public function testServicesReturnsUnauthorisedWithInvalidBearer(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_wrong'],
				['If-None-Match', ''],
			]
		);
		$this->auth->method('validateApiKey')->willReturn(['valid' => false, 'error' => 'Invalid or missing API key']);

		$response = $this->makeController()->services();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testServicesReturnsUnauthorisedWithInvalidBearer()

	/**
	 * Authenticated requests that exceed the rate limit are 429.
	 *
	 * @return void
	 */
	public function testServicesReturns429WhenRateLimited(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);

		$this->auth->method('validateApiKey')->willReturn(
			[
				'valid' => true,
				'key' => ['rateLimit' => 100],
			]
		);
		$this->auth->method('consumeRateLimit')->willReturn(
			[
				'allowed' => false,
				'remaining' => 0,
				'retryAfter' => 60,
			]
		);

		$response = $this->makeController()->services();
		self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		self::assertSame('60', $response->getHeaders()['Retry-After']);

	}//end testServicesReturns429WhenRateLimited()

	/**
	 * The /slots endpoint validates date format before authenticating call paths.
	 *
	 * @return void
	 */
	public function testSlotsRejectsInvalidDateFormat(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);

		$this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
		$this->auth->method('consumeRateLimit')->willReturn(
			['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
		);

		$response = $this->makeController()->slots(
			serviceId: 'svc-001',
			resourceId: 'res-001',
			date: '22-05-2026'
		);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSlotsRejectsInvalidDateFormat()

	/**
	 * THE #491 GATE, THIRD DOOR — availability is refused for a service the
	 * business never published, and the slot engine is never reached.
	 *
	 * guard() only proves the caller holds the widget API key, and that key
	 * ships inside a PUBLIC booking widget — so every visitor has it.
	 * services() hides non-public services and appointments() refuses to book
	 * them; before this, availability answered for any serviceId of the tenant.
	 *
	 * Deleting the gate makes this test red.
	 *
	 * @return void
	 */
	public function testSlotsRefusesAServiceTheBusinessNeverPublished(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);

		$this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
		$this->auth->method('consumeRateLimit')->willReturn(
			['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
		);

		$this->widgetService->method('isPubliclyBookable')->willReturn(false);

		// The engine must not run at all: computing availability and then
		// discarding it would still leak through timing and cache population.
		$this->slots->expects(self::never())->method('getAvailableSlots');

		$response = $this->makeController()->slots(
			serviceId: 'svc-internal-001',
			resourceId: 'res-001',
			date: '2026-05-22'
		);

		// 404, not 403 — a 403 confirms the service exists, which is the very
		// fact being withheld.
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'service_not_found'], $response->getData());

	}//end testSlotsRefusesAServiceTheBusinessNeverPublished()

	/**
	 * THE POSITIVE CONTROL — a published service still returns its slots.
	 *
	 * Without this, a gate that refused everything would satisfy the test
	 * above while taking the whole booking widget offline.
	 *
	 * @return void
	 */
	public function testSlotsStillServesAPublishedService(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);

		$this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
		$this->auth->method('consumeRateLimit')->willReturn(
			['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
		);

		$this->widgetService->method('isPubliclyBookable')->willReturn(true);
		$this->slots->expects(self::once())
			->method('getAvailableSlots')
			->willReturn(['slots' => [], 'etag' => 'W/"abc"', 'cached' => false]);

		$response = $this->makeController()->slots(
			serviceId: 'svc-001',
			resourceId: 'res-001',
			date: '2026-05-22'
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testSlotsStillServesAPublishedService()

	/**
	 * POST /appointments rejects malformed ISO timestamps with 400.
	 *
	 * @return void
	 */
	public function testAppointmentsRejectsInvalidTimestamp(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);

		$this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100]]);
		$this->auth->method('consumeRateLimit')->willReturn(
			['allowed' => true, 'remaining' => 99, 'retryAfter' => 60]
		);

		$response = $this->makeController()->appointments(
			serviceId: 'svc-001',
			resourceId: 'res-001',
			startTime: '2026/05/22 09:00',
			endTime: '2026/05/22 09:30',
			customerName: 'Alice',
			email: 'alice@example.com',
		);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testAppointmentsRejectsInvalidTimestamp()

	/**
	 * Authenticate a widget caller so the isPublic tests reach the catalogue.
	 *
	 * @return void
	 */
	private function authoriseWidgetCaller(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap(
			[
				['Authorization', 'Bearer bk_live_valid'],
				['If-None-Match', ''],
			]
		);
		$this->auth->method('validateApiKey')->willReturn(
			[
				'valid' => true,
				'key' => ['rateLimit' => 100],
			]
		);
		$this->auth->method('consumeRateLimit')->willReturn(
			[
				'allowed' => true,
				'remaining' => 99,
			]
		);
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

	}//end authoriseWidgetCaller()

	/**
	 * Build an ObjectService stub returning a fixed Service catalogue.
	 *
	 * @param array<int,array<string,mixed>> $rows Service rows to return from findAll().
	 *
	 * @return object
	 */
	private function catalogueStub(array $rows): object {
		// phpcs:disable
		return new class($rows) {
			private array $rows;
			public function __construct(array $rows) {
				$this->rows = $rows;
			}
			public function setRegister(string $r): static {
				return $this;
			}
			public function setSchema(string $s): static {
				return $this;
			}
			public function findAll(array $c = []): array {
				$sid = (string)($c['filters']['serviceId'] ?? '');
				if ($sid === '') {
					return $this->rows;
				}
				return array_values(array_filter(
					$this->rows,
					static fn (array $r): bool => ((string)($r['serviceId'] ?? '')) === $sid
				));
			}
		};
		// phpcs:enable
	}//end catalogueStub()

	/**
	 * A service that is active but NOT flagged isPublic must never be listed
	 * by the public widget catalogue.
	 *
	 * @return void
	 */
	public function testServicesOmitsServicesThatAreNotPublic(): void {
		$this->authoriseWidgetCaller();
		$this->container->method('get')->willReturn(
			$this->catalogueStub(
				[
					[
						'serviceId' => 'svc-public',
						'name' => 'Haircut',
						'status' => 'active',
						'isPublic' => true,
					],
					[
						'serviceId' => 'svc-internal',
						'name' => 'Internal staff slot',
						'status' => 'active',
						'isPublic' => false,
					],
					[
						'serviceId' => 'svc-unflagged',
						'name' => 'Legacy service with no isPublic key',
						'status' => 'active',
					],
				]
			)
		);

		$ids = array_column($this->makeController()->services()->getData()['services'], 'serviceId');

		self::assertSame(['svc-public'], $ids, 'only isPublic services may be listed');

	}//end testServicesOmitsServicesThatAreNotPublic()

	/**
	 * priceVisible is a per-service choice; a service that hides its price
	 * must not have one published.
	 *
	 * @return void
	 */
	public function testServicesOmitsPriceWhenPriceVisibleIsFalse(): void {
		$this->authoriseWidgetCaller();
		$this->container->method('get')->willReturn(
			$this->catalogueStub(
				[
					[
						'serviceId' => 'svc-hidden-price',
						'name' => 'Consult',
						'status' => 'active',
						'isPublic' => true,
						'basePrice' => 150,
						'priceVisible' => false,
					],
				]
			)
		);

		$service = $this->makeController()->services()->getData()['services'][0];

		self::assertArrayNotHasKey('price', $service);

	}//end testServicesOmitsPriceWhenPriceVisibleIsFalse()

	/**
	 * A booking the write path refuses as not-bookable surfaces as HTTP 404
	 * with the `service-not-found` code, even though the caller holds a valid
	 * widget API key. Without this the widget key — which ships in the
	 * embedding page — was enough to book any service whose id could be
	 * guessed or read from another surface (PR #491).
	 *
	 * The isPublic / status enforcement ITSELF now lives in WidgetService,
	 * where it is pinned directly by
	 * WidgetServiceTest::testCreateAppointmentRejectsNonPublicService() and
	 * ::testCreateAppointmentRejectsInactiveService(). This test pins the
	 * other half — that the controller does not swallow that refusal or
	 * report it as a server error.
	 *
	 * @return void
	 */
	public function testAppointmentsRefusesAServiceThatIsNotBookable(): void {
		$this->authoriseWidgetCaller();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->method('createAppointment')
			->willReturn(['code' => 404, 'error' => 'service_not_found']);

		$response = $this->makeController()->appointments(
			serviceId: 'svc-internal',
			resourceId: 'res-001',
			startTime: '2026-05-21T10:00:00Z',
			endTime: '2026-05-21T10:30:00Z',
			customerName: 'Anna de Wit',
			email: 'anna@example.com',
		);

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('service-not-found', $response->getData()['error']);

	}//end testAppointmentsRefusesAServiceThatIsNotBookable()

	/**
	 * The controller hands the customer's contact details to the write path.
	 *
	 * REGRESSION PIN for the delegation itself. The controller used to build
	 * the Appointment inline and never passed name / email / phone anywhere;
	 * if a future edit drops them from this call, the fields stop being
	 * persisted again and nothing else would notice.
	 *
	 * @return void
	 */
	public function testAppointmentsForwardsCustomerContactDetailsToTheWritePath(): void {
		$this->authoriseWidgetCaller();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);

		$captured = null;
		$this->widgetService->method('createAppointment')
			->willReturnCallback(
				function (string $administrationId, array $payload) use (&$captured): array {
					$captured = $payload;
					return [
						'code' => 201,
						'appointmentId' => 'apt-1',
						'status' => 'pending_confirmation',
						'confirmationMessage' => 'ok',
					];
				}
			);

		$response = $this->makeController()->appointments(
			serviceId: 'svc-001',
			resourceId: 'res-001',
			startTime: '2026-05-21T10:00:00Z',
			endTime: '2026-05-21T10:30:00Z',
			customerName: 'Anna de Wit',
			email: 'anna@example.com',
			phone: '+31612345678',
		);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('Anna de Wit', $captured['customerName'] ?? null);
		self::assertSame('anna@example.com', $captured['customerEmail'] ?? null);
		self::assertSame('+31612345678', $captured['customerPhone'] ?? null);

	}//end testAppointmentsForwardsCustomerContactDetailsToTheWritePath()
}//end class
