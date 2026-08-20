<?php

/**
 * Shillinq Budget BBV Mapping Controller
 *
 * Thin page controller for the Budget Mapping index + detail pages.
 *
 * Slice 04 of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032). Returns minimal view envelopes so the manifest pages
 * declared in `bookkeeping-waterschappen-bbv-variant-04-manifest-routes`
 * are reachable end-to-end and so hydra-gate-route-auth sees explicit
 * #[NoAdminRequired] attributes on every endpoint. The mapping CRUD
 * itself is mediated by OpenRegister's object endpoints (admin-write
 * per slice 01 register permissions); slice 06/07 build the bespoke
 * index + detail UI that calls those endpoints.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
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
 * Thin page controller for the Budget BBV Mapping index + detail pages.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/tasks.md
 */
class BudgetBBVMappingController extends Controller {
	/**
	 * Constructor for BudgetBBVMappingController.
	 *
	 * @param IRequest $request The current request.
	 * @param IUserSession $userSession Anonymous-rejection guard
	 *                                  (ADR-005 / hydra-gate-no-admin-idor).
	 * @param IL10N $l10n Translation service used to
	 *                    localise response messages
	 *                    (ADR-007 / slice 10 i18n).
	 * @param AdministrationContextService $administrationContext Admin RBAC + accessibility
	 *                                                            checks (slice 09, ADR-005)
	 *                                                            — every mapping read is
	 *                                                            scoped to an administration
	 *                                                            the user is a member of.
	 * @param FiscalYearContextService $fiscalYearContext Active fiscal-year resolver
	 *                                                    (slice 09, REQ-BBVW-006).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly AdministrationContextService $administrationContext,
		private readonly FiscalYearContextService $fiscalYearContext,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the Budget Mapping index view envelope.
	 *
	 * Slice 04 only registers the page route + attribute; the index
	 * UI itself is built in slice 06 (bookkeeping-waterschappen-bbv-
	 * variant-06-mapping-index) and pulls the data from the
	 * OpenRegister BudgetBBVMapping schema. The envelope shape
	 * (schema, register) is fixed here so the future Vue page can
	 * resolve the manifest route deterministically.
	 *
	 * #[NoAdminRequired] opens the route to any authenticated user;
	 * the explicit user-session check rejects anonymous callers per
	 * ADR-005 so route registrations are never reachable without a
	 * session (hydra-gate-no-admin-idor).
	 *
	 * @return JSONResponse {register: string, schema: string, detailRoute: string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
		}

		// Security-endpoint-guards REQ-001 JUSTIFY: this envelope reads no
		// OpenRegister object — `scope` is derived entirely server-side from
		// the session user via AdministrationContextService::buildContext()
		// (never a request-supplied id), and the mapping CRUD itself is
		// mediated by OpenRegister's own admin-write register permissions
		// (slice 01), not by this controller. No per-object tenant guard
		// applies because no tenant-scoped object is read here.
		return new JSONResponse(
			[
				'register' => 'shillinq',
				'schema' => 'BudgetBBVMapping',
				'detailRoute' => 'BudgetBBVMappingDetail',
				'scope' => $this->resolveScope(),
			]
		);
	}//end index()

	/**
	 * Return the Budget Mapping detail view envelope.
	 *
	 * Slice 04 only registers the page route + attribute; the bespoke
	 * detail page + relation pickers are built in slice 07
	 * (bookkeeping-waterschappen-bbv-variant-07-mapping-detail) and
	 * write through OpenRegister's object endpoints (which apply the
	 * admin-write register permission from slice 01). This skeleton
	 * returns the id passed in the URL so the route is end-to-end
	 * reachable; no per-object IDOR surface is introduced because no
	 * data is read from storage. The session guard still rejects
	 * anonymous callers up-front per ADR-005.
	 *
	 * @param string $id The BudgetBBVMapping object id from the URL.
	 *
	 * @return JSONResponse {id: string, register: string, schema: string, indexRoute: string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l10n->t('Not logged in')], Http::STATUS_UNAUTHORIZED);
		}

		// Security-endpoint-guards REQ-001 JUSTIFY: $id is echoed back
		// unread — no OpenRegister lookup happens in this method (see the
		// method docblock), so there is no tenant-scoped object here for a
		// per-object guard to protect. The real BudgetBBVMapping read/write
		// happens through OpenRegister's own object endpoints (slice 07),
		// which apply register-level RBAC independently of this route.
		return new JSONResponse(
			[
				'id' => $id,
				'register' => 'shillinq',
				'schema' => 'BudgetBBVMapping',
				'indexRoute' => 'BudgetBBVMappings',
				'scope' => $this->resolveScope(),
			]
		);
	}//end show()

	/**
	 * Resolve the active administration + fiscal-year scope envelope.
	 *
	 * Slice-09 addition (REQ-BBVW-006). Returns the active
	 * administration id + fiscal year + half-open `[startDate, endDate)`
	 * window the Vue index/detail page applies as the default filter,
	 * derived server-side from {@see AdministrationContextService} +
	 * {@see FiscalYearContextService}. Returns null-valued fields when
	 * the user has no accessible administration so the page can still
	 * render an "empty / no administrations" state.
	 *
	 * @return array{administrationId:?string,fiscalYear:?int,startDate:?string,endDate:?string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	private function resolveScope(): array {
		$context = $this->administrationContext->buildContext();
		$administrationId = ($context['activeAdministrationId'] ?? null);

		$empty = [
			'administrationId' => null,
			'fiscalYear' => null,
			'startDate' => null,
			'endDate' => null,
		];

		if (is_string($administrationId) === false || $administrationId === '') {
			return $empty;
		}

		$window = $this->fiscalYearContext->resolveActiveWindow(administrationId: $administrationId);
		if ($window === null) {
			return $empty;
		}

		return [
			'administrationId' => $administrationId,
			'fiscalYear' => $window['fiscalYear'],
			'startDate' => $window['startDate'],
			'endDate' => $window['endDate'],
		];

	}//end resolveScope()
}//end class
