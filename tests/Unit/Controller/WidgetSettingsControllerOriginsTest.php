<?php

/**
 * Unit tests for WidgetSettingsController::origins() (REQ-WSW-011).
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

use OCA\Shillinq\Controller\WidgetSettingsController;
use OCA\Shillinq\Service\WidgetAuthService;
use OCA\Shillinq\Service\WidgetCorsPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The admin sets the websites that may embed a widget.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.5
 */
#[CoversClass(WidgetSettingsController::class)]
#[UsesClass(WidgetCorsPolicy::class)]
final class WidgetSettingsControllerOriginsTest extends TestCase {

	/**
	 * The widget keys.
	 *
	 * @var WidgetAuthService&MockObject
	 */
	private WidgetAuthService&MockObject $auth;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auth = $this->createMock(WidgetAuthService::class);
	}//end setUp()

	/**
	 * The controller over a request with the given parameters.
	 *
	 * @param array<string, mixed> $params     The request parameters.
	 * @param bool                 $withPolicy Whether the CORS policy is wired.
	 *
	 * @return WidgetSettingsController
	 */
	private function controller(array $params, bool $withPolicy = true): WidgetSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policy = null;
		if ($withPolicy === true) {
			$policy = new WidgetCorsPolicy(keys: $this->auth);
		}

		return new WidgetSettingsController(request: $request, authService: $this->auth, userSession: $session, corsPolicy: $policy);
	}//end controller()

	/**
	 * Valid origins are stored for the acting admin and answered 200.
	 *
	 * @return void
	 */
	public function testValidOriginsAreStored(): void {
		$this->auth->expects(self::once())->method('updateAllowedOrigins')
			->with('salon-1', ['https://kapsalon.example'], 'alice')
			->willReturn(['success' => true, 'businessId' => 'salon-1', 'allowedOrigins' => ['https://kapsalon.example']]);

		$response = $this->controller(params: ['businessId' => ' salon-1 ', 'allowedOrigins' => ['key' => 'https://Kapsalon.example/']])->origins();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['success' => true, 'businessId' => 'salon-1', 'allowedOrigins' => ['https://kapsalon.example']], $response->getData());
	}//end testValidOriginsAreStored()

	/**
	 * A wildcard, a missing business or a non-list is refused with 400 and stores nothing.
	 *
	 * @return void
	 */
	public function testInvalidRequestsAreRefused(): void {
		$this->auth->expects(self::never())->method('updateAllowedOrigins');

		$wildcard = $this->controller(params: ['businessId' => 'salon-1', 'allowedOrigins' => ['*']])->origins();
		self::assertSame(Http::STATUS_BAD_REQUEST, $wildcard->getStatus());
		self::assertFalse($wildcard->getData()['success']);

		$noBusiness = $this->controller(params: ['allowedOrigins' => ['https://kapsalon.example']])->origins();
		self::assertSame(['success' => false, 'message' => 'businessId and a list of allowedOrigins are required.'], $noBusiness->getData());

		$notAList = $this->controller(params: ['businessId' => 'salon-1', 'allowedOrigins' => 'https://kapsalon.example'])->origins();
		self::assertSame(Http::STATUS_BAD_REQUEST, $notAList->getStatus());

		$noPolicy = $this->controller(params: ['businessId' => 'salon-1', 'allowedOrigins' => []], withPolicy: false)->origins();
		self::assertSame(['success' => false, 'message' => 'Allowed origins cannot be set on this instance.'], $noPolicy->getData());
	}//end testInvalidRequestsAreRefused()

	/**
	 * Only an administrator of the app reaches the endpoint.
	 *
	 * @return void
	 */
	public function testTheEndpointIsAdminOnly(): void {
		$attributes = (new ReflectionMethod(WidgetSettingsController::class, 'origins'))->getAttributes(AuthorizedAdminSetting::class);

		self::assertCount(1, $attributes);
	}//end testTheEndpointIsAdminOnly()
}//end class
