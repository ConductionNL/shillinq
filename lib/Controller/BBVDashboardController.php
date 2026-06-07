<?php

/**
 * Shillinq BBV Dashboard Controller
 *
 * Thin page controller for the waterschappen BBV compliance dashboard.
 *
 * Slice 04 of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032). Renders the widget-data envelope the dashboard page (slice
 * 05) binds to. This controller is intentionally a skeleton: slice 05
 * wires the BBVProgramme aggregations + utilisation tiles into the
 * envelope. The route exists in appinfo/routes.php so ADR-016 +
 * hydra-gate-route-auth see an explicit registration with an explicit
 * #[NoAdminRequired] auth attribute.
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin page controller for the waterschappen BBV compliance dashboard.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/tasks.md
 */
class BBVDashboardController extends Controller
{
    /**
     * Constructor for BBVDashboardController.
     *
     * @param IRequest     $request     The current request.
     * @param IUserSession $userSession Anonymous-rejection guard
     *                                  (ADR-005 / hydra-gate-no-admin-idor).
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the BBV-compliance-dashboard widget-data envelope.
     *
     * Slice 04 of the waterschappen BBV chain only registers the page
     * route + attribute; the widget payload is added by slice 05. The
     * envelope shape (widgets[], generatedAt) is fixed here so the
     * dashboard component (slice 05) can bind unconditionally.
     *
     * #[NoAdminRequired] opens the route to any authenticated user
     * (finance officers, controllers); the explicit user-session check
     * rejects anonymous callers per ADR-005 so the page never leaks
     * an empty envelope to logged-out probes (hydra-gate-no-admin-idor).
     *
     * @return JSONResponse {widgets: array<int,array>, generatedAt: string}
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-bbv-page-routes
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            [
                'widgets'     => [],
                'generatedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ]
        );
    }//end index()
}//end class
