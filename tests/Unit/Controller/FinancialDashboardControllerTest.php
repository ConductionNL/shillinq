<?php

/**
 * Unit tests for FinancialDashboardController.
 *
 * Covers the endpoint contract of the Wave-4 financial dashboard API: the
 * authentication gate, from/to validation (both-or-neither + ISO-8601
 * format), the delegation to FinancialDashboardService and the 500 fail path
 * that returns no stack trace (ADR-005).
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FinancialDashboardController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the financial dashboard API controller.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FinancialDashboardControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock FinancialDashboardService.
	 *
	 * @var FinancialDashboardService&MockObject
	 */
	private FinancialDashboardService&MockObject $service;

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
	 * @var FinancialDashboardController
	 */
	private FinancialDashboardController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(FinancialDashboardService::class);
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: an authenticated user who is a member of at least one
		// administration (security-endpoint-guards REQ-001 guard — see
		// testSeriesRejectsCallerWithNoAccessibleAdministration for the
		// negative direction).
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('accessibleAdministrationIds')->willReturn(['adm-1']);

		$this->controller = new FinancialDashboardController(
			request: $this->request,
			dashboard: $this->service,
			context: $this->context,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure the from/to request params.
	 *
	 * @param string $from The from param.
	 * @param string $to The to param.
	 *
	 * @return void
	 */
	private function withParams(string $from, string $to): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $name, mixed $default = null) use ($from, $to): mixed {
				if ($name === 'from') {
					return $from;
				}

				if ($name === 'to') {
					return $to;
				}

				return $default;
			}
		);

	}//end withParams()

	/**
	 * An unauthenticated request is rejected with 401.
	 *
	 * @return void
	 */
	public function testSeriesRejectsUnauthenticatedRequests(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn(null);

		$controller = new FinancialDashboardController(
			request: $this->request,
			dashboard: $this->service,
			context: $context,
			logger: $this->logger,
		);

		$response = $controller->series();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSeriesRejectsUnauthenticatedRequests()

	/**
	 * NEGATIVE CONTROL (security-endpoint-guards REQ-001): an authenticated
	 * user with zero AdministrationMembership records is rejected with 403
	 * before the unscoped FinancialDashboardService read runs. Before this
	 * guard, such a caller received the platform-wide aggregate of every
	 * tenant's turnover/margin/cashflow — see the guard's docblock in
	 * FinancialDashboardController::respond() for the full evidence.
	 *
	 * @return void
	 */
	public function testSeriesRejectsCallerWithNoAccessibleAdministration(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('mallory');
		$context->method('accessibleAdministrationIds')->willReturn([]);

		$controller = new FinancialDashboardController(
			request: $this->request,
			dashboard: $this->service,
			context: $context,
			logger: $this->logger,
		);

		$this->service->expects($this->never())->method('series');

		$response = $controller->series();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSeriesRejectsCallerWithNoAccessibleAdministration()

	/**
	 * Same negative direction for summary() — both endpoints share the
	 * respond() guard.
	 *
	 * @return void
	 */
	public function testSummaryRejectsCallerWithNoAccessibleAdministration(): void {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn('mallory');
		$context->method('accessibleAdministrationIds')->willReturn([]);

		$controller = new FinancialDashboardController(
			request: $this->request,
			dashboard: $this->service,
			context: $context,
			logger: $this->logger,
		);

		$this->service->expects($this->never())->method('summary');

		$response = $controller->summary();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSummaryRejectsCallerWithNoAccessibleAdministration()

	/**
	 * Providing only one of from/to is a 400.
	 *
	 * @return void
	 */
	public function testSeriesRejectsOneSidedRange(): void {
		$this->withParams('2026-01-01', '');

		$response = $this->controller->series();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSeriesRejectsOneSidedRange()

	/**
	 * A malformed bound is a 400.
	 *
	 * @return void
	 */
	public function testSeriesRejectsMalformedDates(): void {
		$this->withParams('not-a-date', '2026-04-30');

		$response = $this->controller->series();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSeriesRejectsMalformedDates()

	/**
	 * A valid range is passed to the service and the payload is returned.
	 *
	 * @return void
	 */
	public function testSeriesDelegatesToServiceWithRange(): void {
		$this->withParams('2026-02-01', '2026-04-30');
		$payload = ['months' => ['2026-02']];
		$this->service->expects($this->once())
			->method('series')
			->with('2026-02-01', '2026-04-30')
			->willReturn($payload);

		$response = $this->controller->series();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());

	}//end testSeriesDelegatesToServiceWithRange()

	/**
	 * A missing range is passed to the service as nulls (fallback window).
	 *
	 * @return void
	 */
	public function testSummaryDelegatesToServiceWithoutRange(): void {
		$this->withParams('', '');
		$payload = ['current' => []];
		$this->service->expects($this->once())
			->method('summary')
			->with(null, null)
			->willReturn($payload);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($payload, $response->getData());

	}//end testSummaryDelegatesToServiceWithoutRange()

	/**
	 * An unexpected service failure yields a 500 without a stack trace.
	 *
	 * @return void
	 */
	public function testSummaryReturnsSanitised500OnServiceFailure(): void {
		$this->withParams('', '');
		$this->service->method('summary')->willThrowException(new \RuntimeException('secret internals'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertStringNotContainsString('secret internals', json_encode($data));

	}//end testSummaryReturnsSanitised500OnServiceFailure()
}//end class
