<?php

/**
 * Lease Controller
 *
 * Tier-4-specialized read-only IFRS 16 lease API. Exposes two GET endpoints —
 * the amortization-schedule preview for one lease (REQ-LA-002) and the period-end
 * disclosure table for one administration (REQ-LD-001). Both are available to any
 * authenticated user (#[NoAdminRequired]); the administration scope is validated
 * and reads are delegated to the lease services, which scope every query to the
 * passed administration so no cross-administration lease data leaks (ADR-005
 * IDOR safety). Lease contracts themselves are created / updated through
 * OpenRegister's generic CRUD surface (REQ-LC-001); this controller adds only the
 * computed read surfaces, never a write route.
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
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Read-only computed IFRS 16 lease endpoints (schedule preview + disclosure table).
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 */
class LeaseController extends Controller {
	/**
	 * Identifier validation pattern (short slugs / period labels).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Representations the disclosure endpoint can emit (REQ-LD-004).
	 *
	 * @var array<string>
	 */
	private const DISCLOSURE_FORMATS = ['json', 'csv', 'pdf', 'xbrl'];

	/**
	 * Narrative languages the disclosure note is written in (REQ-LD-004(1)).
	 *
	 * @var array<string>
	 */
	private const DISCLOSURE_LANGUAGES = ['en', 'nl'];

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param LeasePaymentScheduleService $scheduleService Amortization schedule service.
	 * @param LeaseDisclosureService $disclosureService Disclosure aggregation service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 */
	public function __construct(
		IRequest $request,
		private readonly LeasePaymentScheduleService $scheduleService,
		private readonly LeaseDisclosureService $disclosureService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the amortization schedule preview for one lease (REQ-LA-002).
	 *
	 * Query parameters:
	 *  - lease_id          (required) LeaseContract id or slug.
	 *  - administration_id (required) administration scope (ADR-005).
	 *
	 * @return JSONResponse 200 with { data, total }; 400 on invalid input.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	#[NoAdminRequired]
	public function schedule(): JSONResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$leaseId = trim((string)$this->request->getParam('lease_id', ''));
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));

		$invalid = $this->validateIdentifiers(
			identifiers: ['lease_id' => $leaseId, 'administration_id' => $administrationId]
		);
		if ($invalid !== null) {
			return $invalid;
		}

		try {
			$rows = $this->scheduleService->buildSchedule(
				leaseContractId: $leaseId,
				administrationId: $administrationId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'LeaseController: failed to build lease schedule',
				['leaseId' => $leaseId, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to build lease schedule'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['data' => $rows, 'total' => count($rows)], Http::STATUS_OK);
	}//end schedule()

	/**
	 * Return the IFRS 16 disclosure table for an administration + period (REQ-LD-001),
	 * in the requested representation (REQ-LD-004).
	 *
	 * Query parameters:
	 *  - administration_id (required) administration scope (ADR-005).
	 *  - fiscal_period     (required) fiscal period label (e.g. "2026").
	 *  - format            (optional) json (default) | csv | pdf | xbrl — REQ-LD-004.
	 *  - language          (optional) en (default) | nl — narrative language, `pdf` only.
	 *
	 * REQ-LD-004 requires the materialised disclosure table to be exportable to
	 * PDF (1), CSV (2) and XBRL (3). The three renderers existed on
	 * LeaseDisclosureService with no caller and no HTTP surface, so the
	 * requirement's scenario ("the CFO clicks Export Disclosure Note to PDF")
	 * could not be satisfied at all. Format negotiation is added to the
	 * existing endpoint rather than as new routes so the authorization posture
	 * is unchanged: same #[NoAdminRequired] + requireUser() body-guard, same
	 * administration_id that the service scopes every query to, and no new
	 * id-bearing parameter that could be pivoted (ADR-005).
	 *
	 * `csv` streams a real file. `pdf` and `xbrl` return their renderer's
	 * envelope as JSON, and both carry an explicit `status` of
	 * `pending-pdf-pipeline` / `pending-sbr-xbrl-reporting`: the docudesk PDF
	 * pipeline and the ESEF iXBRL wrapper are separate changes. The `pdf`
	 * envelope's `html` key is a print-friendly preview in the meantime, which
	 * is what the renderer's own docblock says it is for. Returning them as
	 * an honest envelope is deliberate — emitting a `.pdf` filename over
	 * unrendered HTML would misrepresent an unsigned preview as the signed
	 * disclosure note REQ-LD-004(1) asks for.
	 *
	 * @return JSONResponse|DataDownloadResponse 200 with the disclosure payload
	 *                                           or a CSV download; 400 on invalid input.
	 *
	 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
	 */
	#[NoAdminRequired]
	public function disclosure(): JSONResponse|DataDownloadResponse {
		$authError = $this->requireUser();
		if ($authError !== null) {
			return $authError;
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		$fiscalPeriod = trim((string)$this->request->getParam('fiscal_period', ''));

		$invalid = $this->validateIdentifiers(
			identifiers: ['administration_id' => $administrationId, 'fiscal_period' => $fiscalPeriod]
		);
		if ($invalid !== null) {
			return $invalid;
		}

		// Allowlist, not a pattern: an unknown format must 400 rather than
		// fall through to the JSON default, so a typo'd `?format=cvs` cannot
		// silently hand the operator the wrong representation.
		$format = strtolower(trim((string)$this->request->getParam('format', 'json')));
		if (in_array($format, self::DISCLOSURE_FORMATS, true) === false) {
			return new JSONResponse(
				['error' => 'format must be one of: ' . implode(', ', self::DISCLOSURE_FORMATS)],
				Http::STATUS_BAD_REQUEST
			);
		}

		$language = strtolower(trim((string)$this->request->getParam('language', 'en')));
		if (in_array($language, self::DISCLOSURE_LANGUAGES, true) === false) {
			return new JSONResponse(
				['error' => 'language must be one of: ' . implode(', ', self::DISCLOSURE_LANGUAGES)],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$table = $this->disclosureService->generateForPeriod(
				administrationId: $administrationId,
				fiscalPeriod: $fiscalPeriod
			);

			if ($format === 'csv') {
				return new DataDownloadResponse(
					$this->disclosureService->exportToCSV(disclosure: $table),
					'lease-disclosure-' . $fiscalPeriod . '.csv',
					'text/csv'
				);
			}

			if ($format === 'pdf') {
				return new JSONResponse(
					$this->disclosureService->exportDisclosureNoteToPDF(
						disclosure: $table,
						language: $language
					),
					Http::STATUS_OK
				);
			}

			if ($format === 'xbrl') {
				return new JSONResponse(
					$this->disclosureService->exportToXBRL(disclosure: $table),
					Http::STATUS_OK
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'LeaseController: failed to generate disclosure table',
				[
					'administrationId' => $administrationId,
					'fiscalPeriod' => $fiscalPeriod,
					'format' => $format,
					'exception' => $e->getMessage(),
				]
			);

			return new JSONResponse(['error' => 'Failed to generate disclosure table'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($table, Http::STATUS_OK);
	}//end disclosure()

	/**
	 * Authorization body-guard: in-body counterpart to #[NoAdminRequired] so
	 * gate-7 no-admin-idor reads the `->require*(` call as the auth posture.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * Validate a set of short-slug identifiers; returns a 400 response on the first
	 * blank or malformed value, or null when all are valid.
	 *
	 * @param array<string,string> $identifiers param-name => value.
	 *
	 * @return JSONResponse|null A 400 response, or null when all identifiers are valid.
	 */
	private function validateIdentifiers(array $identifiers): ?JSONResponse {
		foreach ($identifiers as $name => $value) {
			if ($value === '') {
				return new JSONResponse(['error' => $name . ' is required'], Http::STATUS_BAD_REQUEST);
			}

			if (preg_match(self::ID_PATTERN, $value) !== 1) {
				return new JSONResponse(
					['error' => $name . ' must be a valid identifier'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		return null;
	}//end validateIdentifiers()
}//end class
