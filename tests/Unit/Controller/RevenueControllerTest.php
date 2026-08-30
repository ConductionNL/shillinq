<?php

/**
 * Unit tests for RevenueController.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RevenueController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RevenueCutoffService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the IFRS 15 revenue cut-off API controller.
 *
 * Covers the endpoint contract (REQ-IFRS15-007, REQ-IFRS15-008), input validation
 * (ADR-005), and the 500 fail path that returns no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RevenueControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock RevenueCutoffService.
	 *
	 * @var RevenueCutoffService&MockObject
	 */
	private RevenueCutoffService&MockObject $service;

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
	 * What canAccess() answers. Flipped by the ADR-005 refusal tests.
	 *
	 * Read through a callback rather than re-stubbed per test: a second
	 * `->method('canAccess')` on the same mock APPENDS a matcher, it does not
	 * replace the first, so re-stubbing would silently keep answering true.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var RevenueController
	 */
	private RevenueController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(RevenueCutoffService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		// Default: the caller IS a member. $this->canAccess is flipped by the
		// ADR-005 refusal test.
		$this->canAccess = true;
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new RevenueController(
			request: $this->request,
			cutoffService: $this->service,
			userSession: $this->userSession,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params (administration_id, period_end).
	 *
	 * @param string $admin String administration_id param.
	 * @param string $periodEnd String period_end param.
	 *
	 * @return void
	 */
	private function withParams(string $admin, string $periodEnd): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($admin, $periodEnd): mixed {
				return match ($key) {
					'administration_id' => $admin,
					'period_end' => $periodEnd,
					default => $default,
				};
			}
		);

	}//end withParams()

	/**
	 * A missing administration_id yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testMissingAdministrationReturns400(): void {
		$this->withParams('', '2026-06-30');
		$response = $this->controller->cutoff();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingAdministrationReturns400()

	/**
	 * A missing period_end yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testMissingPeriodEndReturns400(): void {
		$this->withParams('adm-1', '');
		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingPeriodEndReturns400()

	/**
	 * A malformed administration_id (path traversal) yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testMalformedAdministrationReturns400(): void {
		$this->withParams('../../etc', '2026-06-30');
		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMalformedAdministrationReturns400()

	/**
	 * A non-ISO period_end yields HTTP 400 (ADR-005).
	 *
	 * @return void
	 */
	public function testNonIsoPeriodEndReturns400(): void {
		$this->withParams('adm-1', '30-06-2026');
		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testNonIsoPeriodEndReturns400()

	/**
	 * A valid request returns HTTP 200 with the service result (REQ-IFRS15-007).
	 *
	 * @return void
	 */
	public function testValidRequestReturns200WithData(): void {
		$this->withParams('adm-1', '2026-06-30');
		$payload = [
			'data' => [['contractId' => 'C-2026-001', 'contractLiability' => 857.0]],
			'total' => 1,
		];
		$this->service->expects($this->once())
			->method('compute')
			->with('adm-1', '2026-06-30')
			->willReturn($payload);

		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testValidRequestReturns200WithData()

	/**
	 * A service exception yields HTTP 500 with no stack trace leaked (ADR-005).
	 *
	 * @return void
	 */
	public function testServiceExceptionReturns500WithoutStackTrace(): void {
		$this->withParams('adm-1', '2026-06-30');
		$this->service->method('compute')->willThrowException(new \RuntimeException('GL fetch boom'));

		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		self::assertArrayHasKey('error', $data);
		self::assertStringNotContainsStringIgnoringCase('boom', (string)$data['error']);

	}//end testServiceExceptionReturns500WithoutStackTrace()

	/**
	 * A well-formed administration_id the caller has NO membership for yields 404 (ADR-005 / #518).
	 *
	 * The id passes every format check in the method — this is exactly the case
	 * the old code allowed through, because a character-class regex was the only
	 * thing between an authenticated user and another tenant's revenue cut-off.
	 * The service must never be reached.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-ifrs15-revenue/spec.md
	 */
	public function testForeignAdministrationReturns404AndNeverReachesTheService(): void {
		$this->canAccess = false;
		$this->withParams('adm-not-mine', '2026-06-30');
		$this->service->expects($this->never())->method('compute');

		$response = $this->controller->cutoff();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testForeignAdministrationReturns404AndNeverReachesTheService()
}//end class
