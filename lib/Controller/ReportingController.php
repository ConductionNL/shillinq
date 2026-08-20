<?php

/**
 * Shillinq Reporting & Compliance controller
 *
 * The HTTP surface for the consolidated "Reporting & Compliance" section. It
 * exposes the static report catalogue grouped by category (the overview cards),
 * triggers generation of a chosen report (rendering + Files storage + tagging +
 * recording delegated to {@see \OCA\Shillinq\Reporting\ReportGenerationService}),
 * lists the previously generated reports, and streams a stored report file back to
 * the browser for download.
 *
 * Every endpoint is `#[NoAdminRequired]` — finance officers and controllers, not
 * only admins, work with reports — and every endpoint that names an
 * administration or a stored report is authorised here against the caller's
 * memberships (AdministrationContextService, ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph previously stated that the service "scopes file access to
 * the current user's Files home". It did not: `resolveFile()` used
 * `IRootFolder::getById()` and `IRootFolder::get($path)`, both of which resolve
 * across EVERY user's storage, and `generated()` treated `administrationId` as
 * an OPTIONAL filter, so omitting it listed every tenant's reports together
 * with the ids that feed `download/{id}`. Both are fixed; the sentence is
 * retained as a warning because it is the reason nobody looked.
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
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * HTTP surface for Reporting & Compliance: catalogue, generation, listing, download.
 *
 * @spec exclude The reporting capability has no canonical spec. This tag pointed at
 *       openspec/changes/reporting-compliance-consolidation (a change directory that
 *       exists neither under changes nor under changes/archive), and no canonical
 *       reporting capability exists under openspec/specs either. Tracked in #525.
 *       Deliberately NOT resolved by writing that spec — authoring the requirement
 *       a tag is checked against turns the gate green over an unspecified capability.
 *
 * KNOWINGLY DANGLING — do not repoint this tag (gate-46, shillinq#499).
 * The change directory it names was never committed, and the `reporting`
 * capability has NO canonical spec. One was drafted during gate remediation
 * and withdrawn: a spec written to fit the code, by the process whose job is
 * to check the code against a spec, is not a specification anyone agreed to.
 * Authoring it is the capability owner's decision, not a gate fix. No existing
 * target is honest either — bookkeeping-iv3-reporting REQ-IV3-004 and
 * bookkeeping-vat-btw-filing REQ-VBTW-004 forbid the PHP renderers in this
 * directory, so pointing there would report conformance to a rule this code
 * breaks.
 */
class ReportingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 * @param IUserSession $userSession Anonymous-rejection guard (ADR-005).
	 * @param ReportGenerationService $service The generation/orchestration service.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ReportGenerationService $service,
		private readonly AdministrationContextService $context,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/reporting/types — the report catalogue grouped by category.
	 *
	 * Returns `{ categories: { <id>: <label>, ... }, groups: { <categoryId>: [report, ...] } }`
	 * so the overview can render one section per ReportCatalogue::CATEGORIES with its
	 * report cards, in catalogue order.
	 *
	 * JUSTIFY (security-endpoint-guards, REQ-001b): `ReportCatalogue::CATEGORIES`
	 * and `ReportCatalogue::all()` are a compile-time constant list of report
	 * definitions (id, label, category, supported formats) baked into
	 * `ReportCatalogue.php` — the method names no administration, tenant, or
	 * object and reads no per-tenant data, so there is nothing here to scope
	 * per-object. Any authenticated user may read the catalogue; only
	 * `generate()`/`generated()`/`download()` touch actual report data and
	 * those already check `AdministrationContextService::canAccess()`.
	 *
	 * @return JSONResponse The grouped catalogue.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude Consumed by ReportingComplianceOverview.vue (a real UI surface),
	 *      but this change adds no code here beyond a justification comment — no
	 *      guard/behaviour change requires new e2e coverage (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function types(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		$groups = [];
		foreach (array_keys(ReportCatalogue::CATEGORIES) as $categoryId) {
			$groups[$categoryId] = [];
		}

		foreach (ReportCatalogue::all() as $report) {
			$categoryId = (string)($report['category'] ?? '');
			if (isset($groups[$categoryId]) === false) {
				$groups[$categoryId] = [];
			}

			$groups[$categoryId][] = $report;
		}

		return new JSONResponse(
			[
				'categories' => ReportCatalogue::CATEGORIES,
				'groups' => $groups,
			]
		);

	}//end types()

	/**
	 * POST /api/reporting/generate — generate a report.
	 *
	 * Reads `reportType`, `period`, `administrationId` and `format` from the request,
	 * delegates to the service and returns the recorded GeneratedReport (incl. fileId
	 * + downloadPath). A service-level `{ error: 'docudesk-unavailable' }` envelope
	 * (docudesk not installed/reachable — REQ-RVD-005, ADR-081 rule 7) is surfaced as
	 * 503; any other `{ error: ... }` envelope is surfaced as 422.
	 *
	 * @return JSONResponse The GeneratedReport record, or an error envelope.
	 *
	 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
	 */
	#[NoAdminRequired]
	public function generate(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		$reportType = trim((string)$this->request->getParam('reportType', ''));
		$period = trim((string)$this->request->getParam('period', ''));
		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		$format = trim((string)$this->request->getParam('format', ''));

		if ($reportType === '') {
			return new JSONResponse(['error' => 'missing-report-type'], Http::STATUS_BAD_REQUEST);
		}

		// ADR-005 / REQ-MA-001 — generating a report against another tenant's
		// ledger. An empty administrationId means "no administration scope" and
		// is left to the service; a NAMED administration must be one of ours.
		if ($administrationId !== '' && $this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->service->generate(
			reportType: $reportType,
			period: $period,
			administrationId: $administrationId,
			format: $format,
		);

		if (isset($result['error']) === true) {
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
			if ($result['error'] === 'docudesk-unavailable') {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
			}

			return new JSONResponse($result, $status);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end generate()

	/**
	 * GET /api/reporting/generated — list previously generated reports.
	 *
	 * Optional query filters: reportType, period, category. `administrationId`
	 * is NOT optional in effect: the listing is always scoped to the caller's
	 * administration memberships (ADR-005 / REQ-MA-001), and an explicit
	 * administrationId may only narrow that scope, never widen it. It used to be
	 * an optional filter, so omitting it returned every tenant's reports — ids
	 * included, which then fed `download/{id}`.
	 *
	 * @return JSONResponse `{ reports: [...] }`.
	 *
	 * @spec exclude No canonical requirement exists for the reporting capability — see #525.
	 */
	#[NoAdminRequired]
	public function generated(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		$requested = trim((string)$this->request->getParam('administrationId', ''));
		$scope = $this->context->accessibleAdministrationIds();
		if ($requested !== '') {
			if ($this->context->canAccess(administrationId: $requested) === false) {
				return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
			}

			$scope = [$requested];
		}

		$reports = [];
		foreach ($scope as $administrationId) {
			$reports = array_merge(
				$reports,
				$this->service->listGenerated(
					[
						'reportType' => $this->request->getParam('reportType'),
						'period' => $this->request->getParam('period'),
						'administrationId' => $administrationId,
						'category' => $this->request->getParam('category'),
					]
				)
			);
		}

		return new JSONResponse(['reports' => $reports]);
	}//end generated()

	/**
	 * GET /api/reporting/download/{id} — stream a stored report file.
	 *
	 * Loads the GeneratedReport record, authorises the caller against the
	 * administration that record belongs to (ADR-005 / REQ-MA-001), and only
	 * then resolves and streams the stored Nextcloud file. A record the caller
	 * has no membership for is masked as 404, never confirmed.
	 *
	 * ⚠️ This docblock used to claim the file was "scoped to the current user's
	 * Files home". It was not — `resolveFile()` went through `IRootFolder`,
	 * which resolves across every user's storage, so `download/{id}` was an
	 * arbitrary file read for any authenticated user.
	 *
	 * @param string $id The GeneratedReport id.
	 *
	 * @return DataDownloadResponse|JSONResponse The streamed file, or a 404 JSON envelope.
	 *
	 * @spec exclude No canonical requirement exists for the reporting capability — see #525.
	 */
	#[NoAdminRequired]
	public function download(string $id): DataDownloadResponse|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($id) === '') {
			return new JSONResponse(['error' => 'missing-id'], Http::STATUS_BAD_REQUEST);
		}

		$record = $this->service->findRecord($id);
		if ($record === null
			|| $this->context->canAccess(administrationId: (string)($record['administrationId'] ?? '')) === false
		) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$file = $this->service->resolveRecordFile(record: $record);
		if ($file === null) {
			return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$content = $file->getContent();
			$fileName = $file->getName();
			$mimeType = $file->getMimeType();
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'not-readable'], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse(
			data: $content,
			filename: $fileName,
			contentType: $mimeType,
		);

	}//end download()
}//end class
