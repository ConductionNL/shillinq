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
use OCA\Shillinq\Service\Pipelinq\CustomerBridgeMetricsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDisplayResponse;
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
     * @param IRequest                          $request               The request object.
     * @param CustomerBridgeMetricsService|null $customerBridgeMetrics Customer-bridge metrics aggregator (slice 11); nullable so the
     *                                                                 retrofitted endpoint keeps working when the integration is wired off.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ?CustomerBridgeMetricsService $customerBridgeMetrics=null
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return application metrics as a JSON snapshot.
     *
     * The legacy contract (`{ app, metrics: [] }`) is preserved; the
     * pipelinq customer-bridge slice 11 adds a `pipelinq` block with the
     * snapshot of the {@see CustomerBridgeMetricsService} counters and
     * gauges. Dashboards that already poll this endpoint as JSON keep
     * working without changes.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-4
     * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        $pipelinq = ($this->customerBridgeMetrics?->snapshot() ?? []);

        return new JSONResponse(
            [
                'app'      => Application::APP_ID,
                'metrics'  => [],
                'pipelinq' => $pipelinq,
            ]
        );
    }//end index()

    /**
     * Return the pipelinq customer-bridge metrics in Prometheus text format.
     *
     * Exposes the same series as {@see self::index()} but in the canonical
     * Prometheus exposition format an ops scraper can consume directly.
     * The body always has `Content-Type: text/plain; version=0.0.4` to
     * match the Prometheus spec. Admin-gated for parity with the JSON
     * endpoint — the metrics include the in-flight circuit-breaker state.
     *
     * @return DataDisplayResponse
     *
     * @spec openspec/changes/bookings-pipelinq-customer-bridge-11-docs-observability/tasks.md
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function pipelinq(): DataDisplayResponse
    {
        $body = ($this->customerBridgeMetrics?->renderPrometheus() ?? "# pipelinq customer-bridge metrics service not bound\n");

        return new DataDisplayResponse(
            data: $body,
            statusCode: 200,
            headers: ['Content-Type' => 'text/plain; version=0.0.4']
        );
    }//end pipelinq()
}//end class
