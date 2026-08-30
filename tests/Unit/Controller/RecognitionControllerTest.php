<?php

/**
 * Unit tests for RecognitionController.
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
 * @spec openspec/changes/order-revenue-recognition-engine/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RecognitionController;
use OCA\Shillinq\Service\RevenueRecognitionService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the recognized-recurring-revenue read endpoint controller.
 *
 * Covers the endpoint contract, the auth body-guard (401), input validation (400),
 * the happy-path projection, and the 500 fail path that returns no stack trace
 * (ADR-005 / no-admin-idor).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RecognitionControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock RevenueRecognitionService.
	 *
	 * @var RevenueRecognitionService&MockObject
	 */
	private RevenueRecognitionService&MockObject $service;

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
	 * Mock AdministrationContextService — the ADR-005 membership guard.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * What canAccess() answers. Flipped by the ADR-005 refusal test.
	 *
	 * Read through a callback rather than re-stubbed per test: a second
	 * `->method('canAccess')` APPENDS a matcher instead of replacing the first,
	 * so re-stubbing would silently keep answering true.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var RecognitionController
	 */
	private RecognitionController $controller;

	/**
	 * Set up test fixtures with an authenticated user by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(RevenueRecognitionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new RecognitionController(
			request: $this->request,
			recognitionService: $this->service,
			userSession: $this->userSession,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params (administrationId, from, to).
	 *
	 * @param string $admin String administrationId param.
	 * @param string $from String from param.
	 * @param string $to String to param.
	 *
	 * @return void
	 */
	private function withParams(string $admin, string $from, string $to): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($admin, $from, $to): mixed {
				return match ($key) {
					'administrationId' => $admin,
					'from' => $from,
					'to' => $to,
					default => $default,
				};
			}
		);

	}//end withParams()

	/**
	 * An unauthenticated caller yields HTTP 401 and never touches the data layer (ADR-005).
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$service = $this->createMock(RevenueRecognitionService::class);
		$service->expects($this->never())->method('computeRecurring');

		$controller = new RecognitionController(
			request: $request,
			recognitionService: $service,
			userSession: $userSession,
			context: $this->context,
			logger: $this->logger,
		);

		$response = $controller->recurringRevenue();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedReturns401()

	/**
	 * A missing administrationId yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testMissingAdministrationReturns400(): void {
		$this->withParams('', '2026-01-01', '2026-03-31');
		$response = $this->controller->recurringRevenue();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingAdministrationReturns400()

	/**
	 * A malformed administrationId (path traversal) yields HTTP 400 (ADR-005 no-IDOR).
	 *
	 * @return void
	 */
	public function testMalformedAdministrationReturns400(): void {
		$this->withParams('../../etc', '2026-01-01', '2026-03-31');
		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMalformedAdministrationReturns400()

	/**
	 * A non-ISO from/to yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testNonIsoDatesReturn400(): void {
		$this->withParams('adm-1', '01-01-2026', '2026-03-31');
		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testNonIsoDatesReturn400()

	/**
	 * A from after to yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testFromAfterToReturns400(): void {
		$this->withParams('adm-1', '2026-03-31', '2026-01-01');
		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testFromAfterToReturns400()

	/**
	 * A valid request returns HTTP 200 with the projected recognized/arr/currency/lineCount.
	 *
	 * @return void
	 */
	public function testValidRequestReturns200WithProjection(): void {
		$this->withParams('adm-1', '2026-01-01', '2026-03-31');
		$this->service->expects($this->once())
			->method('computeRecurring')
			->with('adm-1', '2026-01-01', '2026-03-31')
			->willReturn(
				[
					'recognized' => 7500.0,
					'oneOff' => 5000.0,
					'arr' => 30000.0,
					'currency' => 'EUR',
					'lineCount' => 2,
				]
			);

		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(7500.0, $data['recognized']);
		self::assertSame(30000.0, $data['arr']);
		self::assertSame('EUR', $data['currency']);
		self::assertSame(2, $data['lineCount']);
		// The one-off figure is NOT a top-level field of this recurring endpoint.
		self::assertArrayNotHasKey('oneOff', $data);

	}//end testValidRequestReturns200WithProjection()

	/**
	 * A service exception yields HTTP 500 with no stack trace leaked (ADR-005).
	 *
	 * @return void
	 */
	public function testServiceExceptionReturns500WithoutStackTrace(): void {
		$this->withParams('adm-1', '2026-01-01', '2026-03-31');
		$this->service->method('computeRecurring')->willThrowException(new \RuntimeException('OR fetch boom'));

		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('error', $data);
		self::assertStringNotContainsStringIgnoringCase('boom', (string)$data['error']);

	}//end testServiceExceptionReturns500WithoutStackTrace()

	/**
	 * A well-formed administrationId the caller has NO membership for yields 404 (ADR-005 / #518).
	 *
	 * The id passes every format check in the method; the docblock's
	 * "ADR-005 IDOR-safety" used to be satisfied by a character-class regex
	 * alone. The service must never be reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/recurring-revenue-recognition/spec.md
	 */
	public function testForeignAdministrationReturns404AndNeverReachesTheService(): void {
		$this->canAccess = false;
		$this->withParams('adm-not-mine', '2026-01-01', '2026-03-31');
		$this->service->expects($this->never())->method('computeRecurring');

		$response = $this->controller->recurringRevenue();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testForeignAdministrationReturns404AndNeverReachesTheService()
}//end class
