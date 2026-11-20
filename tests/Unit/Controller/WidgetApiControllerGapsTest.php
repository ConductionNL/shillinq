<?php

/**
 * Unit tests for the WidgetApiController branches the main test leaves open.
 *
 * Covers the catalogue-unavailable and lookup-failure paths of services(),
 * price publication, the slots() guard and required-parameter refusals, the
 * ETag 304 answer, the default rate limit and the row normalisation of
 * entity-shaped catalogue rows.
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
use OCA\Shillinq\Kernel\Contracts\WidgetAuthServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
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
 * Gap tests for WidgetApiController.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-15
 */
class WidgetApiControllerGapsTest extends TestCase {

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
	 * Register location stub.
	 *
	 * @var RegisterLocation&MockObject
	 */
	private RegisterLocation&MockObject $registerLocation;

	/**
	 * Container stub.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Widget service stub.
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
	 * Request headers served to the controller.
	 *
	 * @var array<string,string>
	 */
	private array $headers = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request          = $this->createMock(IRequest::class);
		$this->auth             = $this->createMock(WidgetAuthService::class);
		$this->slots            = $this->createMock(SlotService::class);
		$this->registerLocation = $this->createMock(RegisterLocation::class);
		$this->container        = $this->createMock(ContainerInterface::class);
		$this->widgetService    = $this->createMock(WidgetService::class);
		$this->logger           = $this->createMock(LoggerInterface::class);

		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name): string => ($this->headers[$name] ?? '')
		);

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
			registerLocation: $this->registerLocation,
			container: $this->container,
			logger: $this->logger,
		);

	}//end makeController()

	/**
	 * Authorise the caller with a key of the given rate limit.
	 *
	 * @param mixed $rateLimit The key's configured rate limit.
	 *
	 * @return void
	 */
	private function authorise(mixed $rateLimit = 100): void {
		$this->headers['Authorization'] = 'Bearer bk_live_valid';
		$this->auth->method('validateApiKey')->willReturn(
			[
				'valid' => true,
				'key'   => ['rateLimit' => $rateLimit, 'administrationId' => 'adm-salon'],
			]
		);
		$this->auth->method('consumeRateLimit')->willReturn(['allowed' => true, 'remaining' => 99]);

	}//end authorise()

	/**
	 * A fluent catalogue stub serving the given findAll() rows.
	 *
	 * @param array<int,mixed> $rows The rows.
	 *
	 * @return object
	 */
	private function catalogue(array $rows): object {
		// phpcs:disable
		return new class($rows) {
			public array $queries = [];
			public function __construct(private array $rows) {
			}
			public function setRegister(string $r): static {
				$this->queries[] = ['register' => $r];
				return $this;
			}
			public function setSchema(string $s): static {
				$this->queries[] = ['schema' => $s];
				return $this;
			}
			public function findAll(array $c = []): array {
				$this->queries[] = $c;
				return $this->rows;
			}
		};
		// phpcs:enable

	}//end catalogue()

	/**
	 * With OpenRegister unavailable, services() answers 500 without touching
	 * the container.
	 *
	 * @return void
	 */
	public function testServicesReturns500WhenOpenRegisterIsUnavailable(): void {
		$this->authorise();
		$this->registerLocation->method('isOpenRegisterAvailable')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$response = $this->makeController()->services();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(
			['error' => 'server-error', 'message' => 'Service catalogue unavailable.'],
			$response->getData()
		);

	}//end testServicesReturns500WhenOpenRegisterIsUnavailable()

	/**
	 * A failing catalogue lookup is logged and answered with a partner-safe
	 * 500 that carries no exception text.
	 *
	 * @return void
	 */
	public function testServicesReturns500AndLogsWhenLookupFails(): void {
		$this->authorise();
		$this->registerLocation->method('isOpenRegisterAvailable')->willReturn(true);
		$this->container->method('get')->willThrowException(new \RuntimeException('db gone'));
		$this->logger->expects(self::once())->method('error')->with(
			'Shillinq widget: services lookup failed',
			['exception' => 'db gone']
		);

		$response = $this->makeController()->services();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Service catalogue unavailable.', $response->getData()['message']);
		self::assertStringNotContainsString('db gone', (string)json_encode($response->getData()));

	}//end testServicesReturns500AndLogsWhenLookupFails()

	/**
	 * A service with priceVisible publishes basePrice, falling back to price;
	 * the catalogue query is scoped to active services of the key's
	 * administration in the configured register.
	 *
	 * @return void
	 */
	public function testServicesPublishesVisiblePriceAndScopesTheQuery(): void {
		$this->authorise();
		$this->registerLocation->method('isOpenRegisterAvailable')->willReturn(true);
		$this->registerLocation->method('getRegisterSlug')->willReturn('bookings');
		$catalogue = $this->catalogue(
			[
				[
					'serviceId' => 'svc-base', 'name' => 'Cut', 'duration' => '30', 'isPublic' => true,
					'administrationId' => 'adm-salon', 'priceVisible' => true, 'basePrice' => 25, 'price' => 99,
				],
				[
					'serviceId' => 'svc-price', 'name' => 'Dye', 'isPublic' => true, 'currency' => 'USD',
					'administrationId' => 'adm-salon', 'priceVisible' => true, 'price' => 40,
				],
				[
					'serviceId' => 'svc-none', 'isPublic' => true,
					'administrationId' => 'adm-salon', 'priceVisible' => true,
				],
			]
		);
		$this->container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($catalogue);

		$services = $this->makeController()->services()->getData()['services'];

		self::assertSame(
			[
				'serviceId' => 'svc-base', 'name' => 'Cut', 'duration' => 30, 'currency' => 'EUR',
				'description' => '', 'price' => 25,
			],
			$services[0]
		);
		self::assertSame(40, $services[1]['price']);
		self::assertSame('USD', $services[1]['currency']);
		self::assertArrayHasKey('price', $services[2]);
		self::assertNull($services[2]['price']);
		self::assertSame(
			[
				['register' => 'bookings'],
				['schema' => 'Service'],
				['filters' => ['status' => 'active', 'administrationId' => 'adm-salon'], 'limit' => 200],
			],
			$catalogue->queries
		);

	}//end testServicesPublishesVisiblePriceAndScopesTheQuery()

	/**
	 * Entity-shaped catalogue rows are normalised: jsonSerialize() arrays are
	 * used as-is, other objects are cast, and scalars yield no row.
	 *
	 * @return void
	 */
	public function testServicesNormalisesEntityShapedRows(): void {
		$this->authorise();
		$this->registerLocation->method('isOpenRegisterAvailable')->willReturn(true);
		$this->registerLocation->method('getRegisterSlug')->willReturn('shillinq');

		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return ['serviceId' => 'svc-entity', 'isPublic' => true, 'administrationId' => 'adm-salon'];
			}
		};
		$scalarSerialisable = new class implements \JsonSerializable {
			public string $serviceId = 'svc-cast';
			public bool $isPublic = true;
			public string $administrationId = 'adm-salon';
			public function jsonSerialize(): mixed {
				return 'not-an-array';
			}
		};
		$plain = (object)['serviceId' => 'svc-plain', 'isPublic' => true, 'administrationId' => 'adm-salon'];

		$this->container->method('get')->willReturn(
			$this->catalogue([$serialisable, $scalarSerialisable, $plain, 'scalar-row', 42])
		);

		$ids = array_column($this->makeController()->services()->getData()['services'], 'serviceId');

		self::assertSame(['svc-entity', 'svc-cast', 'svc-plain'], $ids);

	}//end testServicesNormalisesEntityShapedRows()

	/**
	 * slots() without a bearer token is refused before any slot lookup.
	 *
	 * @return void
	 */
	public function testSlotsRefusesAnUnauthenticatedCaller(): void {
		$this->slots->expects(self::never())->method('getAvailableSlots');

		$response = $this->makeController()->slots(serviceId: 'svc-1', resourceId: 'res-1', date: '2026-05-22');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('unauthorised', $response->getData()['error']);

	}//end testSlotsRefusesAnUnauthenticatedCaller()

	/**
	 * slots() refuses a blank (whitespace-only) required parameter with 400.
	 *
	 * @return void
	 */
	public function testSlotsRequiresServiceResourceAndDate(): void {
		$this->authorise();
		$this->widgetService->expects(self::never())->method('isPubliclyBookable');

		$response = $this->makeController()->slots(serviceId: 'svc-1', resourceId: '   ', date: '2026-05-22');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			['error' => 'bad-request', 'message' => 'serviceId, resourceId and date are required.'],
			$response->getData()
		);

	}//end testSlotsRequiresServiceResourceAndDate()

	/**
	 * A matching If-None-Match answers 304 with an empty body and the ETag;
	 * the slot lookup receives the trimmed parameters.
	 *
	 * @return void
	 */
	public function testSlotsAnswersNotModifiedForAMatchingETag(): void {
		$this->authorise();
		$this->headers['If-None-Match'] = 'W/"v1"';
		$this->widgetService->method('isPubliclyBookable')->willReturn(true);
		$this->slots->expects(self::once())->method('getAvailableSlots')
			->with(serviceId: 'svc-1', resourceId: 'res-1', date: '2026-05-22')
			->willReturn(['slots' => [['start' => '10:00']], 'etag' => 'W/"v1"', 'cached' => true]);

		$response = $this->makeController()->slots(serviceId: ' svc-1 ', resourceId: 'res-1 ', date: ' 2026-05-22');

		self::assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
		self::assertSame([], $response->getData());
		self::assertSame('W/"v1"', $response->getHeaders()['ETag']);

	}//end testSlotsAnswersNotModifiedForAMatchingETag()

	/**
	 * A key with a non-positive rate limit is metered at the default limit.
	 *
	 * @return void
	 */
	public function testANonPositiveKeyRateLimitFallsBackToTheDefault(): void {
		$this->headers['Authorization'] = 'Bearer bk_live_valid';
		$this->auth->method('validateApiKey')->willReturn(
			['valid' => true, 'key' => ['rateLimit' => 0, 'administrationId' => 'adm-salon']]
		);
		$this->auth->expects(self::once())->method('consumeRateLimit')
			->with(businessId: 'salon-001', limit: WidgetAuthServiceInterface::DEFAULT_RATE_LIMIT)
			->willReturn(['allowed' => true, 'remaining' => 1]);
		$this->registerLocation->method('isOpenRegisterAvailable')->willReturn(false);

		$response = $this->makeController()->services();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testANonPositiveKeyRateLimitFallsBackToTheDefault()
}//end class
