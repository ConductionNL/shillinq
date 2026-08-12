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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RequisitionConversionService;
use OCA\Shillinq\Service\RequisitionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Purchase-requisition REST endpoints (create / submit / approve / reject / convert).
 *
 * @spec openspec/specs/purchase-requisition/spec.md
 */
class RequisitionController extends Controller
{
    /**
     * Short-slug identifier pattern shared by every scope parameter (path/query).
     *
     * @var string
     */
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    /**
     * Constructor.
     *
     * @param IRequest                     $request               The request object.
     * @param RequisitionService           $requisitionService    Create/submit/approve/reject.
     * @param RequisitionConversionService $conversionService     Convert-to-PO.
     * @param AdministrationContextService $administrationContext IDOR + tenant scope.
     * @param IUserSession                 $userSession           User session guard.
     * @param LoggerInterface              $logger                Logger (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly RequisitionService $requisitionService,
        private readonly RequisitionConversionService $conversionService,
        private readonly AdministrationContextService $administrationContext,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

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
     *                       401 anonymous; 404 on cross-tenant; 500 without stack trace.
     *
     * @spec openspec/specs/purchase-requisition/spec.md
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
        }

        $payload = [
            'programme'           => trim((string) $this->request->getParam('programme', '')),
            'financialYear'       => (int) $this->request->getParam('financialYear', 0),
            'neededByDate'        => trim((string) $this->request->getParam('neededByDate', '')),
            'justification'       => trim((string) $this->request->getParam('justification', '')),
            'kind'                => trim((string) $this->request->getParam('kind', '')),
            'preferredSupplierId' => trim((string) $this->request->getParam('preferredSupplierId', '')),
            'lines'               => (array) $this->request->getParam('lines', []),
        ];

        try {
            $requisition = $this->requisitionService->createRequisition(
                administrationId: $administrationId,
                payload: $payload
            );
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RequisitionController: failed to create requisition',
                ['administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Could not create requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
     *                       401 anonymous; 404 on cross-tenant/missing; 409 on
     *                       invalid state; 500 without stack trace.
     *
     * @spec openspec/specs/purchase-requisition/spec.md
     */
    #[NoAdminRequired]
    public function submit(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid requisition id'], Http::STATUS_BAD_REQUEST);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Requisition not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $requisition = $this->requisitionService->submitRequisition(
                administrationId: $administrationId,
                requisitionId: $id
            );
        } catch (\RuntimeException $e) {
            return $this->mapRuntimeError(e: $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RequisitionController: failed to submit requisition',
                ['requisitionId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Could not submit requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
     *                       401 anonymous; 404 on cross-tenant/missing; 409 when
     *                       not submitted or budget insufficient; 500 without stack trace.
     *
     * @spec openspec/specs/purchase-requisition/spec.md
     */
    #[NoAdminRequired]
    public function approve(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid requisition id'], Http::STATUS_BAD_REQUEST);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Requisition not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $requisition = $this->requisitionService->approveRequisition(
                administrationId: $administrationId,
                requisitionId: $id,
                approverId: $user->getUID()
            );
        } catch (\RuntimeException $e) {
            return $this->mapRuntimeError(e: $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RequisitionController: failed to approve requisition',
                ['requisitionId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Could not approve requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
     *                       401 anonymous; 404 on cross-tenant/missing; 409 on
     *                       invalid state; 500 without stack trace.
     *
     * @spec openspec/specs/purchase-requisition/spec.md
     */
    #[NoAdminRequired]
    public function reject(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid requisition id'], Http::STATUS_BAD_REQUEST);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Requisition not found'], Http::STATUS_NOT_FOUND);
        }

        $reason = trim((string) $this->request->getParam('reason', ''));

        try {
            $requisition = $this->requisitionService->rejectRequisition(
                administrationId: $administrationId,
                requisitionId: $id,
                rejectorId: $user->getUID(),
                reason: $reason
            );
        } catch (\RuntimeException $e) {
            return $this->mapRuntimeError(e: $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RequisitionController: failed to reject requisition',
                ['requisitionId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Could not reject requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
     *                       validation; 401 anonymous; 404 on cross-tenant/missing;
     *                       409 when not approved / no supplier / no lines;
     *                       500 without stack trace.
     *
     * @spec openspec/specs/purchase-requisition/spec.md
     */
    #[NoAdminRequired]
    public function convert(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return new JSONResponse(['error' => 'Invalid requisition id'], Http::STATUS_BAD_REQUEST);
        }

        $administrationId = $this->scopeParam(name: 'administrationId');
        if ($administrationId === '') {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
            return new JSONResponse(['error' => 'Requisition not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $result = $this->conversionService->convertToPurchaseOrder(
                administrationId: $administrationId,
                requisitionId: $id
            );
        } catch (\RuntimeException $e) {
            return $this->mapRuntimeError(e: $e);
        } catch (\Throwable $e) {
            $this->logger->error(
                'RequisitionController: failed to convert requisition',
                ['requisitionId' => $id, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
            );
            return new JSONResponse(['error' => 'Could not convert requisition'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($result, Http::STATUS_OK);

    }//end convert()

    /**
     * Map a RuntimeException from the service layer to the appropriate HTTP
     * status: "not found" messages -> 404, everything else (invalid state,
     * budget exceeded, missing supplier/lines) -> 409 Conflict.
     *
     * @param \RuntimeException $e The exception to map.
     *
     * @return JSONResponse
     */
    private function mapRuntimeError(\RuntimeException $e): JSONResponse
    {
        $message = $e->getMessage();
        if (str_contains($message, 'not found') === true) {
            return new JSONResponse(['error' => $message], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['error' => $message], Http::STATUS_CONFLICT);

    }//end mapRuntimeError()

    /**
     * Read and validate a scope parameter, returning '' when blank/malformed.
     *
     * @param string $name Parameter name (body for POST / query for GET).
     *
     * @return string The validated value or '' (blank/malformed).
     */
    private function scopeParam(string $name): string
    {
        $value = trim((string) $this->request->getParam($name, ''));
        if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
            return '';
        }

        return $value;

    }//end scopeParam()
}//end class
