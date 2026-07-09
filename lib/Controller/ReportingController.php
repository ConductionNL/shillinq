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
 * only admins, work with reports. The download endpoint resolves the GeneratedReport
 * record and its stored Nextcloud file through the service, which scopes file access
 * to the current user's Files home.
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
 * @spec openspec/changes/reporting-compliance-consolidation/specs/reporting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Reporting\ReportCatalogue;
use OCA\Shillinq\Reporting\ReportGenerationService;
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
 * @spec openspec/changes/reporting-compliance-consolidation/specs/reporting/spec.md
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request     The current request.
     * @param IUserSession            $userSession Anonymous-rejection guard (ADR-005).
     * @param ReportGenerationService $service     The generation/orchestration service.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly ReportGenerationService $service,
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
     * @return JSONResponse The grouped catalogue.
     */
    #[NoAdminRequired]
    public function types(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
        }

        $groups = [];
        foreach (array_keys(ReportCatalogue::CATEGORIES) as $categoryId) {
            $groups[$categoryId] = [];
        }

        foreach (ReportCatalogue::all() as $report) {
            $categoryId = (string) ($report['category'] ?? '');
            if (isset($groups[$categoryId]) === false) {
                $groups[$categoryId] = [];
            }

            $groups[$categoryId][] = $report;
        }

        return new JSONResponse(
            [
                'categories' => ReportCatalogue::CATEGORIES,
                'groups'     => $groups,
            ]
        );

    }//end types()

    /**
     * POST /api/reporting/generate — generate a report.
     *
     * Reads `reportType`, `period`, `administrationId` and `format` from the request,
     * delegates to the service and returns the recorded GeneratedReport (incl. fileId
     * + downloadPath). A service-level `{ error: ... }` envelope is surfaced as 422.
     *
     * @return JSONResponse The GeneratedReport record, or an error envelope.
     */
    #[NoAdminRequired]
    public function generate(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
        }

        $reportType       = trim((string) $this->request->getParam('reportType', ''));
        $period           = trim((string) $this->request->getParam('period', ''));
        $administrationId = trim((string) $this->request->getParam('administrationId', ''));
        $format           = trim((string) $this->request->getParam('format', ''));

        if ($reportType === '') {
            return new JSONResponse(['error' => 'missing-report-type'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->service->generate(
            reportType: $reportType,
            period: $period,
            administrationId: $administrationId,
            format: $format,
        );

        if (isset($result['error']) === true) {
            return new JSONResponse($result, Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new JSONResponse($result, Http::STATUS_CREATED);

    }//end generate()

    /**
     * GET /api/reporting/generated — list previously generated reports.
     *
     * Optional query filters: reportType, period, administrationId, category.
     *
     * @return JSONResponse `{ reports: [...] }`.
     */
    #[NoAdminRequired]
    public function generated(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
        }

        $filters = [
            'reportType'       => $this->request->getParam('reportType'),
            'period'           => $this->request->getParam('period'),
            'administrationId' => $this->request->getParam('administrationId'),
            'category'         => $this->request->getParam('category'),
        ];

        return new JSONResponse(['reports' => $this->service->listGenerated($filters)]);

    }//end generated()

    /**
     * GET /api/reporting/download/{id} — stream a stored report file.
     *
     * Resolves the GeneratedReport record and its Nextcloud file (scoped to the
     * current user's Files home) and returns it as a download. Missing records/files
     * yield 404.
     *
     * @param string $id The GeneratedReport id.
     *
     * @return DataDownloadResponse|JSONResponse The streamed file, or a 404 JSON envelope.
     */
    #[NoAdminRequired]
    public function download(string $id): DataDownloadResponse|JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
        }

        if (trim($id) === '') {
            return new JSONResponse(['error' => 'missing-id'], Http::STATUS_BAD_REQUEST);
        }

        $file = $this->service->resolveFile($id);
        if ($file === null) {
            return new JSONResponse(['error' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $content  = $file->getContent();
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
