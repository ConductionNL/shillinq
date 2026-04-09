<?php

/**
 * Shillinq Analytics Controller
 *
 * OCS API controller for KPI values and report execution.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/general/tasks.md#task-11.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for analytics KPI endpoints and report execution.
 *
 * @spec openspec/changes/general/tasks.md#task-11.4
 */
class AnalyticsController extends Controller
{

    /**
     * Constructor for AnalyticsController.
     *
     * @param IRequest         $request          The request object
     * @param AnalyticsService $analyticsService The analytics service
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private AnalyticsService $analyticsService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get the current value and trend for a KPI metric.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $metricKey The metric identifier
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function kpi(string $metricKey): JSONResponse
    {
        $data = $this->analyticsService->getKpiValue($metricKey);

        return new JSONResponse($data);
    }//end kpi()

    /**
     * Run a report and return the snapshot data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $reportType The report type
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function runReport(string $reportType): JSONResponse
    {
        $parameters = $this->request->getParams();

        $snapshot = $this->analyticsService->runReport($reportType, $parameters);

        return new JSONResponse($snapshot);
    }//end runReport()

    /**
     * Get the last saved snapshot for a report.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The report object ID
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function snapshot(string $id): JSONResponse
    {
        return new JSONResponse([
            'id'           => $id,
            'snapshotData' => null,
        ]);
    }//end snapshot()
}//end class
