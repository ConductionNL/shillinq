<?php

/**
 * Unit tests for RequisitionController.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\RequisitionController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RequisitionConversionService;
use OCA\Shillinq\Service\RequisitionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the approve / reject transitions of the purchase-requisition API.
 *
 * Both are server-authoritative writes: the acting user id comes from the
 * session (never the request body), the administration scope is checked against
 * the caller's memberships and masked as 404 when foreign (ADR-005), a service
 * refusal maps to 409/404 by message, and an unexpected failure returns a
 * generic 500 that leaks no stack trace.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RequisitionControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock RequisitionService (create / submit / approve / reject).
	 *
	 * @var RequisitionService&MockObject
	 */
	private RequisitionService&MockObject $requisitionService;

	/**
	 * Mock RequisitionConversionService (convert-to-PO).
	 *
	 * @var RequisitionConversionService&MockObject
	 */
	private RequisitionConversionService&MockObject $conversionService;

	/**
	 * Mock AdministrationContextService (IDOR + tenant scope).
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $administrationContext;

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
	 * The user the session reports; null models an anonymous caller.
	 *
	 * @var IUser|null
	 */
	private ?IUser $currentUser = null;

	/**
	 * The controller under test.
	 *
	 * @var RequisitionController
	 */
	private RequisitionController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->requisitionService = $this->createMock(RequisitionService::class);
		$this->conversionService = $this->createMock(RequisitionConversionService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->currentUser = $user;

		$this->userSession->method('getUser')->willReturnCallback(
			function (): ?IUser {
				return $this->currentUser;
			}
		);

		$this->controller = new RequisitionController(
			request: $this->request,
			requisitionService: $this->requisitionService,
			conversionService: $this->conversionService,
			administrationContext: $this->administrationContext,
			userSession: $this->userSession,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Configure request params from a key => value map.
	 *
	 * @param array<string,mixed> $map Param map.
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
	 * Approving a submitted requisition returns 200 with the updated record, and
	 * the approver id is taken from the SESSION, not the request body.
	 *
	 * @return void
	 */
	public function testApproveReturns200AndUsesSessionApprover(): void {
		$this->withParams(['administrationId' => 'adm-1', 'approverId' => 'mallory']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->requisitionService->expects($this->once())
			->method('approveRequisition')
			->willReturnCallback(
				static function (string $administrationId, string $requisitionId, string $approverId) use (&$seen): array {
					$seen = [$administrationId, $requisitionId, $approverId];
					return [
						'id' => $requisitionId,
						'status' => 'approved',
						'approvedBy' => $approverId,
					];
				}
			);

		$response = $this->controller->approve('req-7');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'req-7', 'alice'], $seen);
		self::assertSame('approved', $response->getData()['status']);
		self::assertSame('alice', $response->getData()['approvedBy']);

	}//end testApproveReturns200AndUsesSessionApprover()

	/**
	 * An anonymous approve attempt is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testApproveRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->requisitionService->expects($this->never())->method('approveRequisition');

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Not logged in'], $response->getData());

	}//end testApproveRejectsAnonymousCaller()

	/**
	 * A path-traversal requisition id is rejected with 400 before any lookup.
	 *
	 * @return void
	 */
	public function testApproveRejectsMalformedIdWith400(): void {
		$this->requisitionService->expects($this->never())->method('approveRequisition');

		$response = $this->controller->approve('../../etc/passwd');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'Invalid requisition id'], $response->getData());

	}//end testApproveRejectsMalformedIdWith400()

	/**
	 * A missing administrationId is a 400, not a silent instance-wide approval.
	 *
	 * @return void
	 */
	public function testApproveRequiresAdministrationId(): void {
		$this->withParams([]);
		$this->requisitionService->expects($this->never())->method('approveRequisition');

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'administrationId is required'], $response->getData());

	}//end testApproveRequiresAdministrationId()

	/**
	 * Approving inside another tenant's administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testApproveForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->requisitionService->expects($this->never())->method('approveRequisition');

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Requisition not found'], $response->getData());

	}//end testApproveForeignAdministrationReturns404()

	/**
	 * A budget refusal from BudgetBlocker surfaces as 409 Conflict with the
	 * service's own message — the transition is denied server-side.
	 *
	 * @return void
	 */
	public function testApproveBudgetRefusalReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->method('approveRequisition')
			->willThrowException(new \RuntimeException('Budget exceeded for programme P1'));

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['error' => 'Budget exceeded for programme P1'], $response->getData());

	}//end testApproveBudgetRefusalReturns409()

	/**
	 * A "not found" service refusal maps to 404, not 409.
	 *
	 * @return void
	 */
	public function testApproveUnknownRequisitionReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->method('approveRequisition')
			->willThrowException(new \RuntimeException('Requisition not found'));

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testApproveUnknownRequisitionReturns404()

	/**
	 * An unexpected failure is logged server-side and returns a generic 500 that
	 * contains neither the exception message nor a stack trace.
	 *
	 * @return void
	 */
	public function testApproveUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->method('approveRequisition')
			->willThrowException(new \LogicException('SQLSTATE[42S02] table shillinq_req missing'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->approve('req-7');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not approve requisition'], $response->getData());
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testApproveUnexpectedFailureReturns500WithoutStackTrace()

	/**
	 * Rejecting returns 200 and forwards the session user as the rejector plus
	 * the trimmed reason from the body.
	 *
	 * @return void
	 */
	public function testRejectReturns200WithSessionRejectorAndReason(): void {
		$this->withParams(['administrationId' => 'adm-1', 'reason' => '  over budget  ']);
		$this->administrationContext->method('canAccess')->willReturn(true);

		$seen = [];
		$this->requisitionService->expects($this->once())
			->method('rejectRequisition')
			->willReturnCallback(
				static function (string $administrationId, string $requisitionId, string $rejectorId, string $reason) use (&$seen): array {
					$seen = [$administrationId, $requisitionId, $rejectorId, $reason];
					return ['id' => $requisitionId, 'status' => 'rejected', 'rejectionReason' => $reason];
				}
			);

		$response = $this->controller->reject('req-7');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['adm-1', 'req-7', 'alice', 'over budget'], $seen);
		self::assertSame('rejected', $response->getData()['status']);

	}//end testRejectReturns200WithSessionRejectorAndReason()

	/**
	 * An anonymous reject attempt is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testRejectRejectsAnonymousCaller(): void {
		$this->currentUser = null;
		$this->requisitionService->expects($this->never())->method('rejectRequisition');

		$response = $this->controller->reject('req-7');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRejectRejectsAnonymousCaller()

	/**
	 * Rejecting inside another tenant's administration is masked as 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testRejectForeignAdministrationReturns404(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->requisitionService->expects($this->never())->method('rejectRequisition');

		$response = $this->controller->reject('req-7');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRejectForeignAdministrationReturns404()

	/**
	 * Rejecting a requisition that is not in a rejectable state yields 409.
	 *
	 * @return void
	 */
	public function testRejectInvalidStateReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1', 'reason' => 'duplicate']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->method('rejectRequisition')
			->willThrowException(new \RuntimeException('Requisition must be submitted to be rejected'));

		$response = $this->controller->reject('req-7');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testRejectInvalidStateReturns409()

	/**
	 * A malformed requisition id is rejected with 400 before any lookup.
	 *
	 * @return void
	 */
	public function testRejectRejectsMalformedIdWith400(): void {
		$this->requisitionService->expects($this->never())->method('rejectRequisition');

		$response = $this->controller->reject('req 7; DROP TABLE');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testRejectRejectsMalformedIdWith400()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
