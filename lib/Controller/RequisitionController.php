<?php

/**
 * Requisition Controller
 *
 * Server-authoritative API for the purchase requisition (aanvraag) sub-ledger
 * (REQ-REQ-001..005). Exposes create / submit / approve / reject / convert
 * endpoints over RequisitionService and RequisitionConversionService. Every
 * endpoint is #[NoAdminRequired] (admin posture is the NC SecurityMiddleware
 * default — controllers without the attribute are admin-only, see
 * [[nc-security-defaults]]); a manual user-session guard rejects anonymous
 * callers and the administration scope is validated via
 * AdministrationContextService so cross-tenant access is masked as 404
 * (ADR-005 IDOR-safe). No stack traces are returned to the client.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\RequisitionConversionService;
use OCA\Shillinq\Service\RequisitionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Purchase-requisition REST endpoints (create / submit / approve / reject / convert).
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 */
class RequisitionController extends Controller {
	/**
	 * Short-slug identifier pattern shared by every scope parameter (path/query).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param RequisitionService $requisitionService Create/submit/approve/reject.
	 * @param RequisitionConversionService $conversionService Convert-to-PO.
	 * @param AdministrationContextServiceInterface $administrationContext IDOR + tenant scope.
	 * @param IUserSession $userSession User session guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param IL10N $l10n Localized user-facing error messages (ADR-050).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly RequisitionService $requisitionService,
		private readonly RequisitionConversionService $conversionService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Create a draft requisition with its lines.
	 *
	 * POST /api/requisitions
	 * Body: administrationId, programma, boekjaar, neededByDate, justification,
	 *       soort, preferredSupplierId (optional),
	 *       lines: [{ description, quantity, unitPrice, glAccountSuggestion, lineNumber? }, ...].
	 *
	 * @return JSONResponse 201 with the persisted Requisition; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant; 500 without stack trace.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$administrationId = $this->newRequisitionAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$requisition = $this->requisitionService->createRequisition(administrationId: $administrationId, payload: $this->createPayload());
		} catch (\RuntimeException $e) {
			return $this->createRejected(administrationId: $administrationId, e: $e);
		} catch (\Throwable $e) {
			return $this->failed(action: 'create', context: ['administrationId' => $administrationId, 'exception' => $e]);
		}

		return new JSONResponse($requisition, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Submit a draft requisition for approval.
	 *
	 * POST /api/requisitions/{id}/submit
	 * Body: administrationId.
	 *
	 * @param string $id The requisition id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated Requisition; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant/missing; 409 on
	 *                      invalid state; 500 without stack trace.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function submit(string $id): JSONResponse {
		$administrationId = $this->requisitionAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$requisition = $this->requisitionService->submitRequisition(
				administrationId: $administrationId,
				requisitionId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'submit', context: $this->failureContext(id: $id, e: $e));
		}

		return new JSONResponse($requisition, Http::STATUS_OK);
	}//end submit()

	/**
	 * Approve a submitted requisition.
	 *
	 * POST /api/requisitions/{id}/approve
	 * Body: administrationId.
	 * Gated server-side by BudgetBlocker::canCommit (budget availability or
	 * override-mandate) — the Vue layer never grants the transition.
	 *
	 * @param string $id The requisition id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated Requisition; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant/missing; 409 when
	 *                      not submitted or budget insufficient; 500 without stack trace.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$administrationId = $this->requisitionAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$requisition = $this->requisitionService->approveRequisition(
				administrationId: $administrationId, requisitionId: $id, approverId: $this->currentUid()
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'approve', context: $this->failureContext(id: $id, e: $e));
		}

		return new JSONResponse($requisition, Http::STATUS_OK);
	}//end approve()

	/**
	 * Reject a submitted requisition.
	 *
	 * POST /api/requisitions/{id}/reject
	 * Body: administrationId, reason.
	 *
	 * @param string $id The requisition id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated Requisition; 400 on validation;
	 *                      401 anonymous; 404 on cross-tenant/missing; 409 on
	 *                      invalid state; 500 without stack trace.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$administrationId = $this->requisitionAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$requisition = $this->requisitionService->rejectRequisition(
				administrationId: $administrationId, requisitionId: $id,
				rejectorId: $this->currentUid(), reason: $this->trimmedParam(name: 'reason')
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'reject', context: $this->failureContext(id: $id, e: $e));
		}

		return new JSONResponse($requisition, Http::STATUS_OK);
	}//end reject()

	/**
	 * Convert an approved requisition into a purchase order.
	 *
	 * POST /api/requisitions/{id}/convert
	 * Body: administrationId.
	 * Refused when the requisition is not approved (409) — the server, not the
	 * Vue layer, enforces this precondition.
	 *
	 * @param string $id The requisition id (path parameter).
	 *
	 * @return JSONResponse 200 with {requisition, purchaseOrder}; 400 on
	 *                      validation; 401 anonymous; 404 on cross-tenant/missing;
	 *                      409 when not approved / no supplier / no lines;
	 *                      500 without stack trace.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	#[NoAdminRequired]
	public function convert(string $id): JSONResponse {
		$administrationId = $this->requisitionAdministration(id: $id);
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		try {
			$result = $this->conversionService->convertToPurchaseOrder(
				administrationId: $administrationId,
				requisitionId: $id
			);
		} catch (\Throwable $e) {
			return $this->failed(action: 'convert', context: $this->failureContext(id: $id, e: $e));
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end convert()

	/**
	 * Resolve the administration for a new requisition: user check, then the
	 * administration scope (cross-tenant masked as "Administration not found").
	 *
	 * @return string|JSONResponse The administration id, or the refusal.
	 */
	private function newRequisitionAdministration(): string|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->scopedAdministration(notFound: 'Administration not found');
	}//end newRequisitionAdministration()

	/**
	 * Resolve the administration for an existing requisition: user check, the
	 * id pattern, then the administration scope (masked as "Requisition not found").
	 *
	 * @param string $id The requisition id (path parameter).
	 *
	 * @return string|JSONResponse The administration id, or the refusal.
	 */
	private function requisitionAdministration(string $id): string|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return new JSONResponse(['error' => 'Invalid requisition id'], Http::STATUS_BAD_REQUEST);
		}

		return $this->scopedAdministration(notFound: 'Requisition not found');
	}//end requisitionAdministration()

	/**
	 * Read the administrationId scope parameter and check the caller may access it.
	 *
	 * @param string $notFound The error text for an inaccessible administration.
	 *
	 * @return string|JSONResponse The administration id, or the refusal.
	 */
	private function scopedAdministration(string $notFound): string|JSONResponse {
		$administrationId = $this->scopeParam(name: 'administrationId');
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => $notFound], Http::STATUS_NOT_FOUND);
		}

		return $administrationId;
	}//end scopedAdministration()

	/**
	 * Read the create payload from the request body.
	 *
	 * @return array<string, mixed> The trimmed/cast create payload.
	 */
	private function createPayload(): array {
		return [
			'programme' => $this->trimmedParam(name: 'programme'),
			'financialYear' => (int)$this->request->getParam('financialYear', 0),
			'neededByDate' => $this->trimmedParam(name: 'neededByDate'),
			'justification' => $this->trimmedParam(name: 'justification'),
			'kind' => $this->trimmedParam(name: 'kind'),
			'preferredSupplierId' => $this->trimmedParam(name: 'preferredSupplierId'),
			'lines' => (array)$this->request->getParam('lines', []),
		];
	}//end createPayload()

	/**
	 * Read a request parameter as a trimmed string ('' when absent).
	 *
	 * @param string $name Parameter name.
	 *
	 * @return string The trimmed value.
	 */
	private function trimmedParam(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}//end trimmedParam()

	/**
	 * Answer a validation refusal from the create service call.
	 *
	 * The exception's own text is never placed in the response body (ADR-050 /
	 * REQ-003); it is logged server-side and the client gets a stable slug plus
	 * localized text.
	 *
	 * @param string            $administrationId The administration id.
	 * @param \RuntimeException $e                The service refusal.
	 *
	 * @return JSONResponse 400 with the localized message.
	 */
	private function createRejected(string $administrationId, \RuntimeException $e): JSONResponse {
		$this->logger->error(
			'RequisitionController.create rejected',
			['administrationId' => $administrationId, 'exception' => $e]
		);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Requisition could not be created; check the required fields'),
				'error' => 'requisition-create-invalid',
			],
			Http::STATUS_BAD_REQUEST,
		);
	}//end createRejected()

	/**
	 * Build the log context of a failed action on an existing requisition.
	 *
	 * @param string     $id The requisition id.
	 * @param \Throwable $e  The failure.
	 *
	 * @return array<string, mixed> requisitionId, administrationId and exception.
	 */
	private function failureContext(string $id, \Throwable $e): array {
		return [
			'requisitionId' => $id,
			'administrationId' => $this->scopeParam(name: 'administrationId'),
			'exception' => $e,
		];
	}//end failureContext()

	/**
	 * Answer a failed action: service refusals map via mapRuntimeError, any
	 * other failure is logged with its message and answered 500.
	 *
	 * @param string               $action  The action key in FAILURES.
	 * @param array<string, mixed> $context The log context; 'exception' holds the failure.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $action, array $context): JSONResponse {
		$e = $context['exception'];
		if ($e instanceof \RuntimeException) {
			return $this->mapRuntimeError(e: $e);
		}

		$context['exception'] = $e->getMessage();
		$this->logger->error('RequisitionController: failed to '.$action.' requisition', $context);
		return new JSONResponse(['error' => 'Could not '.$action.' requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failed()

	/**
	 * The UID of the logged-in user (the access step already refused anonymous callers).
	 *
	 * @return string The user id.
	 */
	private function currentUid(): string {
		return $this->userSession->getUser()->getUID();
	}//end currentUid()

	/**
	 * Map a RuntimeException from the service layer to the appropriate HTTP
	 * status: "not found" messages -> 404, everything else (invalid state,
	 * budget exceeded, missing supplier/lines) -> 409 Conflict.
	 *
	 * The exception's own message text is used only to pick the status code
	 * (a closed set of curated strings this app's own service layer throws)
	 * — it is never placed in the response body itself (ADR-050 / REQ-003).
	 * The real message is logged server-side; the client gets a stable slug
	 * plus a localized, generic message.
	 *
	 * @param \RuntimeException $e The exception to map.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 */
	private function mapRuntimeError(\RuntimeException $e): JSONResponse {
		$this->logger->error('RequisitionController: requisition action rejected', ['exception' => $e]);

		if (str_contains($e->getMessage(), 'not found') === true) {
			return new JSONResponse(
				['message' => $this->l10n->t('Requisition not found'), 'error' => 'requisition-not-found'],
				Http::STATUS_NOT_FOUND,
			);
		}

		$message = $this->l10n->t('Requisition is not in a state that allows this action');
		return new JSONResponse(['message' => $message, 'error' => 'requisition-invalid-state'], Http::STATUS_CONFLICT);
	}//end mapRuntimeError()

	/**
	 * Read and validate a scope parameter, returning '' when blank/malformed.
	 *
	 * @param string $name Parameter name (body for POST / query for GET).
	 *
	 * @return string The validated value or '' (blank/malformed).
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()
}//end class
