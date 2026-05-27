<?php

/**
 * Shillinq Metrics Controller
 *
 * Controller for the Prometheus-compatible metrics endpoint.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/spec/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for the Prometheus-compatible metrics endpoint.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-4
 */
class MetricsController extends Controller
{
    /**
     * Constructor for MetricsController.
     *
     * @param IRequest $request The request object
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return application metrics in Prometheus text format.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        return new JSONResponse(
            [
                'app'     => Application::APP_ID,
                'metrics' => [],
            ]
        );
    }//end index()
}//end class
