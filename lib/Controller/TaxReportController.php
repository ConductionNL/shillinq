<?php

/**
 * Tax Report Controller
 *
 * Tier-2 read-only Vpb quarterly/annual tax-statement API (REQ-VPB-003,
 * REQ-VPB-009, REQ-VPB-010, REQ-VPB-012). Exposes two GET endpoints returning
 * the aggregated income statement for one administration + fiscal period. The
 * endpoints are available to any authenticated user (#[NoAdminRequired]) and are
 * authorised per administration here, against the caller's memberships
 * (AdministrationContextService::canAccess(), ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph used to say the scope "is validated and reads are delegated
 * to OpenRegister's ObjectService, which enforces multitenancy / RBAC, so no
 * cross-administration data leaks". Both halves were false: the only check was a
 * character-class regex on the id, and OpenRegister grants every action on a
 * schema that declares no `authorization` block — which is all ~871 of them here.
 *
 * The statement is computed (read-only) — there is no
 * create/update/delete route; deadline/payment CRUD is served by OpenRegister's
 * generic object API.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-36
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\TaxReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /api/tax-reports/{year}/{quarter} — quarterly Vpb statement.
 * GET /api/tax-reports/{year}            — annual Vpb summary.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-36
 */
class TaxReportController extends Controller {
	/**
	 * Constructor for the TaxReportController.
	 *
	 * @param IRequest $request The request object.
	 * @param TaxReportService $taxReportService The tax-statement computation service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TaxReportService $taxReportService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the quarterly Vpb tax statement (REQ-VPB-009).
	 *
	 * Path parameters:
	 *  - year    (int)   fiscal year (1900-2200).
	 *  - quarter (int)   quarter 1-4.
	 *
	 * Query parameter:
	 *  - administration_id (required) administration scope (REQ-VPB-003).
	 *
	 * Returns HTTP 200 with the statement on success; HTTP 400 on a
	 * missing/invalid parameter; HTTP 500 (without a stack trace) on a GL fetch
	 * failure.
	 *
	 * @param string $year Fiscal year (path).
	 * @param string $quarter Quarter (path).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-36
	 */
	#[NoAdminRequired]
	public function quarter(string $year, string $quarter): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$error = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$fiscalYear = (int)$year;
		$quarterValue = (int)$quarter;
		if ($fiscalYear < 1900 || $fiscalYear > 2200) {
			return new JSONResponse(['error' => 'year must be a valid fiscal year'], Http::STATUS_BAD_REQUEST);
		}

		if ($quarterValue < 1 || $quarterValue > 4) {
			return new JSONResponse(['error' => 'quarter must be between 1 and 4'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->taxReportService->computeQuarter(
				administrationId: $administrationId,
				fiscalYear: $fiscalYear,
				quarter: $quarterValue
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TaxReportController: failed to compute quarterly tax statement',
				[
					'administrationId' => $administrationId,
					'fiscalYear' => $fiscalYear,
					'quarter' => $quarterValue,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute tax statement'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end quarter()

	/**
	 * Return the annual Vpb summary rolling up Q1-Q4 (REQ-VPB-012).
	 *
	 * @param string $year Fiscal year (path).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-25
	 */
	#[NoAdminRequired]
	public function annual(string $year): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$error = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$fiscalYear = (int)$year;
		if ($fiscalYear < 1900 || $fiscalYear > 2200) {
			return new JSONResponse(['error' => 'year must be a valid fiscal year'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->taxReportService->computeAnnual(
				administrationId: $administrationId,
				fiscalYear: $fiscalYear
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TaxReportController: failed to compute annual tax summary',
				[
					'administrationId' => $administrationId,
					'fiscalYear' => $fiscalYear,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute tax summary'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end annual()

	/**
	 * Validate AND authorise the administration_id query parameter (REQ-VPB-003 scoping).
	 *
	 * ⚠️ Renamed from `validateAdministration()`. The old name implied an access
	 * check; the old body was entirely an empty-check plus
	 * `preg_match('/^[A-Za-z0-9_.\-]{1,64}$/')` — a character-class test. Format
	 * validation is not authorisation, and both callers relied on this method for
	 * the latter. It now performs the membership check as well (ADR-005 /
	 * REQ-MA-001), masking a non-member's administration as 404.
	 *
	 * @param string $administrationId The administration identifier to validate.
	 *
	 * @return JSONResponse|null A 400/404 response when refused, null when acceptable.
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
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

		return null;
	}//end requireAccessibleAdministration()
}//end class
