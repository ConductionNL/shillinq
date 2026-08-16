<?php

/**
 * Unit tests for PeriodCloseController.
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PeriodCloseController;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\PeriodCloseException;
use OCA\Shillinq\Service\PeriodCloseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the period-close API controller: input validation, auth resolution,
 * the read endpoint (REQ-PC-005), and the service-error → HTTP-status mapping
 * (REQ-PC-008 forbidden, ADR-005 no-stack-trace errors).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodCloseControllerTest extends TestCase {

	/**
	 * Mock request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock lifecycle service.
	 *
	 * @var PeriodCloseService&MockObject
	 */
	private PeriodCloseService&MockObject $service;

	/**
	 * Mock assistant service.
	 *
	 * @var PeriodCloseAssistantService&MockObject
	 */
	private PeriodCloseAssistantService&MockObject $assistant;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var PeriodCloseController
	 */
	private PeriodCloseController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PeriodCloseService::class);
		$this->assistant = $this->createMock(PeriodCloseAssistantService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new PeriodCloseController(
			request: $this->request,
			periodCloseService: $this->service,
			assistantService: $this->assistant,
			userSession: $this->userSession,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Stub the request administration_id (and other params) the controller reads.
	 *
	 * @param array<string,string> $params Param map (param => value).
	 *
	 * @return void
	 */
	private function stubParams(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return ($params[$key] ?? $default);
			}
		);

	}//end stubParams()

	/**
	 * Stub the acting user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function stubUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end stubUser()

	/**
	 * The show() endpoint returns the record plus freshly-computed AI flags (REQ-PC-005).
	 *
	 * @return void
	 */
	public function testShowReturnsRecordWithFlags(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->stubUser('alice');
		$this->service->method('getPeriodForClose')->willReturn(
			['periodId' => '2026-01', 'endDate' => '2026-01-31', 'state' => 'open']
		);
		$this->assistant->method('analyse')->willReturn(
			[['id' => 'flag-ap', 'severity' => 'warning', 'category' => 'ap', 'message' => 'x', 'detectedAt' => 'now']]
		);

		$response = $this->controller->show('2026-01');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('2026-01', $data['data']['periodId']);
		self::assertCount(1, $data['data']['aiFlags']);

	}//end testShowReturnsRecordWithFlags()

	/**
	 * The show() endpoint returns 400 when administration_id is missing (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testShowRequiresAdministrationId(): void {
		$this->stubParams([]);
		$this->stubUser('alice');
		$response = $this->controller->show('2026-01');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testShowRequiresAdministrationId()

	/**
	 * The show() endpoint rejects a malformed period id with 400 (ADR-005 input validation).
	 *
	 * @return void
	 */
	public function testShowRejectsMalformedPeriodId(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->stubUser('alice');
		$response = $this->controller->show('bad id with spaces/../');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testShowRejectsMalformedPeriodId()

	/**
	 * The show() endpoint returns 404 when the period is not found in scope (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testShowReturnsNotFound(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->stubUser('alice');
		$this->service->method('getPeriodForClose')->willReturn(null);

		$response = $this->controller->show('2026-01');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testShowReturnsNotFound()

	/**
	 * The close() endpoint maps a forbidden service error to HTTP 403 (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testCloseMapsForbiddenTo403(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->stubUser('bob@org.nl');
		$this->service->method('closePeriod')->willThrowException(
			new PeriodCloseException(message: 'denied', status: PeriodCloseService::ERR_FORBIDDEN)
		);

		$response = $this->controller->close('2026-01');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('denied', $response->getData()['error']);

	}//end testCloseMapsForbiddenTo403()

	/**
	 * The close() endpoint maps a validation service error to HTTP 422 (REQ-PC-002).
	 *
	 * @return void
	 */
	public function testCloseMapsValidationTo422(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->stubUser('alice@org.nl');
		$this->service->method('closePeriod')->willThrowException(
			new PeriodCloseException(message: 'checklist', status: PeriodCloseService::ERR_VALIDATION)
		);

		$response = $this->controller->close('2026-01');
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testCloseMapsValidationTo422()

	/**
	 * A transition without a session is rejected with 401 (REQ-PC-008).
	 *
	 * @return void
	 */
	public function testTransitionRequiresAuthenticatedUser(): void {
		$this->stubParams(['administration_id' => 'adm-1']);
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->lockAudit('2026-01');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTransitionRequiresAuthenticatedUser()

	/**
	 * The reopen() endpoint forwards the close reason and returns the record (REQ-PC-006).
	 *
	 * @return void
	 */
	public function testReopenForwardsCloseReason(): void {
		$this->stubParams(['administration_id' => 'adm-1', 'closeReason' => 'Posted correction']);
		$this->stubUser('alice@org.nl');
		$this->service->expects(self::once())
			->method('reopenPeriod')
			->with('2026-01', 'adm-1', 'Posted correction', 'alice@org.nl')
			->willReturn(['periodId' => '2026-01', 'state' => 'open']);

		$response = $this->controller->reopen('2026-01');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('open', $response->getData()['data']['state']);

	}//end testReopenForwardsCloseReason()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
