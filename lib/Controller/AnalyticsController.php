<?php

/**
 * Shillinq Analytics Controller
 *
 * OCS API controller for KPI data and analytics report execution.
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
 * @spec openspec/changes/general/tasks.md#task-11.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for analytics KPI values and report execution.
 *
 * @spec openspec/changes/general/tasks.md#task-11.2
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
     * Get the current KPI value and trend for a metric key.
     *
     * @param string $metricKey The metric identifier.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function kpi(string $metricKey): JSONResponse
    {
        $data = $this->analyticsService->getKpiValue($metricKey);

        return new JSONResponse($data);
    }//end kpi()

    /**
     * Run a report by type and return its snapshot data.
     *
     * @param string $reportType The report type identifier.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function runReport(string $reportType): JSONResponse
    {
        $parameters = $this->request->getParams();
        unset($parameters['reportType'], $parameters['_route']);

        $snapshot = $this->analyticsService->runReport($reportType, $parameters);

        return new JSONResponse(
                [
                    'reportType'   => $reportType,
                    'snapshotData' => $snapshot,
                    'lastRunAt'    => (new DateTimeImmutable())->format('c'),
                ]
                );
    }//end runReport()

    /**
     * Get the last saved snapshot for a report.
     *
     * @param string $id The report object ID.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/general/tasks.md#task-11.2
     */
    public function snapshot(string $id): JSONResponse
    {
        return new JSONResponse(
                [
                    'id'       => $id,
                    'snapshot' => null,
                ]
                );
    }//end snapshot()
}//end class
