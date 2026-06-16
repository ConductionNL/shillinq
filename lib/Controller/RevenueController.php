<?php

/**
 * Revenue Controller
 *
 * Tier-2 read-only IFRS 15 revenue cut-off API (REQ-IFRS15-007, REQ-IFRS15-008).
 * Exposes a single GET endpoint that returns the per-contract revenue cut-off for
 * one administration + period end: cumulative recognised, contract asset /
 * liability split, allocated transaction price, and the remaining-performance-
 * obligation amount. The endpoint is available to any authenticated user
 * (#[NoAdminRequired]); the administration scope is validated and reads are
 * delegated to OpenRegister's ObjectService, which enforces multitenancy / RBAC,
 * so no cross-administration data leaks (ADR-005 IDOR-safety). The cut-off is a
 * derived read — there is no create/update/delete route (the ContractAsset /
 * ContractLiability / RevenueWaterfall schemas are read-only).
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\RevenueCutoffService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/revenue-cutoff — per-contract IFRS 15 revenue cut-off for a period.
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 */
class RevenueController extends Controller
{
    /**
     * Constructor for the RevenueController.
     *
     * @param IRequest             $request       The request object.
     * @param RevenueCutoffService $cutoffService The revenue cut-off computation service.
     * @param IUserSession         $userSession   Session for the auth body-guard.
     * @param LoggerInterface      $logger        Logger for diagnostics (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly RevenueCutoffService $cutoffService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the per-contract revenue cut-off for a period (REQ-IFRS15-007, REQ-IFRS15-008).
     *
     * Query parameters:
     *  - administration_id (required) administration scope (ADR-005 IDOR-safety).
     *  - period_end        (required) ISO date the cut-off covers.
     *
     * Returns HTTP 200 with { data, total } on success; HTTP 400 on a missing or
     * malformed parameter; HTTP 500 (without a stack trace) on an unexpected fetch
     * failure.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
     */
    #[NoAdminRequired]
    public function cutoff(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        $periodEnd        = trim((string) $this->request->getParam('period_end', ''));

        if ($administrationId === '') {
            return new JSONResponse(
                ['error' => 'administration_id is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($periodEnd === '') {
            return new JSONResponse(
                ['error' => 'period_end is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Reject obviously malformed identifiers before touching the data layer
        // (ADR-005 input validation) — administration ids are short slugs.
        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(
                ['error' => 'administration_id must be a valid administration identifier'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // The period_end must be an ISO date (YYYY-MM-DD).
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $periodEnd) !== 1) {
            return new JSONResponse(
                ['error' => 'period_end must be an ISO date (YYYY-MM-DD)'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->cutoffService->compute(
                administrationId: $administrationId,
                periodEnd: $periodEnd
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'RevenueController: failed to compute revenue cut-off',
                [
                    'administrationId' => $administrationId,
                    'periodEnd'        => $periodEnd,
                    'exception'        => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to compute revenue cut-off'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse($result, Http::STATUS_OK);

    }//end cutoff()
}//end class
