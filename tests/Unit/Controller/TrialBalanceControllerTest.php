<?php

/**
 * Unit tests for TrialBalanceController.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-2
 * KNOWINGLY DANGLING until shillinq#500 — see TrialBalanceService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\TrialBalanceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TrialBalanceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the trial-balance API controller.
 *
 * Covers REQ-TB-009 (endpoint contract), REQ-TB-015 (period validation) and the
 * 500 fail path that returns no stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TrialBalanceControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock TrialBalanceService.
	 *
	 * @var TrialBalanceService&MockObject
	 */
	private TrialBalanceService&MockObject $service;

	/**
	 * Mock AdministrationContextService.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var TrialBalanceController
	 */
	private TrialBalanceController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TrialBalanceService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: an authenticated user with access to 'adm-1'.
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		$this->controller = new TrialBalanceController(
			request: $this->request,
			trialBalanceService: $this->service,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params (period_id, administration_id, prior_period_id).
	 *
	 * @param string $period String period_id param.
	 * @param string $admin String administration_id param.
	 * @param string $prior String prior_period_id param.
	 *
	 * @return void
	 */
	private function withParams(string $period, string $admin, string $prior = ''): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($period, $admin, $prior): mixed {
				return match ($key) {
					'period_id' => $period,
					'administration_id' => $admin,
					'prior_period_id' => $prior,
					default => $default,
				};
			}
		);

	}//end withParams()

	/**
	 * A missing period_id yields HTTP 400 (REQ-TB-015).
	 *
	 * @return void
	 */
	public function testMissingPeriodReturns400(): void {
		$this->withParams('', 'adm-1');
		$response = $this->controller->index();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingPeriodReturns400()

	/**
	 * A missing administration_id yields HTTP 400 (REQ-TB-016).
	 *
	 * @return void
	 */
	public function testMissingAdministrationReturns400(): void {
		$this->withParams('2026-Q1', '');
		$response = $this->controller->index();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMissingAdministrationReturns400()

	/**
	 * A malformed period_id (path-traversal attempt) yields HTTP 400 (REQ-TB-015).
	 *
	 * @return void
	 */
	public function testMalformedPeriodReturns400(): void {
		$this->withParams('../../etc', 'adm-1');
		$response = $this->controller->index();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testMalformedPeriodReturns400()

	/**
	 * A valid request returns HTTP 200 with the service result (REQ-TB-009).
	 *
	 * @return void
	 */
	public function testValidRequestReturns200WithData(): void {
		$this->withParams('2026-Q1', 'adm-1', '2025-Q4');
		$payload = [
			'data' => [['accountNumber' => '1000', 'closingBalance' => 55000.0]],
			'total' => 1,
			'totals' => ['totalDebit' => 10000.0, 'totalCredit' => 5000.0],
			'isBalanced' => false,
		];
		$this->service->expects($this->once())
			->method('compute')
			->with('adm-1', '2026-Q1', ['priorPeriodId' => '2025-Q4'])
			->willReturn($payload);

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testValidRequestReturns200WithData()

	/**
	 * A service exception yields HTTP 500 with no stack trace leaked (ADR-005).
	 *
	 * @return void
	 */
	public function testServiceFailureReturns500WithoutStackTrace(): void {
		$this->withParams('2026-Q1', 'adm-1');
		$this->service->method('compute')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Failed to compute trial balance'], $response->getData());

	}//end testServiceFailureReturns500WithoutStackTrace()

	/**
	 * An unauthenticated request yields HTTP 401 before any data access (REQ-TB-016).
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$request = $this->createMock(IRequest::class);
		$service = $this->createMock(TrialBalanceService::class);
		$context = $this->createMock(AdministrationContextService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$context->method('currentUserId')->willReturn(null);
		$service->expects($this->never())->method('compute');

		$controller = new TrialBalanceController(
			request: $request,
			trialBalanceService: $service,
			context: $context,
			logger: $logger,
		);

		$response = $controller->index();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testUnauthenticatedReturns401()

	/**
	 * A foreign administrationId is masked as HTTP 404 (REQ-TB-016, REQ-TB-017 IDOR guard).
	 *
	 * @return void
	 */
	public function testForeignAdministrationIsMaskedAs404(): void {
		$this->withParams('2026-Q1', 'adm-other');
		$this->service->expects($this->never())->method('compute');

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Administration not found'], $response->getData());

	}//end testForeignAdministrationIsMaskedAs404()
}//end class
