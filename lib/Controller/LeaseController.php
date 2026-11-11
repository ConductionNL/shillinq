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

use OCA\Shillinq\AppInfo\AppIdentity;
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
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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

		return $this->builtSchedule(leaseId: $leaseId, administrationId: $administrationId);
	}//end schedule()

	/**
	 * Build the lease's payment schedule; a failure is a logged 500.
	 *
	 * @param string $leaseId          The lease contract.
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse 200 with the rows and their count.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	private function builtSchedule(string $leaseId, string $administrationId): JSONResponse {
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
	}//end builtSchedule()

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

		$ask = new DisclosureRequest(request: $this->request);
		$invalid = $this->validateIdentifiers(
			identifiers: ['administration_id' => $ask->administrationId, 'fiscal_period' => $ask->fiscalPeriod]
		) ?? $this->optionRefusal(ask: $ask);
		if ($invalid !== null) {
			return $invalid;
		}

		return $this->disclosed(ask: $ask);
	}//end disclosure()

	/**
	 * The 400 for an unknown format, then for an unknown language.
	 *
	 * Allowlist, not a pattern: an unknown format must 400 rather than
	 * fall through to the JSON default, so a typo'd `?format=cvs` cannot
	 * silently hand the operator the wrong representation.
	 *
	 * @param DisclosureRequest $ask The request.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function optionRefusal(DisclosureRequest $ask): ?JSONResponse {
		if (in_array($ask->format, self::DISCLOSURE_FORMATS, true) === false) {
			return new JSONResponse(
				['error' => 'format must be one of: ' . implode(', ', self::DISCLOSURE_FORMATS)],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (in_array($ask->language, self::DISCLOSURE_LANGUAGES, true) === false) {
			return new JSONResponse(
				['error' => 'language must be one of: ' . implode(', ', self::DISCLOSURE_LANGUAGES)],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end optionRefusal()

	/**
	 * Generate the disclosure table and answer it in the asked format; a
	 * failure is a logged 500.
	 *
	 * @param DisclosureRequest $ask The validated request.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	private function disclosed(DisclosureRequest $ask): JSONResponse|DataDownloadResponse {
		try {
			$table = $this->disclosureService->generateForPeriod(
				administrationId: $ask->administrationId,
				fiscalPeriod: $ask->fiscalPeriod
			);

			return $this->rendered(ask: $ask, table: $table);
		} catch (\Throwable $e) {
			return $this->disclosureFailed(ask: $ask, e: $e);
		}//end try
	}//end disclosed()

	/**
	 * The table as a CSV download, or as JSON: the PDF note, the XBRL facts or the table itself.
	 *
	 * @param DisclosureRequest    $ask   The request.
	 * @param array<string, mixed> $table The disclosure table.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 */
	private function rendered(DisclosureRequest $ask, array $table): JSONResponse|DataDownloadResponse {
		if ($ask->format === 'csv') {
			return new DataDownloadResponse(
				$this->disclosureService->exportToCSV(disclosure: $table),
				'lease-disclosure-' . $ask->fiscalPeriod . '.csv',
				'text/csv'
			);
		}

		return new JSONResponse($this->jsonBody(ask: $ask, table: $table), Http::STATUS_OK);
	}//end rendered()

	/**
	 * The JSON body of a format: the PDF note, the XBRL facts, or the table.
	 *
	 * @param DisclosureRequest    $ask   The request.
	 * @param array<string, mixed> $table The disclosure table.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonBody(DisclosureRequest $ask, array $table): array {
		if ($ask->format === 'pdf') {
			return $this->disclosureService->exportDisclosureNoteToPDF(
				disclosure: $table,
				language: $ask->language
			);
		}

		if ($ask->format === 'xbrl') {
			return $this->disclosureService->exportToXBRL(disclosure: $table);
		}

		return $table;
	}//end jsonBody()

	/**
	 * Log a failed disclosure and answer 500 without internals.
	 *
	 * @param DisclosureRequest $ask The request.
	 * @param \Throwable        $e   The failure.
	 *
	 * @return JSONResponse
	 */
	private function disclosureFailed(DisclosureRequest $ask, \Throwable $e): JSONResponse {
		$this->logger->error(
			'LeaseController: failed to generate disclosure table',
			[
				'administrationId' => $ask->administrationId,
				'fiscalPeriod' => $ask->fiscalPeriod,
				'format' => $ask->format,
				'exception' => $e->getMessage(),
			]
		);

		return new JSONResponse(['error' => 'Failed to generate disclosure table'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end disclosureFailed()

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
