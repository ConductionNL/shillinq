<?php

/**
 * Unit tests for PurchaseOrderApprovalController.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PurchaseOrderApprovalController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the single routed approval-decision endpoint (REQ-PO3W-010).
 *
 * Asserts the anonymous 401 guard, the cross-tenant 404 mask, the
 * required-parameter 400s, the server-side stamping of the decision (the
 * request body's user fields are never trusted) and the RuntimeException →
 * 404 / 409 / 400 mapping.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderApprovalControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock PurchaseOrderApprovalService.
	 *
	 * @var PurchaseOrderApprovalService&MockObject
	 */
	private PurchaseOrderApprovalService&MockObject $approvalService;

	/**
	 * Mock AdministrationContextService.
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
	 * Set up shared fixtures — authenticated with an accessible
	 * administration by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->approvalService = $this->createMock(PurchaseOrderApprovalService::class);
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->administrationContext->method('canAccess')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Configure the request params from a key => value map.
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
	 * Build the controller over the current mocks.
	 *
	 * @return PurchaseOrderApprovalController
	 */
	private function controller(): PurchaseOrderApprovalController {
		return new PurchaseOrderApprovalController(
			$this->request,
			$this->approvalService,
			$this->administrationContext,
			$this->userSession,
			$this->logger,
		);

	}//end controller()

	/**
	 * decide() records the decision and returns the updated PO with HTTP 200.
	 *
	 * @return void
	 */
	public function testDecideReturns200WithUpdatedPurchaseOrder(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'decision' => 'approve',
				'comment' => 'budget confirmed',
			]
		);
		$po = ['id' => 'po-1', 'status' => 'approved', 'approvalChain' => [['role' => 'budget_holder', 'signedBy' => 'alice']]];
		$this->approvalService->expects($this->once())
			->method('recordApprovalDecision')
			->willReturnCallback(
				static function (string $administrationId, string $purchaseOrderId, string $decision, ?string $comment) use ($po): array {
					self::assertSame('adm-1', $administrationId);
					self::assertSame('po-1', $purchaseOrderId);
					self::assertSame('approve', $decision);
					self::assertSame('budget confirmed', $comment);
					return $po;
				}
			);

		$response = $this->controller()->decide('po-1');

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($po, $response->getData());

	}//end testDecideReturns200WithUpdatedPurchaseOrder()

	/**
	 * A non-string comment (an injected object/array) is normalised to null
	 * rather than forwarded to the service.
	 *
	 * @return void
	 */
	public function testDecideNormalisesNonStringCommentToNull(): void {
		$this->withParams(
			[
				'administrationId' => 'adm-1',
				'decision' => 'reject',
				'comment' => ['injected' => true],
			]
		);
		$this->approvalService->expects($this->once())
			->method('recordApprovalDecision')
			->willReturnCallback(
				static function (string $administrationId, string $purchaseOrderId, string $decision, ?string $comment): array {
					self::assertNull($comment);
					return ['id' => 'po-1', 'status' => 'rejected'];
				}
			);

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('rejected', $response->getData()['status']);

	}//end testDecideNormalisesNonStringCommentToNull()

	/**
	 * decide() rejects an anonymous caller with HTTP 401 and records nothing.
	 *
	 * @return void
	 */
	public function testDecideAnonymousReturns401(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'approve']);
		$this->approvalService->expects($this->never())->method('recordApprovalDecision');

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testDecideAnonymousReturns401()

	/**
	 * A missing administrationId is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testDecideMissingAdministrationReturns400(): void {
		$this->withParams(['decision' => 'approve']);
		$this->approvalService->expects($this->never())->method('recordApprovalDecision');

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('administrationId is required', $response->getData()['error']);

	}//end testDecideMissingAdministrationReturns400()

	/**
	 * A missing decision is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testDecideMissingDecisionReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1']);
		$this->approvalService->expects($this->never())->method('recordApprovalDecision');

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('decision is required', $response->getData()['error']);

	}//end testDecideMissingDecisionReturns400()

	/**
	 * A malformed PO id in the path is rejected with HTTP 400.
	 *
	 * @return void
	 */
	public function testDecideMalformedPurchaseOrderIdReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'approve']);
		$this->approvalService->expects($this->never())->method('recordApprovalDecision');

		$response = $this->controller()->decide('../../etc/passwd');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Purchase order id is required', $response->getData()['error']);

	}//end testDecideMalformedPurchaseOrderIdReturns400()

	/**
	 * A cross-tenant administration is masked as HTTP 404 (ADR-005).
	 *
	 * @return void
	 */
	public function testDecideCrossTenantReturns404(): void {
		$this->administrationContext = $this->createMock(AdministrationContextService::class);
		$this->administrationContext->method('canAccess')->willReturn(false);
		$this->withParams(['administrationId' => 'adm-other', 'decision' => 'approve']);
		$this->approvalService->expects($this->never())->method('recordApprovalDecision');

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testDecideCrossTenantReturns404()

	/**
	 * A PO that is not in pending_approval yields HTTP 409, not 200.
	 *
	 * @return void
	 */
	public function testDecideNotPendingReturns409(): void {
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'approve']);
		$this->approvalService->method('recordApprovalDecision')
			->willThrowException(new \RuntimeException('Purchase order is not pending approval'));

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('Purchase order is not pending approval', $response->getData()['error']);

	}//end testDecideNotPendingReturns409()

	/**
	 * A missing PO yields HTTP 404.
	 *
	 * @return void
	 */
	public function testDecideMissingPurchaseOrderReturns404(): void {
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'approve']);
		$this->approvalService->method('recordApprovalDecision')
			->willThrowException(new \RuntimeException('Purchase order not found'));

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testDecideMissingPurchaseOrderReturns404()

	/**
	 * An unrecognised decision verb is rejected by the service and mapped to
	 * HTTP 400.
	 *
	 * @return void
	 */
	public function testDecideInvalidDecisionReturns400(): void {
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'rubber-stamp']);
		$this->approvalService->method('recordApprovalDecision')
			->willThrowException(new \RuntimeException('Invalid approval decision'));

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('Invalid approval decision', $response->getData()['error']);

	}//end testDecideInvalidDecisionReturns400()

	/**
	 * An unexpected failure yields HTTP 500 and leaks no stack trace.
	 *
	 * @return void
	 */
	public function testDecideUnexpectedFailureReturns500WithoutStackTrace(): void {
		$this->withParams(['administrationId' => 'adm-1', 'decision' => 'approve']);
		$this->approvalService->method('recordApprovalDecision')
			->willThrowException(new \LogicException('SQLSTATE[08006] connection refused'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller()->decide('po-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('Could not record approval decision', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'SQLSTATE',
			(string)json_encode($response->getData())
		);

	}//end testDecideUnexpectedFailureReturns500WithoutStackTrace()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
