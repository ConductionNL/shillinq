<?php

/**
 * Purchase Order Approval Controller
 *
 * Slice 11 of the bookkeeping-purchase-order-3way chain (REQ-PO3W-010).
 * Exposes the single approval-decision endpoint over
 * {@see PurchaseOrderApprovalService::recordApprovalDecision()}. The
 * authenticated user is stamped server-side as the approver — the
 * request body's user fields are ignored (ADR-005).
 *
 *   POST /api/purchase-orders/{id}/approval-decision
 *        Body: administrationId, decision, comment (optional).
 *        → 200 with the updated PurchaseOrder; 400 on validation;
 *          401 anonymous; 404 cross-tenant or missing PO; 409 when the
 *          PO is not waiting for approval; 500 without stack trace.
 *
 * Every endpoint is #[NoAdminRequired] (admin posture is the NC
 * SecurityMiddleware default — controllers without the attribute are
 * admin-only, see [[nc-security-defaults]]); a manual user-session
 * guard rejects anonymous callers and the administration scope is
 * validated via AdministrationContextService so cross-tenant access is
 * masked as 404 (ADR-005 IDOR-safe).
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderApprovalService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Server-authoritative approval-decision endpoint.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
class PurchaseOrderApprovalController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PurchaseOrderApprovalService $approvalService The approval service.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User-session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to
	 *                                client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PurchaseOrderApprovalService $approvalService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Record the authenticated user's decision on a PurchaseOrder's
	 * approval chain.
	 *
	 * POST /api/purchase-orders/{id}/approval-decision
	 * Body: administrationId, decision, comment (optional).
	 *
	 * @param string $id The PurchaseOrder id (URL path param).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	#[NoAdminRequired]
	public function decide(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$approval = new ApprovalDecision(request: $this->request, id: (string)$id);
		$refusal = $this->administrationRefusal(approval: $approval) ?? $this->decisionRefusal(approval: $approval);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->recorded(approval: $approval);
	}//end decide()

	/**
	 * The 400 without a well-formed administration, or the masked 404 when the
	 * caller may not access it.
	 *
	 * @param ApprovalDecision $approval The decision.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function administrationRefusal(ApprovalDecision $approval): ?JSONResponse {
		if ($approval->administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $approval->administrationId) === false) {
			return new JSONResponse(['error' => 'Purchase order not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The 400 for a malformed purchase order id, then for a missing decision.
	 *
	 * @param ApprovalDecision $approval The decision.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function decisionRefusal(ApprovalDecision $approval): ?JSONResponse {
		if ($approval->wellFormedOrder() === false) {
			return new JSONResponse(['error' => 'Purchase order id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($approval->decision === '') {
			return new JSONResponse(['error' => 'decision is required'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end decisionRefusal()

	/**
	 * Record the decision: 200 with the purchase order, the mapped refusal, or a logged 500.
	 *
	 * @param ApprovalDecision $approval The validated decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	private function recorded(ApprovalDecision $approval): JSONResponse {
		try {
			$purchaseOrder = $this->approvalService->recordApprovalDecision(
				administrationId: $approval->administrationId,
				purchaseOrderId:  $approval->purchaseOrderId,
				decision:         $approval->decision,
				comment:          $approval->comment
			);
		} catch (\RuntimeException $e) {
			return $this->mapRuntimeException(exception: $e);
		} catch (\Throwable $e) {
			return $this->recordFailed(approval: $approval, e: $e);
		}

		return new JSONResponse($purchaseOrder, Http::STATUS_OK);
	}//end recorded()

	/**
	 * Log a failed decision and answer 500 without a stack trace.
	 *
	 * @param ApprovalDecision $approval The decision.
	 * @param \Throwable       $e        The failure.
	 *
	 * @return JSONResponse
	 */
	private function recordFailed(ApprovalDecision $approval, \Throwable $e): JSONResponse {
		$this->logger->error(
			'PurchaseOrderApprovalController: failed to record approval decision',
			[
				'administrationId' => $approval->administrationId,
				'purchaseOrderId' => $approval->purchaseOrderId,
				'exception' => $e->getMessage(),
			]
		);
		return new JSONResponse(['error' => 'Could not record approval decision'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end recordFailed()

	/**
	 * Map a service-level RuntimeException to a JSONResponse.
	 *
	 * @param \RuntimeException $exception The exception.
	 *
	 * @return JSONResponse
	 */
	private function mapRuntimeException(\RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		if (str_contains($message, 'not found') === true) {
			return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
		}

		if (str_contains($message, 'not pending') === true
			|| str_contains($message, 'fully signed') === true
		) {
			return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
	}//end mapRuntimeException()
}//end class
