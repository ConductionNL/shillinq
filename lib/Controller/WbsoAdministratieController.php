<?php

/**
 * WBSO Administratie Controller
 *
 * Tier-1 read-only WBSO realisatie API (REQ-WBSO-010). Exposes a single GET
 * endpoint that returns, per WbsoBeschikking, the granted versus realised S&O
 * hours for one administration. The endpoint is available to any authenticated
 * user (#[NoAdminRequired]); the administration scope named by the request is
 * validated in this controller against the caller's own
 * AdministrationMembership via {@see AdministrationContextService::canAccess()}
 * (ADR-005 Rule 3 / security-endpoint-guards REQ-001) before any read runs — a
 * prior version of this paragraph claimed OpenRegister's ObjectService itself
 * enforced that scoping, which was false (no schema in this app declares an
 * `authorization` block, so OpenRegister grants every read to every
 * authenticated user). The realisatie is read-only — there is no
 * create/update/delete route here; the WBSO entities are authored through the
 * declarative manifest pages backed by OpenRegister CRUD.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\WbsoAdministratieService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/wbso/realisatie — per-beschikking granted-vs-realised S&O hours.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 */
class WbsoAdministratieController extends Controller {
	/**
	 * Constructor for the WbsoAdministratieController.
	 *
	 * @param IRequest $request The request object.
	 * @param WbsoAdministratieService $service The realisatie computation service.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 * @param AdministrationContextService $administrationContext Per-administration IDOR guard (ADR-005 Rule 3).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly WbsoAdministratieService $service,
		private readonly LoggerInterface $logger,
		private readonly AdministrationContextService $administrationContext,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return the WBSO realisatie summary for one administration (REQ-WBSO-010).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (REQ-WBSO-004).
	 *
	 * Returns HTTP 200 with { data, total } on success; HTTP 400 on a
	 * missing/malformed parameter; HTTP 500 (without a stack trace) on an
	 * unexpected fetch failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function realisatie(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		if ($administrationId === '') {
			return new JSONResponse(
				['error' => 'administration_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Reject obviously malformed identifiers before touching the data layer
		// (REQ-WBSO-004) — administration identifiers are short slugs.
		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// ADR-005 Rule 3 / REQ-001 (security-endpoint-guards). The docblock above
		// USED to claim "administration scope is validated against the caller's
		// membership by OpenRegister's ObjectService RBAC layer" — false, per the
		// same fleet-wide finding documented on VATReturnController/VATLineController:
		// none of this app's ~871 schemas declares an `authorization` block, so
		// OpenRegister grants every read to every authenticated user. Without this
		// check, any authenticated caller could read another organization's
		// statutory WBSO S&O realisatie by quoting its administration_id. Masked
		// as 404 (not 403) per AdministrationContextService::canAccess()'s own
		// contract — a non-member must not be able to distinguish "wrong
		// administration" from "administration does not exist".
		if ($this->administrationContext->canAccess($administrationId) === false) {
			return new JSONResponse(
				['error' => 'Administration not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$result = $this->service->realisatieSummary(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WbsoAdministratieController: failed to compute realisatie summary',
				[
					'administrationId' => $administrationId,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute WBSO realisatie summary'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end realisatie()
}//end class
