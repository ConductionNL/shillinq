<?php

/**
 * Unit tests for PayrollController.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PayrollController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PayrollService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the payroll compute API controller.
 *
 * Covers parameter validation (400), not-found (404), the 500 fail path that
 * returns no stack trace (ADR-005), and the success contract for each endpoint.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock PayrollService.
	 *
	 * @var PayrollService&MockObject
	 */
	private PayrollService&MockObject $service;

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
	 * Mock IL10N.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

	/**
	 * What canAccess() answers. Flipped by the REQ-MA-001 refusal tests.
	 *
	 * @var bool
	 */
	private bool $canAccess = true;

	/**
	 * The controller under test.
	 *
	 * @var PayrollController
	 */
	private PayrollController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PayrollService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		// The ADR-005 membership guard. Default ALLOW so the pre-existing tests
		// keep asserting what they were written to assert; the refusal tests
		// flip $this->canAccess. A callback rather than a second
		// ->method('canAccess') call, because PHPUnit APPENDS matchers instead
		// of replacing the first one.
		$this->canAccess = true;
		$this->context = $this->createMock(AdministrationContextService::class);
		$this->context->method('canAccess')->willReturnCallback(fn (): bool => $this->canAccess);

		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->controller = new PayrollController(
			request: $this->request,
			payrollService: $this->service,
			userSession: $this->userSession,
			context: $this->context,
			logger: $this->logger,
			l10n: $this->l10n,
		);

	}//end setUp()

	/**
	 * Map the request param stub to a fixed set of values.
	 *
	 * @param array<string,string> $params Param name => value.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

	}//end withParams()

	/**
	 * A blank administration_id yields HTTP 400 (REQ-PAY scoping).
	 *
	 * @return void
	 */
	public function testLoonstrookRejectsBlankAdministration(): void {
		$this->withParams(['administration_id' => '', 'werknemer_id' => 'wn-1', 'periode_id' => 'lp-1']);
		$response = $this->controller->loonstrook();
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLoonstrookRejectsBlankAdministration()

	/**
	 * A malformed identifier (path traversal) is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testLoonstrookRejectsMalformedId(): void {
		$this->withParams(['administration_id' => '../etc', 'werknemer_id' => 'wn-1', 'periode_id' => 'lp-1']);
		$response = $this->controller->loonstrook();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLoonstrookRejectsMalformedId()

	/**
	 * A missing record surfaces as HTTP 404 (RuntimeException from the service).
	 *
	 * @return void
	 */
	public function testLoonstrookReturns404OnMissingRecord(): void {
		$this->withParams(['administration_id' => 'adm-1', 'werknemer_id' => 'wn-x', 'periode_id' => 'lp-1']);
		$this->service->method('berekenLoonStrook')->willThrowException(new \RuntimeException('niet gevonden'));
		$response = $this->controller->loonstrook();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testLoonstrookReturns404OnMissingRecord()

	/**
	 * An unexpected error yields HTTP 500 without leaking a stack trace (ADR-005).
	 *
	 * @return void
	 */
	public function testLoonstrookReturns500WithoutStackTrace(): void {
		$this->withParams(['administration_id' => 'adm-1', 'werknemer_id' => 'wn-1', 'periode_id' => 'lp-1']);
		$this->service->method('berekenLoonStrook')->willThrowException(new \LogicException('boom at line 42'));
		$response = $this->controller->loonstrook();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('boom at line 42', (string)json_encode($response->getData()));

	}//end testLoonstrookReturns500WithoutStackTrace()

	/**
	 * A valid request returns the computed payslip with HTTP 200.
	 *
	 * @return void
	 */
	public function testLoonstrookSuccess(): void {
		$this->withParams(['administration_id' => 'adm-1', 'werknemer_id' => 'wn-1', 'periode_id' => 'lp-1']);
		$payload = ['employeeId' => 'wn-1', 'netPaid' => 3403.61];
		$this->service->method('berekenLoonStrook')->willReturn($payload);
		$response = $this->controller->loonstrook();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testLoonstrookSuccess()

	/**
	 * Every payroll endpoint refuses an administration the caller holds no
	 * membership for (ADR-005 / REQ-MA-001), and refuses it BEFORE computing.
	 *
	 * ⚠️ The 404 alone would prove nothing — testLoonstrookReturns404OnMissingRecord()
	 * also answers 404, from the service. What distinguishes a REFUSAL from a
	 * LOOKUP-THAT-MISSED is that the payroll service is never consulted at all,
	 * so that is the assertion. Remove the guard from the controller and every
	 * arm of this test errors on its never() matcher rather than merely failing
	 * a status comparison.
	 *
	 * @param string $method The controller method to drive.
	 * @param string $serviceMethod The PayrollService method that must not run.
	 *
	 * @return void
	 *
	 * @dataProvider payrollEndpointProvider
	 */
	public function testRefusesInaccessibleAdministrationWithoutComputing(
		string $method,
		string $serviceMethod
	): void {
		$this->canAccess = false;
		$this->withParams(
			[
				'administration_id' => 'someone-elses-adm',
				'werknemer_id' => 'wn-1',
				'periode_id' => 'lp-1',
			]
		);
		$this->service->expects(self::never())->method($serviceMethod);

		$response = $this->controller->$method();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Administration not found', $response->getData()['error']);

	}//end testRefusesInaccessibleAdministrationWithoutComputing()

	/**
	 * The three administration-scoped payroll endpoints and the service call
	 * each one must not reach when the membership check refuses.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function payrollEndpointProvider(): array {
		return [
			'loonstrook (payslip)' => ['loonstrook', 'berekenLoonStrook'],
			'lhAfdracht (wage-tax remittance)' => ['lhAfdracht', 'berekenLHAfdracht'],
			'journaalpost (payroll journal)' => ['journaalpost', 'bouwLoonjournaalpost'],
		];

	}//end payrollEndpointProvider()

	/**
	 * LH-afdracht requires a period and returns the aggregate with HTTP 200.
	 *
	 * @return void
	 */
	public function testLhAfdrachtSuccess(): void {
		$this->withParams(['administration_id' => 'adm-1', 'periode_id' => 'lp-1']);
		$payload = ['periodId' => 'lp-1', 'totalRemittance' => 2936.56, 'status' => 'VOORBEREID'];
		$this->service->method('berekenLHAfdracht')->willReturn($payload);
		$response = $this->controller->lhAfdracht();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($payload, $response->getData());

	}//end testLhAfdrachtSuccess()

	/**
	 * LH-afdracht rejects a blank period with HTTP 400.
	 *
	 * @return void
	 */
	public function testLhAfdrachtRejectsBlankPeriod(): void {
		$this->withParams(['administration_id' => 'adm-1', 'periode_id' => '']);
		$response = $this->controller->lhAfdracht();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testLhAfdrachtRejectsBlankPeriod()

	/**
	 * The journaalpost endpoint returns the balanced journal with HTTP 200.
	 *
	 * @return void
	 */
	public function testJournaalpostSuccess(): void {
		$this->withParams(['administration_id' => 'adm-1', 'periode_id' => 'lp-1']);
		$payload = ['periodId' => 'lp-1', 'balanced' => true, 'rules' => []];
		$this->service->method('bouwLoonjournaalpost')->willReturn($payload);
		$response = $this->controller->journaalpost();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['balanced']);

	}//end testJournaalpostSuccess()

	/**
	 * The journaalpost endpoint surfaces an unexpected error as HTTP 500.
	 *
	 * @return void
	 */
	public function testJournaalpostReturns500(): void {
		$this->withParams(['administration_id' => 'adm-1', 'periode_id' => 'lp-1']);
		$this->service->method('bouwLoonjournaalpost')->willThrowException(new \RuntimeException('db down'));
		$response = $this->controller->journaalpost();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testJournaalpostReturns500()
}//end class
