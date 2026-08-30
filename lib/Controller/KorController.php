<?php

/**
 * KOR Controller
 *
 * Tier-2 read-only KOR drempel-bewaking API (REQ-KOR-002, REQ-KOR-003). Exposes a
 * single GET endpoint returning the running KOR omzet, drempel-benutting, monthly
 * breakdown, end-of-year prognose and the highest reached 80/90/100 % alert-schijf
 * for one administration + calendar year. The endpoint is available to any
 * authenticated user (#[NoAdminRequired]); the administration scope is validated
 * and reads are delegated to OpenRegister's ObjectService, which enforces
 * multitenancy / RBAC, so no cross-administration data leaks (REQ-KOR-002 IDOR
 * safety). KOR monitoring is read-only — there is no create/update/delete route;
 * state changes flow through the declarative KORRegistration lifecycle (ADR-031).
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
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\KorMonitorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/kor/monitor — KOR drempel-status for an administration + year.
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */
class KorController extends Controller {
	/**
	 * Constructor for the KorController.
	 *
	 * @param IRequest $request The request object.
	 * @param KorMonitorService $korMonitorService The KOR drempel-bewaking service.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly KorMonitorService $korMonitorService,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the KOR drempel-status for an administration + year (REQ-KOR-002, REQ-KOR-003).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (REQ-KOR-002 IDOR safety).
	 *  - year              (optional) calendar year; defaults to the current year.
	 *
	 * Returns HTTP 200 with the drempel-status payload on success; HTTP 400 on a
	 * missing/malformed parameter; HTTP 500 (without a stack trace) on an unexpected
	 * AR-ledger fetch failure.
	 *
	 * Authorization: `AdministrationContextService::canAccess()` is checked
	 * against the requested `administration_id` before the KOR service is ever
	 * called; a non-member is masked as 404, never a disclosing 403 (ADR-005
	 * Rule 3 — {@see \OCA\Shillinq\Service\AdministrationContextService::canAccess()}).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function monitor(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$yearParam = trim((string)$this->request->getParam('year', ''));

		if ($administrationId === '') {
			return new JSONResponse(
				['error' => 'administration_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$year = (int)date('Y');
		if ($yearParam !== '') {
			if (preg_match('/^[0-9]{4}$/', $yearParam) !== 1) {
				return new JSONResponse(
					['error' => 'year must be a four-digit calendar year'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$year = (int)$yearParam;
		}

		try {
			$result = $this->korMonitorService->status(
				administrationId: $administrationId,
				year: $year
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'KorController: failed to compute KOR drempel-status',
				[
					'administrationId' => $administrationId,
					'year' => $year,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute KOR threshold status'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end monitor()
}//end class
