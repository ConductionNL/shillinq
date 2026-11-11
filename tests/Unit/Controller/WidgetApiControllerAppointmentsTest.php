<?php

/**
 * Characterisation tests for WidgetApiController::appointments().
 *
 * Pins every payload refusal message, the order in which the guard, the
 * payload checks and the OpenRegister availability check run, and the
 * mapping from the write path's result code to the HTTP response.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the POST /api/widget/appointments action.
 */
class WidgetApiControllerAppointmentsTest extends TestCase {

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
	 * Settings stub.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The widget booking write path.
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
		$this->settings = $this->createMock(SettingsService::class);
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
			$this->request,
			$this->auth,
			$this->widgetService,
			$this->createMock(SlotService::class),
			$this->settings,
			$this->createMock(ContainerInterface::class),
			$this->logger,
		);

	}//end makeController()

	/**
	 * Let the widget guard accept the request.
	 *
	 * @return void
	 */
	private function authorise(): void {
		$this->request->method('getParam')->willReturnMap([['businessId', '', 'salon-001']]);
		$this->request->method('getHeader')->willReturnMap([['Authorization', 'Bearer bk_live_valid']]);
		$this->auth->method('validateApiKey')->willReturn(
			['valid' => true, 'key' => ['rateLimit' => 100, 'administrationId' => 'adm-salon']]
		);
		$this->auth->method('consumeRateLimit')->willReturn(['allowed' => true]);

	}//end authorise()

	/**
	 * A valid payload, overridable per key.
	 *
	 * @param array<string,string|null> $override Keys to replace.
	 *
	 * @return array<string,string|null>
	 */
	private static function payload(array $override): array {
		return array_merge(
			[
				'serviceId' => 'svc-001',
				'resourceId' => 'res-001',
				'startTime' => '2026-05-21T10:00:00Z',
				'endTime' => '2026-05-21T10:30:00Z',
				'customerName' => 'Anna de Wit',
				'email' => 'anna@example.com',
				'phone' => null,
				'notes' => null,
			],
			$override
		);

	}//end payload()

	/**
	 * Call appointments() with a payload.
	 *
	 * @param array<string,string|null> $payload The action arguments.
	 *
	 * @return JSONResponse
	 */
	private function book(array $payload): JSONResponse {
		return $this->makeController()->appointments(
			$payload['serviceId'],
			$payload['resourceId'],
			$payload['startTime'],
			$payload['endTime'],
			$payload['customerName'],
			$payload['email'],
			$payload['phone'],
			$payload['notes'],
		);

	}//end book()

	/**
	 * Every invalid payload and the message it is refused with.
	 *
	 * @return array<string,array{0:array<string,string|null>,1:string}>
	 */
	public static function invalidPayloads(): array {
		$required = 'serviceId, resourceId, startTime and endTime are required.';
		$iso = 'startTime and endTime must be ISO 8601 UTC (YYYY-MM-DDTHH:MM:SSZ).';
		return [
			'no service' => [self::payload(['serviceId' => '']), $required],
			'no resource' => [self::payload(['resourceId' => '']), $required],
			'no start' => [self::payload(['startTime' => '']), $required],
			'no end' => [self::payload(['endTime' => '']), $required],
			'missing beats bad name' => [self::payload(['endTime' => '', 'customerName' => '']), $required],
			'bad start' => [self::payload(['startTime' => '2026-05-21 10:00']), $iso],
			'bad end' => [self::payload(['endTime' => '2026-05-21T10:30:00+01:00']), $iso],
			'blank name' => [self::payload(['customerName' => '   ']), 'customerName must be 1-255 characters.'],
			'long name' => [self::payload(['customerName' => str_repeat('a', 256)]), 'customerName must be 1-255 characters.'],
			'name chars' => [self::payload(['customerName' => 'Anna <b>']), 'customerName contains invalid characters.'],
			'no email' => [self::payload(['email' => '']), 'Please enter a valid email address.'],
			'bad email' => [self::payload(['email' => 'anna@']), 'Please enter a valid email address.'],
			'bad email beats bad phone' => [self::payload(['email' => 'x', 'phone' => 'abc']), 'Please enter a valid email address.'],
			'bad phone' => [self::payload(['phone' => '0612']), 'Phone number must be E.164 (e.g. +31612345678).'],
			'bad phone beats long notes' => [self::payload(['phone' => 'abc', 'notes' => str_repeat('n', 501)]), 'Phone number must be E.164 (e.g. +31612345678).'],
			'long notes' => [self::payload(['notes' => str_repeat('n', 501)]), 'notes must be at most 500 characters.'],
		];

	}//end invalidPayloads()

	/**
	 * Each invalid payload is a 400 with its own message, before any write.
	 *
	 * @param array<string,string|null> $payload The action arguments.
	 * @param string                    $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider invalidPayloads
	 */
	public function testInvalidPayloadIsRefusedWithItsMessage(array $payload, string $message): void {
		$this->authorise();
		$this->settings->expects(self::never())->method('isOpenRegisterAvailable');
		$this->widgetService->expects(self::never())->method('createAppointment');

		$response = $this->book($payload);

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'bad-request', 'message' => $message], $response->getData());

	}//end testInvalidPayloadIsRefusedWithItsMessage()

	/**
	 * Edge values that pass validation reach the write path.
	 *
	 * @return array<string,array{0:array<string,string|null>}>
	 */
	public static function acceptedPayloads(): array {
		return [
			'empty phone' => [self::payload(['phone' => ''])],
			'empty notes' => [self::payload(['notes' => ''])],
			'notes of 500' => [self::payload(['notes' => str_repeat('n', 500)])],
			'name of 255' => [self::payload(['customerName' => str_repeat('a', 255)])],
			'phone without plus' => [self::payload(['phone' => '31612345678'])],
		];

	}//end acceptedPayloads()

	/**
	 * Accepted edge values are booked, with null phone and notes sent as ''.
	 *
	 * @param array<string,string|null> $payload The action arguments.
	 *
	 * @return void
	 *
	 * @dataProvider acceptedPayloads
	 */
	public function testAcceptedPayloadIsForwarded(array $payload): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->expects(self::once())->method('createAppointment')
			->with(
				'adm-salon',
				[
					'serviceId' => $payload['serviceId'],
					'resourceId' => $payload['resourceId'],
					'startTime' => $payload['startTime'],
					'endTime' => $payload['endTime'],
					'customerName' => $payload['customerName'],
					'customerEmail' => $payload['email'],
					'customerPhone' => ($payload['phone'] ?? ''),
					'notes' => ($payload['notes'] ?? ''),
				]
			)
			->willReturn(['code' => 201, 'appointmentId' => 'apt-1']);

		$response = $this->book($payload);

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(
			['appointmentId' => 'apt-1', 'status' => 'pending_confirmation', 'confirmationMessage' => ''],
			$response->getData()
		);

	}//end testAcceptedPayloadIsForwarded()

	/**
	 * The guard runs before the payload is looked at.
	 *
	 * @return void
	 */
	public function testGuardRunsBeforeValidation(): void {
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getHeader')->willReturn('');

		$response = $this->book(self::payload(['serviceId' => '']));

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testGuardRunsBeforeValidation()

	/**
	 * Without OpenRegister a valid booking is a 500 and nothing is written.
	 *
	 * @return void
	 */
	public function testUnavailableOpenRegisterIsAServerError(): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);
		$this->widgetService->expects(self::never())->method('createAppointment');

		$response = $this->book(self::payload([]));

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'server-error', 'message' => 'Booking unavailable.'], $response->getData());

	}//end testUnavailableOpenRegisterIsAServerError()

	/**
	 * A double booking is a 409 with the retry message.
	 *
	 * @return void
	 */
	public function testConflictIsMappedToSlotUnavailable(): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->method('createAppointment')->willReturn(['code' => 409]);

		$response = $this->book(self::payload([]));

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(
			['error' => 'slot-unavailable', 'message' => 'This slot was just booked. Please select another time.'],
			$response->getData()
		);

	}//end testConflictIsMappedToSlotUnavailable()

	/**
	 * A refused service is a 404 with the not-bookable message.
	 *
	 * @return void
	 */
	public function testNotFoundIsMappedToServiceNotFound(): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->method('createAppointment')->willReturn(['code' => 404]);

		$response = $this->book(self::payload([]));

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(
			['error' => 'service-not-found', 'message' => 'This service is not available for online booking.'],
			$response->getData()
		);

	}//end testNotFoundIsMappedToServiceNotFound()

	/**
	 * Any other code is logged and answered with a 500.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function failedResults(): array {
		return [
			'error code' => [['code' => 422, 'error' => 'invalid'], 'invalid'],
			'no code' => [[], 'unknown'],
		];

	}//end failedResults()

	/**
	 * A failed write is logged with its error and answered with a 500.
	 *
	 * @param array<string,mixed> $result The write path's result.
	 * @param string              $logged The error that is logged.
	 *
	 * @return void
	 *
	 * @dataProvider failedResults
	 */
	public function testFailedWriteIsLoggedAndAServerError(array $result, string $logged): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->method('createAppointment')->willReturn($result);
		$this->logger->expects(self::once())->method('error')
			->with('Shillinq widget: appointment create failed', ['error' => $logged]);

		$response = $this->book(self::payload([]));

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(
			['error' => 'server-error', 'message' => 'Booking failed. Please try again later.'],
			$response->getData()
		);

	}//end testFailedWriteIsLoggedAndAServerError()

	/**
	 * A created booking returns the write path's id, status and message.
	 *
	 * @return void
	 */
	public function testCreatedBookingReturnsItsSummary(): void {
		$this->authorise();
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->widgetService->method('createAppointment')->willReturn(
			['code' => 201, 'appointmentId' => 'apt-9', 'status' => 'confirmed', 'confirmationMessage' => 'See you']
		);

		$response = $this->book(self::payload([]));

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame(
			['appointmentId' => 'apt-9', 'status' => 'confirmed', 'confirmationMessage' => 'See you'],
			$response->getData()
		);

	}//end testCreatedBookingReturnsItsSummary()
}//end class
