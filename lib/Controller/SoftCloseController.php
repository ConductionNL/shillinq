<?php

/**
 * Soft Close Controller
 *
 * Tier-2 continuous-close API (REQ-CLS-002, REQ-CLS-005, REQ-CLS-007). Exposes
 * the on-demand soft-close trigger, the on-demand flux-analysis trigger, and
 * the flux-narrative export endpoint. Authentication is enforced via
 * #[NoAdminRequired]; per-administration scope is resolved server-side from
 * the request and writes are role-gated inside the underlying services /
 * lifecycle guards. Errors return a static client-safe message + the right
 * HTTP status — no stack trace ever reaches the client (ADR-005).
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\FluxService;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST surface for soft-close execution + flux analysis (REQ-CLS-002, REQ-CLS-007).
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
 */
class SoftCloseController extends Controller
{
    /**
     * Construct the controller.
     *
     * @param IRequest          $request           Request object.
     * @param SoftCloseExecutor $softCloseExecutor Orchestration service.
     * @param FluxService       $fluxService       Flux analysis service.
     * @param IUserSession      $userSession       Session for the acting user id.
     * @param LoggerInterface   $logger            Logger (no stack traces to client).
     */
    public function __construct(
        IRequest $request,
        private readonly SoftCloseExecutor $softCloseExecutor,
        private readonly FluxService $fluxService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * POST /api/v2/soft-close/{administrationId}/execute-now (REQ-CLS-002).
     *
     * On-demand soft-close trigger for the administratie. The bulk of work is
     * delegated to SoftCloseExecutor; controller-level auth-guard rejects
     * unauthenticated callers and validates the administration id slug.
     *
     * Body params:
     *  - periodId (string, optional): yyyy-mm. Defaults to current month.
     *
     * @param string $administrationId Administration scope (path).
     *
     * @return JSONResponse 200 with the run report; 400 on bad input; 401 unauth.
     *
     * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
     */
    #[NoAdminRequired]
    public function executeNow(string $administrationId): JSONResponse
    {
        if ($this->requireUser() === false) {
            return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim($administrationId);
        if ($this->validId(value: $administrationId) === false) {
            return $this->error(message: 'administration_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
        }

        $periodId = trim((string) $this->request->getParam('periodId', ''));
        if ($periodId === '') {
            $periodId = (new DateTimeImmutable())->format('Y-m');
        }

        if (preg_match('/^\d{4}-\d{2}$/', $periodId) !== 1) {
            return $this->error(message: 'period_id must match yyyy-mm', status: Http::STATUS_BAD_REQUEST);
        }

        try {
            $report = $this->softCloseExecutor->execute(
                administrationId: $administrationId,
                periodId: $periodId,
                asOf: new DateTimeImmutable()
            );

            $status = $report['status'] === 'failed' ? Http::STATUS_INTERNAL_SERVER_ERROR : Http::STATUS_OK;
            return new JSONResponse(['data' => $report], $status);
        } catch (\Throwable $e) {
            return $this->serverError(action: 'executeNow', e: $e);
        }

    }//end executeNow()

    /**
     * POST /api/v2/flux-runs/execute (REQ-CLS-005).
     *
     * On-demand flux analysis. The request body carries the run inputs
     * (administrationId, periodId, scope, comparisonBasis, accounts array,
     * materialityPolicy snapshot).
     *
     * @return JSONResponse 200 with the run summary; 400 on bad input; 401 unauth.
     *
     * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-22
     */
    #[NoAdminRequired]
    public function executeFlux(): JSONResponse
    {
        if ($this->requireUser() === false) {
            return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
        }

        $administrationId = trim((string) $this->request->getParam('administrationId', ''));
        if ($this->validId(value: $administrationId) === false) {
            return $this->error(message: 'administration_id is required', status: Http::STATUS_BAD_REQUEST);
        }

        $periodId = trim((string) $this->request->getParam('periodId', ''));
        if (preg_match('/^\d{4}-\d{2}$/', $periodId) !== 1) {
            return $this->error(message: 'period_id must match yyyy-mm', status: Http::STATUS_BAD_REQUEST);
        }

        $accounts = (array) $this->request->getParam('accounts', []);
        $policy   = (array) $this->request->getParam('materialityPolicy', []);
        $scope    = (string) $this->request->getParam('scope', 'administratie');
        $basis    = (string) $this->request->getParam('comparisonBasis', 'budget');

        try {
            $summary = $this->fluxService->run(
                    [
                        'administrationId'  => $administrationId,
                        'periodId'          => $periodId,
                        'scope'             => $scope,
                        'comparisonBasis'   => $basis,
                        'accounts'          => $accounts,
                        'materialityPolicy' => $policy,
                        'runTimestamp'      => new DateTimeImmutable(),
                    ]
                    );

            return new JSONResponse(['data' => $summary], Http::STATUS_OK);
        } catch (\Throwable $e) {
            return $this->serverError(action: 'executeFlux', e: $e);
        }

    }//end executeFlux()

    /**
     * GET /api/v2/flux-runs/{fluxRunId}/narrative?format=pdf|markdown|json (REQ-CLS-007).
     *
     * For a known FluxRun, build the narrative + render to the requested format.
     * The accounts payload for the narrative is gathered from the request body
     * when present (the actual GL aggregation lives elsewhere); without items
     * we render an empty narrative shell.
     *
     * @param string $fluxRunId The run id (path).
     *
     * @return DataResponse|JSONResponse Format-specific response.
     *
     * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-25
     */
    #[NoAdminRequired]
    public function narrative(string $fluxRunId): DataResponse|JSONResponse
    {
        if ($this->requireUser() === false) {
            return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
        }

        $fluxRunId = trim($fluxRunId);
        if ($this->validId(value: $fluxRunId) === false) {
            return $this->error(message: 'flux_run_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
        }

        $format   = strtolower((string) $this->request->getParam('format', 'json'));
        $items    = (array) $this->request->getParam('items', []);
        $periodId = trim((string) $this->request->getParam('periodId', ''));

        try {
            $narrative = $this->fluxService->buildNarrative(items: $items, periodId: $periodId);

            return match ($format) {
                'json' => new JSONResponse(['data' => $narrative], Http::STATUS_OK),
                'markdown' => new DataResponse(
                    $this->fluxService->renderNarrativeMarkdown(narrative: $narrative),
                    Http::STATUS_OK,
                    ['Content-Type' => 'text/markdown; charset=utf-8']
                ),
                'pdf' => new DataResponse(
                    $this->fluxService->renderNarrativePdfBody(narrative: $narrative),
                    Http::STATUS_OK,
                    ['Content-Type' => 'application/pdf']
                ),
                default => $this->error(message: 'format must be json, markdown or pdf', status: Http::STATUS_BAD_REQUEST),
            };
        } catch (\Throwable $e) {
            return $this->serverError(action: 'narrative', e: $e);
        }

    }//end narrative()

    /**
     * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
     *
     * @return bool True when authenticated, false otherwise.
     */
    private function requireUser(): bool
    {
        return $this->userSession->getUser() !== null;

    }//end requireUser()

    /**
     * Validate a short slug identifier.
     *
     * @param string $value The value to validate.
     *
     * @return bool True when the value is a safe short identifier.
     */
    private function validId(string $value): bool
    {
        return $value !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $value) === 1;

    }//end validId()

    /**
     * Build a client-safe error response.
     *
     * @param string $message The static, client-safe message.
     * @param int    $status  The HTTP status code.
     *
     * @return JSONResponse The error response.
     */
    private function error(string $message, int $status): JSONResponse
    {
        return new JSONResponse(['error' => $message], $status);

    }//end error()

    /**
     * Log an unexpected error server-side and return a generic 500 (no stack trace).
     *
     * @param string     $action The controller action for the log entry.
     * @param \Throwable $e      The caught throwable.
     *
     * @return JSONResponse A generic 500 response.
     */
    private function serverError(string $action, \Throwable $e): JSONResponse
    {
        $this->logger->error(
            'SoftCloseController: '.$action.' failed',
            ['exception' => $e->getMessage()]
        );

        return new JSONResponse(['error' => 'An unexpected error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end serverError()
}//end class
