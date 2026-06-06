<?php

/**
 * Trial Balance Controller
 *
 * Tier-2 read-only trial-balance API (REQ-TB-009, REQ-TB-015, REQ-TB-016).
 * Exposes a single GET endpoint that returns the per-account opening / movement /
 * closing breakdown for one administration + fiscal period. The endpoint is
 * available to any authenticated user (#[NoAdminRequired]); the administration
 * scope is validated and reads are delegated to OpenRegister's ObjectService,
 * which enforces multitenancy / RBAC, so no cross-administration data leaks
 * (REQ-TB-017). Trial balance is read-only — there is no create/update/delete
 * route (REQ-TB-007).
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\TrialBalanceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/trial-balance — period-scoped per-account trial balance.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 */
class TrialBalanceController extends Controller
{
    /**
     * Constructor for the TrialBalanceController.
     *
     * @param IRequest            $request             The request object.
     * @param TrialBalanceService $trialBalanceService The trial-balance computation service.
     * @param LoggerInterface     $logger              Logger for diagnostics (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly TrialBalanceService $trialBalanceService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the trial balance for a period (REQ-TB-009).
     *
     * Query parameters:
     *  - period_id         (required) fiscal period identifier (REQ-TB-015).
     *  - administration_id (required) administration scope (REQ-TB-016, REQ-TB-017).
     *  - prior_period_id   (optional) period whose closing balances seed the opening (REQ-TB-002).
     *
     * Returns HTTP 200 with { data, total, totals, isBalanced } on success;
     * HTTP 400 on a missing/blank parameter; HTTP 500 (without a stack trace) on
     * an unexpected GL fetch failure.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $periodId         = trim((string) $this->request->getParam('period_id', ''));
        $administrationId = trim((string) $this->request->getParam('administration_id', ''));
        $priorPeriodId    = trim((string) $this->request->getParam('prior_period_id', ''));

        if ($periodId === '') {
            return new JSONResponse(
                ['error' => 'period_id is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($administrationId === '') {
            return new JSONResponse(
                ['error' => 'administration_id is required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Reject obviously malformed identifiers before touching the data layer
        // (REQ-TB-015) — period/administration identifiers are short slugs.
        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $periodId) !== 1) {
            return new JSONResponse(
                ['error' => 'period_id must be a valid period identifier'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
            return new JSONResponse(
                ['error' => 'administration_id must be a valid administration identifier'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $filters = [];
        if ($priorPeriodId !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $priorPeriodId) === 1) {
            $filters['priorPeriodId'] = $priorPeriodId;
        }

        try {
            $result = $this->trialBalanceService->compute(
                administrationId: $administrationId,
                periodId: $periodId,
                filters: $filters
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'TrialBalanceController: failed to compute trial balance',
                [
                    'administrationId' => $administrationId,
                    'periodId'         => $periodId,
                    'exception'        => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to compute trial balance'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse($result, Http::STATUS_OK);

    }//end index()
}//end class
