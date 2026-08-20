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
use OCP\IL10N;
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
	 * Mock IL10N (ADR-050 localized error messages).
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N&MockObject $l10n;

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
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []): string => $text
		);

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
			l10n: $this->l10n,
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
	 * A budget refusal from BudgetBlocker surfaces as 409 Conflict. Per ADR-050 /
	 * REQ-003 the service's own exception text is NEVER placed in the response
	 * body — the client gets a stable slug + generic localized message, and the
	 * real reason is only visible server-side via the logger.
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
		self::assertSame('requisition-invalid-state', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'Budget exceeded for programme P1',
			(string)json_encode($response->getData())
		);

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

	/**
	 * Creating a requisition succeeds for a member of the target administration
	 * (positive direction — security-endpoint-guards re-verification, verdict
	 * ALREADY-GUARDED: the controller already checked canAccess() before this
	 * change).
	 *
	 * @return void
	 */
	public function testCreateByMemberOfTargetAdministrationSucceeds(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'programme' => 'P1',
				'financialYear' => 2026,
				'neededByDate' => '2026-09-01',
				'justification' => 'Replace laptops',
				'kind' => 'goods',
				'lines' => [
					['description' => 'Laptop', 'quantity' => 1, 'unitPrice' => 1000, 'glAccountSuggestion' => '4000'],
				],
			]
		);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->expects($this->once())
			->method('createRequisition')
			->with('adm-1', $this->anything())
			->willReturn(['id' => 'req-1', 'administrationId' => 'adm-1', 'statusCode' => 'draft']);

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('req-1', $response->getData()['id']);

	}//end testCreateByMemberOfTargetAdministrationSucceeds()

	/**
	 * NEGATIVE CONTROL: creating a requisition against an administration the
	 * caller is not a member of is masked as 404, and the service is never
	 * called (security-endpoint-guards, REQ-001).
	 *
	 * @return void
	 */
	public function testCreateForForeignAdministrationIsForbidden(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->requisitionService->expects($this->never())->method('createRequisition');

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCreateForForeignAdministrationIsForbidden()

	/**
	 * A validation refusal from the service layer (missing/invalid field) never
	 * places the exception's own text in the response body (ADR-050 / REQ-003).
	 *
	 * @return void
	 */
	public function testCreateValidationFailureDoesNotLeakExceptionText(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->method('createRequisition')
			->willThrowException(new \RuntimeException('programma is required'));

		$response = $this->controller->create();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('requisition-create-invalid', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'programma is required',
			(string)json_encode($response->getData())
		);

	}//end testCreateValidationFailureDoesNotLeakExceptionText()

	/**
	 * Submitting a draft requisition succeeds for a member of its administration
	 * (positive direction — security-endpoint-guards re-verification).
	 *
	 * @return void
	 */
	public function testSubmitByOwnAdministrationMemberSucceeds(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->requisitionService->expects($this->once())
			->method('submitRequisition')
			->with('adm-1', 'req-1')
			->willReturn(['id' => 'req-1', 'statusCode' => 'submitted']);

		$response = $this->controller->submit('req-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('submitted', $response->getData()['statusCode']);

	}//end testSubmitByOwnAdministrationMemberSucceeds()

	/**
	 * NEGATIVE CONTROL: submitting inside another tenant's administration is
	 * masked as 404 and never reaches the service (security-endpoint-guards).
	 *
	 * @return void
	 */
	public function testSubmitForForeignAdministrationIsForbidden(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->requisitionService->expects($this->never())->method('submitRequisition');

		$response = $this->controller->submit('req-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testSubmitForForeignAdministrationIsForbidden()

	/**
	 * Converting an approved requisition succeeds for a member of its
	 * administration (positive direction — security-endpoint-guards
	 * re-verification).
	 *
	 * @return void
	 */
	public function testConvertByOwnAdministrationMemberSucceeds(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->administrationContext->method('canAccess')->willReturn(true);
		$this->conversionService->expects($this->once())
			->method('convertToPurchaseOrder')
			->with('adm-1', 'req-1')
			->willReturn(
				[
					'requisition' => ['id' => 'req-1', 'statusCode' => 'converted'],
					'purchaseOrder' => ['id' => 'po-1'],
				]
			);

		$response = $this->controller->convert('req-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('po-1', $response->getData()['purchaseOrder']['id']);

	}//end testConvertByOwnAdministrationMemberSucceeds()

	/**
	 * NEGATIVE CONTROL: converting inside another tenant's administration is
	 * masked as 404 and never reaches the conversion service
	 * (security-endpoint-guards).
	 *
	 * @return void
	 */
	public function testConvertForForeignAdministrationIsForbidden(): void {
		$this->withParams(['administrationId' => 'adm-other']);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->conversionService->expects($this->never())->method('convertToPurchaseOrder');

		$response = $this->controller->convert('req-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testConvertForForeignAdministrationIsForbidden()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
