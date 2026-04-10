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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

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
     * @param IGroupManager    $groupManager     Nextcloud group manager
     * @param IUserSession     $userSession      Nextcloud user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private AnalyticsService $analyticsService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return 403 if the current user is not an admin.
     *
     * @return JSONResponse|null 403 response or null when user is authorised
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(['error' => 'Admin access required'], 403);
        }

        return null;
    }//end requireAdmin()

    /**
     * Get the current value and trend for a KPI metric.
     *
     * @param string $metricKey The metric identifier
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function kpi(string $metricKey): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $data = $this->analyticsService->getKpiValue($metricKey);

        return new JSONResponse($data);
    }//end kpi()

    /**
     * Run a report and return the snapshot data.
     *
     * @param string $reportType The report type
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function runReport(string $reportType): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $parameters = $this->request->getParams();

        $snapshot = $this->analyticsService->runReport($reportType, $parameters);

        return new JSONResponse($snapshot);
    }//end runReport()

    /**
     * Get the last saved snapshot for a report.
     *
     * @param string $id The report object ID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/general/tasks.md#task-11.4
     */
    public function snapshot(string $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard !== null) {
            return $guard;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $report        = $objectService->getObject(
                register: 'shillinq',
                schema: 'AnalyticsReport',
                id: $id,
            );

            return new JSONResponse(
                    [
                        'id'           => $id,
                        'snapshotData' => ($report['snapshotData'] ?? null),
                    ]
                    );
        } catch (\Throwable $e) {
            return new JSONResponse(
                    [
                        'id'           => $id,
                        'snapshotData' => null,
                    ]
                    );
        }//end try
    }//end snapshot()
}//end class
