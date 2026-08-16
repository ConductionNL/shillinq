<?php

/**
 * BCF Claim Controller
 *
 * Tier-3 Btw-compensatiefonds (BCF) compensable-VAT API (REQ-BCF-002,
 * REQ-BCF-012). Exposes a single GET endpoint that returns the server-computed
 * per-account compensable-VAT breakdown and quarter total for one administration
 * + claim quarter, used by the BCF-claims detail page to render the breakdown
 * table before the operator submits. The endpoint is available to any
 * authenticated user (#[NoAdminRequired]); the administration scope is validated
 * and reads are delegated to OpenRegister's ObjectService, which enforces
 * multitenancy / RBAC, so no cross-administration data leaks (REQ-BCF-012). The
 * breakdown is read-only (computed) — the claim lifecycle (draft/submit/settle)
 * is driven by the OpenRegister object lifecycle, not by this controller.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BcfClaimService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/bcf-claims/compensation — quarter-scoped compensable-VAT breakdown.
 *
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 */
class BcfClaimController extends Controller {
	/**
	 * Constructor for the BcfClaimController.
	 *
	 * @param IRequest $request The request object.
	 * @param BcfClaimService $bcfClaimService The BCF compensable-VAT computation service.
	 * @param AdministrationContextService $context Administratie-aware IDOR guard (ADR-005).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly BcfClaimService $bcfClaimService,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Return the compensable-VAT breakdown for an administration + quarter (REQ-BCF-002).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (REQ-BCF-010, REQ-BCF-012).
	 *  - claim_quarter     (required) quarter identifier, e.g. 2026-Q1 (REQ-BCF-001).
	 *
	 * Returns HTTP 200 with { administrationId, claimQuarter, totalCompensableAmount,
	 * breakdown } on success; HTTP 400 on a missing/malformed parameter; HTTP 500
	 * (without a stack trace) on an unexpected GL fetch failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	#[NoAdminRequired]
	public function compensation(): JSONResponse {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$claimQuarter = trim((string)$this->request->getParam('claim_quarter', ''));

		if ($administrationId === '') {
			return new JSONResponse(
				['error' => 'administration_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($claimQuarter === '') {
			return new JSONResponse(
				['error' => 'claim_quarter is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Reject obviously malformed identifiers before touching the data layer
		// (REQ-BCF-010) — administration/quarter identifiers are short slugs.
		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid administration identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $claimQuarter) !== 1) {
			return new JSONResponse(
				['error' => 'claim_quarter must be a valid quarter identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Per-object IDOR guard (ADR-005 Rule 3): 403 when user lacks administration membership.
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(
				['error' => 'Access to this administration is not allowed'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$result = $this->bcfClaimService->computeClaim(
				administrationId: $administrationId,
				claimQuarter: $claimQuarter
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BcfClaimController: failed to compute compensable VAT',
				[
					'administrationId' => $administrationId,
					'claimQuarter' => $claimQuarter,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				['error' => 'Failed to compute compensable VAT'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($result, Http::STATUS_OK);
	}//end compensation()
}//end class
