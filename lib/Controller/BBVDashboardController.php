<?php

/**
 * Shillinq BBV Dashboard Controller
 *
 * Thin page controller for the waterschappen BBV compliance dashboard.
 *
 * Slice 04 of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032) registered the route + `#[NoAdminRequired]` auth attribute
 * and returned an empty-widget skeleton. Slice 08 (this controller's
 * present form) delegates the widget envelope to
 * {@see \OCA\Shillinq\Dashboard\BBVComplianceWidget}, which reads the
 * slice-02 materialised aggregation values and shapes the JSON the
 * slice-05 dashboard binds to. The widget envelope is cached for 1h
 * via {@see \OCA\Shillinq\Service\ComplianceService} and invalidated
 * on GL transaction create/update by
 * {@see \OCA\Shillinq\Listener\GLTransactionComplianceCacheListener}.
 * Slice 10 routes the controller's error responses through
 * {@see \OCP\IL10N::t()} so the anonymous-rejection message is
 * translatable (ADR-007).
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
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Dashboard\BBVComplianceWidget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Thin page controller for the waterschappen BBV compliance dashboard.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
class BBVDashboardController extends Controller
{
    /**
     * Constructor for BBVDashboardController.
     *
     * @param IRequest            $request     The current request.
     * @param IUserSession        $userSession Anonymous-rejection guard
     *                                         (ADR-005 / hydra-gate-no-admin-idor).
     * @param IL10N               $l10n        Translation service used
     *                                         to localise response
     *                                         messages (ADR-007 / slice
     *                                         10 i18n).
     * @param BBVComplianceWidget $widget      Envelope assembler — reads
     *                                         the slice-02 declarative
     *                                         aggregation, cached for 1h
     *                                         by ComplianceService.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IL10N $l10n,
        private readonly BBVComplianceWidget $widget,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the BBV-compliance-dashboard widget-data envelope.
     *
     * Slice 04 registered the route + auth attribute; slice 05 declared
     * the widget components; slice 08 (this method) delegates envelope
     * assembly to {@see BBVComplianceWidget::buildEnvelope()} which
     * reads the slice-02 materialised aggregation. Slice 10 wraps the
     * anonymous-rejection error string through IL10N::t(). The route
     * auth attribute is preserved from slice 04 — `#[NoAdminRequired]`
     * opens the route to any authenticated user (finance officers,
     * controllers); the explicit user-session check rejects anonymous
     * callers per ADR-005 so the page never leaks an empty envelope to
     * logged-out probes (hydra-gate-no-admin-idor).
     *
     * Query parameters:
     *
     *   - `fiscalYear` (int, optional)        — filter active programmes
     *                                            to a given fiscal year.
     *   - `administrationId` (string, optional) — scope the envelope
     *                                            to one administration.
     *
     * @return JSONResponse {
     *   widgets: array,
     *   programmes: array,
     *   mappings: array,
     *   counts: array,
     *   summary: array,
     *   generatedAt: string
     * }
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-08-compliance-service/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-the-dashboard-controller-shall-return-the-widget-data-envelope
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
        }

        $fiscalYearRaw = $this->request->getParam('fiscalYear');
        $fiscalYear    = null;
        if (is_numeric($fiscalYearRaw) === true) {
            $fiscalYear = (int) $fiscalYearRaw;
        }

        $administrationIdRaw = $this->request->getParam('administrationId');
        $administrationId    = null;
        if (is_string($administrationIdRaw) === true && trim($administrationIdRaw) !== '') {
            $administrationId = trim($administrationIdRaw);
        }

        return new JSONResponse(
            $this->widget->buildEnvelope(
                fiscalYear: $fiscalYear,
                administrationId: $administrationId,
            )
        );
    }//end index()
}//end class
