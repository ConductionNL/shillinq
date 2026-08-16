<?php

/**
 * Unit tests for AansluitingController.
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
 * @spec openspec/changes/bookkeeping-aansluitingen/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AansluitingController;
use OCA\Shillinq\Service\AansluitingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the Aansluiting compute/explain/resolve/reopen API controller.
 *
 * Covers REQ-AANS-004 (compute contract), REQ-AANS-006 (explain/resolve/reopen
 * contracts), parameter validation, auth, and the 500 fail path that leaks no
 * stack trace (ADR-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock AansluitingService.
	 *
	 * @var AansluitingService&MockObject
	 */
	private AansluitingService&MockObject $service;

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
	 * The controller under test.
	 *
	 * @var AansluitingController
	 */
	private AansluitingController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AansluitingService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bookkeeper-1');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new AansluitingController(
			request: $this->request,
			reconciliationService: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,string> $map Param map.
	 *
	 * @return void
	 */
	private function withParams(array $map): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($map): mixed {
				return ($map[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * An unauthenticated caller receives HTTP 401 on every endpoint (ADR-005).
	 *
	 * @return void
	 */
	public function testComputeRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = new AansluitingController(
			request: $this->request,
			reconciliationService: $this->service,
			userSession: $this->userSession,
			logger: $this->logger,
		);

		$response = $this->controller->compute(reconciliationId: 'aansl-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testComputeRequiresAuthentication()

	/**
	 * A missing period_id yields HTTP 400 on compute (REQ-AANS-004).
	 *
	 * @return void
	 */
	public function testComputeMissingPeriodReturns400(): void {
		$this->withParams([]);
		$response = $this->controller->compute(reconciliationId: 'aansl-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testComputeMissingPeriodReturns400()

	/**
	 * A path-traversal aansluitingId is rejected with HTTP 400 (IDOR-safe input).
	 *
	 * @return void
	 */
	public function testComputeMalformedAansluitingIdReturns400(): void {
		$this->withParams(['period_id' => '2026-Q2']);
		$response = $this->controller->compute(reconciliationId: '../../etc');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testComputeMalformedAansluitingIdReturns400()

	/**
	 * A valid compute request returns HTTP 200 with the service result (REQ-AANS-004).
	 *
	 * @return void
	 */
	public function testComputeValidReturns200(): void {
		$this->withParams(['period_id' => '2026-Q2']);
		$payload = ['id' => 'aanslres-1', 'status' => 'open', 'sourceATotal' => 4200.0, 'sourceBTotal' => 4450.0];
		$this->service->expects($this->once())
			->method('compute')
			->with('aansl-1', '2026-Q2')
			->willReturn($payload);

		$response = $this->controller->compute(reconciliationId: 'aansl-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testComputeValidReturns200()

	/**
	 * A service exception maps to HTTP 500 without leaking the exception message (ADR-005).
	 *
	 * @return void
	 */
	public function testComputeServiceFailureReturns500WithoutLeakingDetail(): void {
		$this->withParams(['period_id' => '2026-Q2']);
		$this->service->method('compute')->willThrowException(new RuntimeException('No filed VATReturn found — internal detail'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->compute(reconciliationId: 'aansl-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('internal detail', (string)json_encode($response->getData()));

	}//end testComputeServiceFailureReturns500WithoutLeakingDetail()

	/**
	 * A missing reason_text yields HTTP 400 on explain (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testExplainMissingReasonTextReturns400(): void {
		$this->withParams(['reason_code' => 'timing']);
		$response = $this->controller->explain(resultId: 'aanslres-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testExplainMissingReasonTextReturns400()

	/**
	 * A valid explain request returns HTTP 200 and forwards the acting user id (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testExplainValidReturns200(): void {
		$this->withParams(['reason_code' => 'timing', 'reason_text' => 'Volgt bij de volgende recompute.']);
		$payload = ['id' => 'aanslres-1', 'status' => 'explained'];
		$this->service->expects($this->once())
			->method('explain')
			->with('aanslres-1', 'timing', 'Volgt bij de volgende recompute.', 'bookkeeper-1')
			->willReturn($payload);

		$response = $this->controller->explain(resultId: 'aanslres-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testExplainValidReturns200()

	/**
	 * A valid resolve request returns HTTP 200 and forwards the acting user id (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testResolveValidReturns200(): void {
		$this->withParams([]);
		$payload = ['id' => 'aanslres-1', 'status' => 'resolved'];
		$this->service->expects($this->once())
			->method('resolve')
			->with('aanslres-1', 'bookkeeper-1')
			->willReturn($payload);

		$response = $this->controller->resolve(resultId: 'aanslres-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testResolveValidReturns200()

	/**
	 * A valid reopen request returns HTTP 200 and forwards the acting user id + reason.
	 *
	 * @return void
	 */
	public function testReopenValidReturns200(): void {
		$this->withParams(['reason' => 'Nieuwe factuur ontdekt.']);
		$payload = ['id' => 'aanslres-1', 'status' => 'open'];
		$this->service->expects($this->once())
			->method('reopen')
			->with('aanslres-1', 'bookkeeper-1', 'Nieuwe factuur ontdekt.')
			->willReturn($payload);

		$response = $this->controller->reopen(resultId: 'aanslres-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testReopenValidReturns200()
}//end class
