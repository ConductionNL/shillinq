<?php

/**
 * OSS (One-Stop-Shop) Controller
 *
 * Tier-2 read API for the Union One-Stop-Shop pipeline. Exposes two GET
 * endpoints, both available to any authenticated user (#[NoAdminRequired]):
 * destination-country VAT-rate resolution (REQ-OSS-001, no administration
 * parameter) and quarterly draft-return generation (REQ-OSS-004), which is
 * authorised against the caller's administration memberships here
 * (AdministrationContextService::canAccess(), ADR-005 / REQ-MA-001). Threshold
 * evaluation (REQ-OSS-002) is enforced server-side at invoice-save time through
 * OssThresholdGuard, not as a client endpoint.
 *
 * ⚠️ This paragraph used to say both endpoints were "scoped to a server-validated
 * administration so no cross-administration data leaks" and that reads were
 * "delegated to OpenRegister's ObjectService, which enforces multitenancy /
 * RBAC". The "server validation" was `preg_match(self::ID_PATTERN, …)`, and
 * OpenRegister received no administration term. No mutation
 * routes — OSS records are created/transitioned through the OpenRegister lifecycle.
 * No stack traces are returned to the client.
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\OssRateResolver;
use OCA\Shillinq\Service\OssReturnGenerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET endpoints for OSS rate resolution and quarterly return generation.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
class OssController extends Controller {
	/**
	 * Identifier validation pattern for short slugs (administration / period).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param OssRateResolver $rateResolver Destination-country VAT-rate resolver.
	 * @param OssReturnGenerator $returnGenerator Quarterly return draft generator.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 * @param IUserSession $userSession The user session.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly OssRateResolver $rateResolver,
		private readonly OssReturnGenerator $returnGenerator,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the destination-country VAT rate for an invoice (REQ-OSS-001).
	 *
	 * Query params: country (ISO alpha-2), category (rate category), date (YYYY-MM-DD).
	 * Returns 200 with the resolved ossContext fields, 400 on bad input, or 404 with
	 * `oss.rate.missing` when no rate is in force on the date.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function resolveRate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		// JUSTIFY (security-endpoint-guards REQ-001): intentionally reachable by
		// any authenticated user with no per-object/administration check. This
		// endpoint resolves a VAT rate from the TEDB reference table by
		// (country, category, date) only — there is no `administrationId` param
		// (see class docblock) and OssRateResolver::resolve() takes no tenant
		// argument anywhere in its signature. The published EU VAT rate for a
		// given country/category/date is the same fact for every caller; there
		// is no per-tenant object here for an IDOR to target.
		$country = strtoupper(trim((string)$this->request->getParam('country', '')));
		$category = trim((string)$this->request->getParam('category', 'standard'));
		$date = trim((string)$this->request->getParam('date', ''));

		if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
			return new JSONResponse(['error' => 'country must be an ISO 3166-1 alpha-2 code'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return new JSONResponse(['error' => 'date must be YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->rateResolver->isOssDestination(countryCode: $country) === false) {
			return new JSONResponse(['error' => 'country is not an OSS destination (EU, non-NL)'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$resolved = $this->rateResolver->resolve(countryCode: $country, rateCategory: $category, invoiceDate: $date);
		} catch (\Throwable $e) {
			$this->logger->error('OssController: rate resolution failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to resolve VAT rate'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($resolved === null) {
			return new JSONResponse(
				['error' => 'oss.rate.missing', 'hint' => 'Run the TEDB refresh job or contact support.'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($resolved, Http::STATUS_OK);
	}//end resolveRate()

	/**
	 * Generate a draft OSS return for a quarter (REQ-OSS-004).
	 *
	 * Query params: administration_id, period_year (YYYY), period_quarter (Q1..Q4),
	 * registration_id. Returns 200 with the draft return payload or 400 on bad input.
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * `$this->context->canAccess($administrationId)` below (added for #520,
	 * see class docblock) is an enforced per-administration membership check,
	 * not a syntactic no-op; a non-member gets a masked 404 before the draft
	 * is generated. No code change needed here; recorded in the change's
	 * verdict table for traceability.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function generateReturn(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$periodYear = (int)$this->request->getParam('period_year', 0);
		$periodQuarter = trim((string)$this->request->getParam('period_quarter', ''));
		$registrationId = trim((string)$this->request->getParam('registration_id', ''));

		if (preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($periodYear < 2000 || $periodYear > 2200) {
			return new JSONResponse(['error' => 'period_year must be a valid year'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($periodQuarter, ['Q1', 'Q2', 'Q3', 'Q4'], true) === false) {
			return new JSONResponse(['error' => 'period_quarter must be one of Q1..Q4'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $registrationId) !== 1) {
			return new JSONResponse(['error' => 'registration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		// ⚠️ `preg_match(self::ID_PATTERN, ...)` is a character-class test, not a
		// "server-validated administration". This draft exposes cross-border EU
		// OSS turnover per member state, so the membership check belongs here
		// (ADR-005 / REQ-MA-001).
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$draft = $this->returnGenerator->generateDraft(
				administrationId: $administrationId,
				periodYear: $periodYear,
				periodQuarter: $periodQuarter,
				registrationId: $registrationId
			);
		} catch (\Throwable $e) {
			$this->logger->error('OssController: return generation failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Failed to generate OSS return'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($draft, Http::STATUS_OK);
	}//end generateReturn()
}//end class
