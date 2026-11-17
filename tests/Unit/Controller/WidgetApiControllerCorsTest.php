<?php

/**
 * Unit tests for the CORS answers of WidgetApiController (REQ-WSW-011).
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
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\WidgetApiController;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SlotService;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetCorsPolicy;
use OCA\Shillinq\Service\WidgetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An allowed origin gets the CORS headers; any other origin gets none.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
 */
#[CoversClass(WidgetApiController::class)]
#[UsesClass(WidgetCorsPolicy::class)]
final class WidgetApiControllerCorsTest extends TestCase {

	/**
	 * The widget keys.
	 *
	 * @var WidgetAuthService&MockObject
	 */
	private WidgetAuthService&MockObject $auth;

	/**
	 * The booking write path.
	 *
	 * @var WidgetService&MockObject
	 */
	private WidgetService&MockObject $widgetService;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(WidgetAuthService::class);
		$this->widgetService = $this->createMock(WidgetService::class);
		$this->auth->method('findActiveKeyByBusinessId')->willReturnCallback(
			static fn (string $businessId): ?array => match ($businessId) {
				'salon-001' => ['businessId' => 'salon-001', 'status' => 'active', 'allowedOrigins' => ['https://kapsalon.example', 'http://localhost:8080']],
				default => null,
			}
		);
	}//end setUp()

	/**
	 * The controller over a request with the given business, Origin and API key.
	 *
	 * @param string $businessId The businessId parameter.
	 * @param string $origin     The Origin header.
	 * @param string $bearer     The Authorization header.
	 * @param bool   $withPolicy Whether the CORS policy is wired.
	 *
	 * @return WidgetApiController
	 */
	private function controller(string $businessId, string $origin, string $bearer = '', bool $withPolicy = true): WidgetApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'businessId' => $businessId,
				default => $default,
			}
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match ($name) {
				'Origin' => $origin,
				'Authorization' => $bearer,
				default => '',
			}
		);

		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		$policy = null;
		if ($withPolicy === true) {
			$policy = new WidgetCorsPolicy(keys: $this->auth);
		}

		return new WidgetApiController(
			request: $request,
			auth: $this->auth,
			widgetService: $this->widgetService,
			slots: $this->createMock(SlotService::class),
			registerLocation: $settings,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			corsPolicy: $policy,
		);
	}//end controller()

	/**
	 * The preflight from an origin registered on the key answers 204 with the CORS headers.
	 *
	 * @return void
	 */
	public function testThePreflightOfAnAllowedOriginAnswersTheCorsHeaders(): void {
		$response = $this->controller(businessId: 'salon-001', origin: 'https://Kapsalon.example/')->preflight();

		self::assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$headers = $response->getHeaders();
		self::assertSame('https://kapsalon.example', $headers['Access-Control-Allow-Origin']);
		self::assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
		self::assertSame('Authorization, Content-Type', $headers['Access-Control-Allow-Headers']);
		self::assertSame('600', $headers['Access-Control-Max-Age']);
		self::assertSame('Origin', $headers['Vary']);
	}//end testThePreflightOfAnAllowedOriginAnswersTheCorsHeaders()

	/**
	 * A preflight from another origin, for an unknown business, or without an origin is refused without CORS headers.
	 *
	 * @return void
	 */
	public function testThePreflightOfAnyOtherOriginIsRefused(): void {
		$cases = [
			['salon-001', 'https://evil.example'],
			['salon-001', 'https://kapsalon.example.evil.example'],
			['salon-001', 'http://kapsalon.example'],
			['salon-001', ''],
			['salon-001', 'null'],
			['other-002', 'https://kapsalon.example'],
			['', 'https://kapsalon.example'],
		];
		foreach ($cases as [$businessId, $origin]) {
			$response = $this->controller(businessId: $businessId, origin: $origin)->preflight();

			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $businessId . ' ' . $origin);
			self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->getHeaders(), $businessId . ' ' . $origin);
		}
	}//end testThePreflightOfAnyOtherOriginIsRefused()

	/**
	 * Without the policy wired nothing is allowed.
	 *
	 * @return void
	 */
	public function testWithoutThePolicyNoOriginIsAllowed(): void {
		$response = $this->controller(businessId: 'salon-001', origin: 'https://kapsalon.example', withPolicy: false)->preflight();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testWithoutThePolicyNoOriginIsAllowed()

	/**
	 * A booking from the allowed website is created and carries the CORS headers.
	 *
	 * @return void
	 */
	public function testABookingFromTheAllowedWebsiteCarriesTheCorsHeaders(): void {
		$this->auth->method('validateApiKey')->willReturn(['valid' => true, 'key' => ['rateLimit' => 100, 'administrationId' => 'adm-salon']]);
		$this->auth->method('consumeRateLimit')->willReturn(['allowed' => true, 'remaining' => 99]);
		$this->widgetService->expects(self::once())->method('createAppointment')
			->willReturn(['code' => 201, 'appointmentId' => 'apt-1', 'status' => 'pending_confirmation', 'confirmationMessage' => 'See you']);

		$response = $this->controller(businessId: 'salon-001', origin: 'https://kapsalon.example', bearer: 'Bearer bk_live_valid')->appointments(
			serviceId: 'svc-001',
			resourceId: 'res-001',
			startTime: '2026-12-03T10:00:00Z',
			endTime: '2026-12-03T10:30:00Z',
			customerName: 'Anna de Wit',
			email: 'anna@example.com',
		);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(['appointmentId' => 'apt-1', 'status' => 'pending_confirmation', 'confirmationMessage' => 'See you'], $response->getData());
		self::assertSame('https://kapsalon.example', $response->getHeaders()['Access-Control-Allow-Origin']);
		self::assertSame('Origin', $response->getHeaders()['Vary']);
	}//end testABookingFromTheAllowedWebsiteCarriesTheCorsHeaders()

	/**
	 * A refusal still carries the headers for an allowed origin, so the widget can show it; another origin gets none.
	 *
	 * @return void
	 */
	public function testRefusalsCarryTheHeadersOnlyForAnAllowedOrigin(): void {
		$allowed = $this->controller(businessId: 'salon-001', origin: 'http://localhost:8080')->services();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $allowed->getStatus());
		self::assertSame('http://localhost:8080', $allowed->getHeaders()['Access-Control-Allow-Origin']);

		$other = $this->controller(businessId: 'salon-001', origin: 'https://evil.example')->slots(serviceId: 'svc', resourceId: 'res', date: '2026-12-03');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $other->getStatus());
		self::assertArrayNotHasKey('Access-Control-Allow-Origin', $other->getHeaders());
	}//end testRefusalsCarryTheHeadersOnlyForAnAllowedOrigin()
}//end class
