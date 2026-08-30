<?php

/**
 * Innovatiebox Controller
 *
 * Read-only innovatiebox-administratie API (REQ-IBA-006, REQ-IBA-009,
 * REQ-IBA-004). Exposes the per-asset Vpb roll-up, a non-persisting nexus
 * scenario calculator, and the doorsnijdingsverbod year-end check. Every method
 * is available to any authenticated user (#[NoAdminRequired]); the
 * administration scope is validated and reads are delegated to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC, so no cross-administration
 * data leaks (REQ-IBA-008). These endpoints never mutate innovatiebox records —
 * registration/calculation writes go through the OpenRegister object UI under
 * the audit-trail-immutable schemas.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\DoorsnijdingsVerbodValidator;
use OCA\Shillinq\Service\InnovatieboxAggregationService;
use OCA\Shillinq\Service\InnovatieboxSbrExportService;
use OCA\Shillinq\Service\NexusCalculationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Read-only innovatiebox-administratie endpoints (REQ-IBA-006/009/004).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 */
class InnovatieboxController extends Controller {
	/**
	 * Identifier validation pattern (short slugs only; blocks path traversal).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor for the InnovatieboxController.
	 *
	 * @param IRequest $request The request object.
	 * @param InnovatieboxAggregationService $aggregation The per-asset Vpb roll-up service.
	 * @param NexusCalculationService $nexus The nexus arithmetic helper (scenario).
	 * @param DoorsnijdingsVerbodValidator $doorsnijden The doorsnijdingsverbod validator.
	 * @param InnovatieboxSbrExportService $sbrExport The SBR export service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly InnovatieboxAggregationService $aggregation,
		private readonly NexusCalculationService $nexus,
		private readonly DoorsnijdingsVerbodValidator $doorsnijden,
		private readonly InnovatieboxSbrExportService $sbrExport,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the per-asset innovatiebox roll-up for a year (REQ-IBA-006).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (REQ-IBA-008).
	 *  - boekjaar          (required) fiscal year (4-digit).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	#[NoAdminRequired]
	public function aggregation(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$financialYear = trim((string)$this->request->getParam('financialYear', ''));

		$error = $this->requireAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$yearError = $this->requireYear(financialYear: $financialYear);
		if ($yearError !== null) {
			return $yearError;
		}

		try {
			$result = $this->aggregation->aggregate(
				administrationId: $administrationId,
				financialYear: (int)$financialYear
			);
		} catch (\Throwable $e) {
			return $this->fail(
				message: 'Failed to compute innovatiebox aggregation',
				context: [
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'exception' => $e->getMessage(),
				]
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end aggregation()

	/**
	 * Recalculate a nexus break for a what-if scenario without persisting (REQ-IBA-009).
	 *
	 * Query parameters (all required, numeric, >= 0):
	 *  - eigen_rd_kosten, uitbesteed_derden, uitbesteed_verbonden.
	 *
	 * Returns the applied nexusbreuk and the full breakdown; no records are
	 * modified. Used by the scenario planner ("what if I outsource EUR X to a
	 * related party?").
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-009
	 *
	 * @no-admin-idor-exempt Stateless calculator — reads no storage and takes no object
	 *     reference, so there is nothing to scope to a caller. The three inputs
	 *     (own_rd_cost, uitbesteed_derden, uitbesteed_verbonden) are numbers supplied by
	 *     the caller; they are handed to NexusCalculationService::calculateNexusBreak(),
	 *     whose body is pure arithmetic over its own arguments (max/min/round + the OECD
	 *     1.3 uplift and the 1.0 cap) with no ObjectService, no mapper, no app-config and
	 *     no session read anywhere in the call. Every byte of the response is a function
	 *     of the caller's own request, so no other administration's data can be reached
	 *     by substituting any value. Deliberately NOT "fixed" with an administration_id
	 *     parameter: that would demand a scope term the computation has no use for, and a
	 *     guard that gates nothing is the dead auth code gate-6 exists to catch.
	 *     Verify by reading lib/Service/NexusCalculationService.php::calculateNexusBreak().
	 */
	#[NoAdminRequired]
	public function scenario(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$eigen = $this->request->getParam('own_rd_cost', null);
		$derden = $this->request->getParam('uitbesteed_derden', null);
		$verbonden = $this->request->getParam('uitbesteed_verbonden', null);

		foreach (['own_rd_cost' => $eigen, 'uitbesteed_derden' => $derden, 'uitbesteed_verbonden' => $verbonden] as $name => $value) {
			if ($value === null || is_numeric($value) === false || (float)$value < 0.0) {
				return new JSONResponse(
					['error' => $name . ' must be a non-negative number'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		$result = $this->nexus->calculateNexusBreak(
			eigenRdCost: (float)$eigen,
			uitbesteedDerden: (float)$derden,
			uitbesteedVerbonden: (float)$verbonden
		);

		return new JSONResponse($result, Http::STATUS_OK);
	}//end scenario()

	/**
	 * Run the doorsnijdingsverbod year-end check (REQ-IBA-004).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope.
	 *  - boekjaar          (required) fiscal year.
	 *
	 * Returns the duplication findings and whether the year-end close is blocked.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
	 */
	#[NoAdminRequired]
	public function doorsnijdingsverbod(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$financialYear = trim((string)$this->request->getParam('financialYear', ''));

		$error = $this->requireAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$yearError = $this->requireYear(financialYear: $financialYear);
		if ($yearError !== null) {
			return $yearError;
		}

		try {
			$result = $this->doorsnijden->validateNoDuplication(
				administrationId: $administrationId,
				financialYear: (int)$financialYear
			);
		} catch (\Throwable $e) {
			return $this->fail(
				message: 'Failed to run doorsnijdingsverbod check',
				context: [
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'exception' => $e->getMessage(),
				]
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end doorsnijdingsverbod()

	/**
	 * Render the SBR/XBRL + docudesk PDF hand-off payload for the Vpb
	 * innovatiebox-sectie (REQ-IBA-006, task 8.1).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (REQ-IBA-008).
	 *  - boekjaar          (required) fiscal year (4-digit).
	 *  - methode           (optional) 'per_asset_afpelmethode' (default) or
	 *                      'flat_rate_25pct'.
	 *
	 * Returns {sbr, pdf} with the deterministic instanceRef, the per-asset
	 * rows (or the single forfaitair line), and the totals that contribute
	 * to Vpb-aangifte regel 23. The actual XBRL serialisation + Digipoort
	 * transport is owned by the not-yet-merged bookkeeping-sbr-xbrl-reporting
	 * NT mapper; this endpoint produces the deterministic hand-off contract.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	#[NoAdminRequired]
	public function export(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$financialYear = trim((string)$this->request->getParam('financialYear', ''));
		$method = trim((string)$this->request->getParam('method', 'per_asset_afpelmethode'));

		$error = $this->requireAdministration(administrationId: $administrationId);
		if ($error !== null) {
			return $error;
		}

		$yearError = $this->requireYear(financialYear: $financialYear);
		if ($yearError !== null) {
			return $yearError;
		}

		$allowed = ['per_asset_afpelmethode', 'flat_rate_25pct', 'cost_plus'];
		if (in_array($method, $allowed, true) === false) {
			return new JSONResponse(
				['error' => 'methode must be one of ' . implode(', ', $allowed)],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$aggregation = $this->aggregation->aggregate(
				administrationId: $administrationId,
				financialYear: (int)$financialYear
			);

			$sbr = $this->sbrExport->toSbrInstancePayload(
				aggregation: $aggregation,
				administrationId: $administrationId,
				financialYear: (int)$financialYear,
				method: $method
			);

			$pdf = $this->sbrExport->toPdfRenderContext(
				aggregation: $aggregation,
				administrationId: $administrationId,
				financialYear: (int)$financialYear,
				method: $method
			);
		} catch (\Throwable $e) {
			return $this->fail(
				message: 'Failed to render innovatiebox SBR/PDF export',
				context: [
					'administrationId' => $administrationId,
					'financialYear' => $financialYear,
					'method' => $method,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return new JSONResponse(
			['sbr' => $sbr, 'pdf' => $pdf],
			Http::STATUS_OK
		);

	}//end export()

	/**
	 * Validate the administration_id parameter (REQ-IBA-008).
	 *
	 * @param string $administrationId The raw parameter value.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when valid.
	 */
	private function requireAdministration(string $administrationId): ?JSONResponse {
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administration_id must be a valid administration identifier'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end requireAdministration()

	/**
	 * Validate the boekjaar parameter (4-digit year).
	 *
	 * @param string $financialYear The raw parameter value.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when valid.
	 */
	private function requireYear(string $financialYear): ?JSONResponse {
		if ($financialYear === '' || preg_match('/^\d{4}$/', $financialYear) !== 1) {
			return new JSONResponse(['error' => 'boekjaar must be a 4-digit fiscal year'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end requireYear()

	/**
	 * Log a failure and return a 500 without leaking a stack trace (ADR-005).
	 *
	 * @param string $message Client-facing error message.
	 * @param array<string,mixed> $context Structured log context.
	 *
	 * @return JSONResponse
	 */
	private function fail(string $message, array $context): JSONResponse {
		$this->logger->error('InnovatieboxController: ' . $message, $context);

		return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end fail()
}//end class
