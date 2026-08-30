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
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Dashboard\BBVComplianceWidget;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
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
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */
class BBVDashboardController extends Controller {
	/**
	 * Constructor for BBVDashboardController.
	 *
	 * @param IRequest $request The current request.
	 * @param IUserSession $userSession Anonymous-rejection guard
	 *                                  (ADR-005 / hydra-gate-no-admin-idor).
	 * @param IL10N $l10n Translation service used
	 *                    to localise response
	 *                    messages (ADR-007 / slice
	 *                    10 i18n).
	 * @param BBVComplianceWidget $widget Envelope assembler — reads
	 *                                    the slice-02 declarative
	 *                                    aggregation, cached for 1h
	 *                                    by ComplianceService.
	 * @param AdministrationContextService $administrationContext Admin RBAC + accessibility
	 *                                                            checks (slice 09, ADR-005)
	 *                                                            — server-side admin scope
	 *                                                            so cross-tenant requests
	 *                                                            cannot read another
	 *                                                            administration's data.
	 * @param FiscalYearContextService $fiscalYearContext Active fiscal-year resolver
	 *                                                    (slice 09, REQ-BBVW-006) —
	 *                                                    collapses the client-
	 *                                                    supplied fiscal year to
	 *                                                    the active window
	 *                                                    inherited from the
	 *                                                    Administration context.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly BBVComplianceWidget $widget,
		private readonly AdministrationContextService $administrationContext,
		private readonly FiscalYearContextService $fiscalYearContext,
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
	 *   - `administrationId` (string, optional) — explicit administration
	 *                                            scope. When supplied it
	 *                                            MUST resolve to an admin
	 *                                            the user has a valid
	 *                                            membership for; cross-
	 *                                            tenant requests are
	 *                                            masked as an empty
	 *                                            envelope (ADR-005).
	 *                                            When omitted, the active
	 *                                            administration from the
	 *                                            session context is used.
	 *   - `fiscalYear` (int, optional)         — accepted but constrained
	 *                                            to the active fiscal
	 *                                            year of the resolved
	 *                                            administration. Multi-
	 *                                            year views are out of
	 *                                            scope (giant D5 /
	 *                                            REQ-BBVW-006).
	 *
	 * Slice 09 additions:
	 *
	 *   - administrationId is server-derived from the session when the
	 *     client omits it (REQ-MA-001 default).
	 *   - Cross-tenant administrationId values resolve to an empty
	 *     envelope, never leaking other administrations' programmes.
	 *   - fiscalYear is collapsed to the active window inherited from the
	 *     Administration record (`fiscalYearStartMonth/Day` — REQ-MA-002).
	 *     Prior-fiscal-year GL data is excluded because the underlying
	 *     declarative aggregation runs on transactions inside the active
	 *     window only.
	 *   - The envelope echoes the resolved `fiscalYear` + `startDate` +
	 *     `endDate` + `administrationId` under a `scope` key so the UI
	 *     can render the "FY 2026" label without re-deriving it.
	 *
	 * @return JSONResponse {
	 *                      widgets: array,
	 *                      programmes: array,
	 *                      mappings: array,
	 *                      counts: array,
	 *                      summary: array,
	 *                      scope: array,
	 *                      generatedAt: string
	 *                      }
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
		}

		$administrationIdRaw = $this->request->getParam('administrationId');
		$administrationId = null;
		if (is_string($administrationIdRaw) === true && trim($administrationIdRaw) !== '') {
			$administrationId = trim($administrationIdRaw);
		}

		// Server-derived default: when the client omits administrationId, fall
		// back to the active administration from the session (REQ-MA-001).
		if ($administrationId === null) {
			$context = $this->administrationContext->buildContext();
			$sessionDefault = ($context['activeAdministrationId'] ?? null);
			if (is_string($sessionDefault) === true && $sessionDefault !== '') {
				$administrationId = $sessionDefault;
			}
		}

		// IDOR mask (ADR-005) — when the user lacks a valid membership we
		// return an empty envelope rather than 403 / 404 so the dashboard
		// never discloses the existence of other tenants.
		if ($administrationId === null
			|| $this->administrationContext->canAccess(administrationId: $administrationId) === false
		) {
			return new JSONResponse($this->emptyEnvelope());
		}

		$window = $this->fiscalYearContext->resolveActiveWindow(administrationId: $administrationId);
		if ($window === null) {
			return new JSONResponse($this->emptyEnvelope(administrationId: $administrationId));
		}

		$envelope = $this->widget->buildEnvelope(
			fiscalYear: $window['fiscalYear'],
			administrationId: $administrationId,
		);
		$envelope['scope'] = [
			'administrationId' => $administrationId,
			'fiscalYear' => $window['fiscalYear'],
			'startDate' => $window['startDate'],
			'endDate' => $window['endDate'],
		];

		return new JSONResponse($envelope);
	}//end index()

	/**
	 * Empty envelope for unauthorised / cross-tenant probes.
	 *
	 * Returned when the user has no accessible administration or the
	 * client-supplied administrationId resolves to a tenant the user is
	 * not a member of. Keeps the response shape stable so the dashboard
	 * still renders (with zero programmes) instead of crashing.
	 *
	 * @param string|null $administrationId Echoed administrationId or null.
	 *
	 * @return array<string,mixed>
	 */
	private function emptyEnvelope(?string $administrationId = null): array {
		return [
			'widgets' => [],
			'programmes' => [],
			'mappings' => [],
			'counts' => [
				'unconfigured' => 0,
				'on-track' => 0,
				'at-risk' => 0,
				'non-compliant' => 0,
			],
			'summary' => [
				'programmeCount' => 0,
				'mappingCount' => 0,
				'totalBudget' => 0,
				'totalYtdSpend' => 0,
				'utilization' => 0.0,
			],
			'scope' => [
				'administrationId' => $administrationId,
				'fiscalYear' => null,
				'startDate' => null,
				'endDate' => null,
			],
			'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];
	}//end emptyEnvelope()
}//end class
