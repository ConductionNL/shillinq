<?php

/**
 * Financial Dashboard Controller
 *
 * Read-only API for the Wave-4 endpoint-bound financial dashboard widgets:
 * GET /api/dashboard/financial-series returns the monthly turnover / costs /
 * margin / billable-hours / cashflow series and GET
 * /api/dashboard/financial-summary returns the KPI bag (turnover, margin,
 * open debtors/creditors, billable hours, all-time cash position) with
 * previous-period trend values. Both endpoints are available to any
 * authenticated user (#[NoAdminRequired]); all reads are delegated to
 * OpenRegister's ObjectService, which enforces RBAC and multitenancy
 * (ADR-005, ADR-022), so the endpoints expose exactly the objects the user
 * could already fetch through /apps/openregister/api/objects — computed
 * server-side over ALL matching objects instead of the client's 2000-object
 * truncation. There is no create/update/delete route and no client-supplied
 * object identifier (no IDOR surface); the only inputs are the optional
 * from/to date bounds, which are format-validated before use.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDashboardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/dashboard/financial-series + /api/dashboard/financial-summary.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
class FinancialDashboardController extends Controller
{
    /**
     * Accepted shape for the from/to bounds: a `YYYY-MM-DD` day, optionally
     * followed by a time suffix (the service only reads the first ten
     * characters, mirroring the client's `.slice(0, 10)`).
     *
     * @var string
     */
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ][0-9:.+\-Z]{1,22})?$/';

    /**
     * Constructor for the FinancialDashboardController.
     *
     * @param IRequest                     $request   The request object.
     * @param FinancialDashboardService    $dashboard The series/summary computation service.
     * @param AdministrationContextService $context   Authenticated-user context (ADR-005).
     * @param LoggerInterface              $logger    Logger for diagnostics (no stack traces to client).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly FinancialDashboardService $dashboard,
        private readonly AdministrationContextService $context,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Monthly financial series (turnover, costs, margin + %, billable vs
     * non-billable hours, realized cashflow) over the requested range.
     *
     * Query parameters:
     *  - from (optional) ISO-8601 lower bound; requires `to`.
     *  - to   (optional) ISO-8601 upper bound; requires `from`.
     *
     * Without a range the trailing 12 months are returned (the widgets'
     * fallback window). Returns HTTP 200 with { months, revenue, costs,
     * margin, marginPct, billableHours, nonBillableHours, billablePct,
     * cashIn, cashOut, cashNet }; HTTP 400 on malformed bounds; HTTP 500
     * without a stack trace on an unexpected failure.
     *
     * @return JSONResponse The series payload.
     *
     * @spec openspec/specs/financial-dashboard-graphs/spec.md
     */
    #[NoAdminRequired]
    public function series(): JSONResponse
    {
        return $this->respond(endpoint: 'series');

    }//end series()

    /**
     * KPI summary (turnover, margin + %, open debtors/creditors, billable
     * hours, all-time cash position) over the requested range, with
     * previous-period values for the range-driven metrics (previous window =
     * same length immediately before the current one).
     *
     * Query parameters:
     *  - from (optional) ISO-8601 lower bound; requires `to`.
     *  - to   (optional) ISO-8601 upper bound; requires `from`.
     *
     * Returns HTTP 200 with { months, previousMonths, current, previousPeriod };
     * HTTP 400 on malformed bounds; HTTP 500 without a stack trace on an
     * unexpected failure.
     *
     * @return JSONResponse The summary payload.
     *
     * @spec openspec/specs/financial-dashboard-graphs/spec.md
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse
    {
        return $this->respond(endpoint: 'summary');

    }//end summary()

    /**
     * Shared request handling for both endpoints: authentication gate,
     * from/to validation, service dispatch and the no-stack-trace 500 path.
     *
     * @param string $endpoint Either 'series' or 'summary'.
     *
     * @return JSONResponse The endpoint payload or an error envelope.
     */
    private function respond(string $endpoint): JSONResponse
    {
        // Authentication gate — the NC SecurityMiddleware already rejects
        // anonymous requests for #[NoAdminRequired] routes; we check
        // explicitly (ADR-005) so the data layer below can rely on a
        // resolved user for its RBAC scoping.
        if ($this->context->currentUserId() === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $from = trim((string) $this->request->getParam('from', ''));
        $to   = trim((string) $this->request->getParam('to', ''));

        if (($from === '') !== ($to === '')) {
            return new JSONResponse(
                ['error' => 'from and to must be provided together'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($from !== '' && preg_match(self::DATE_PATTERN, $from) !== 1) {
            return new JSONResponse(
                ['error' => 'from must be an ISO-8601 date'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if ($to !== '' && preg_match(self::DATE_PATTERN, $to) !== 1) {
            return new JSONResponse(
                ['error' => 'to must be an ISO-8601 date'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $fromParam = null;
        if ($from !== '') {
            $fromParam = $from;
        }

        $toParam = null;
        if ($to !== '') {
            $toParam = $to;
        }

        try {
            if ($endpoint === 'series') {
                $result = $this->dashboard->series(from: $fromParam, to: $toParam);
            } else {
                $result = $this->dashboard->summary(from: $fromParam, to: $toParam);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'FinancialDashboardController: failed to compute '.$endpoint,
                [
                    'from'      => $from,
                    'to'        => $to,
                    'exception' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                ['error' => 'Failed to compute financial '.$endpoint],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse($result, Http::STATUS_OK);

    }//end respond()
}//end class
