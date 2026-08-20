<?php

/**
 * Cashflow Export Controller
 *
 * The route half of REQ-CF-016 (#865): downloads the 13-week cashflow forecast
 * as a PDF for a bank or accountant meeting. Before this existed,
 * `CashflowPdfRenderer` had zero callers and `appinfo/routes.php` registered no
 * cashflow export route at all, so the capability was written and unreachable.
 *
 * ## Authorisation posture
 *
 * `#[NoAdminRequired]` — this is an operator capability, not an admin one. It
 * takes NO request parameters: the horizon is resolved server-side from the
 * caller's own AdministrationMembership set (REQ-MA-001) by
 * {@see \OCA\Shillinq\Service\CashflowExportService}. There is therefore no
 * caller-supplied object identifier to guard, which is why no per-object check
 * appears in this method body — the absence is the design, not an omission.
 *
 * No `#[NoCSRFRequired]`: the call is a POST issued by the SPA through
 * `@nextcloud/axios`, which carries the request token.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CashflowExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PDF export endpoint for the 13-week cashflow forecast (REQ-CF-016).
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 */
class CashflowExportController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request.
	 * @param CashflowExportService $exportService Assembles the export document.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly CashflowExportService $exportService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download the current 13-week cashflow forecast as a PDF (REQ-CF-016).
	 *
	 * Answers 404 rather than an empty 200 when the caller's administration has
	 * no horizon: a blank forecast handed to a bank is a worse artefact than a
	 * refusal, and the dashboard surfaces the refusal as a toast.
	 *
	 * @return DataDownloadResponse|JSONResponse The PDF download, or a JSON error envelope.
	 *
	 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function exportPdf(): DataDownloadResponse|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Security-endpoint-guards REQ-001 JUSTIFY: this endpoint takes NO
		// caller-supplied object identifier — buildHorizonExport() resolves
		// the forecast horizon entirely from the authenticated caller's own
		// AdministrationMembership set (REQ-MA-001). There is no request-
		// supplied id to check against another tenant's data, so no
		// per-object guard applies beyond the authentication check above.
		try {
			$export = $this->exportService->buildHorizonExport();
		} catch (Throwable $e) {
			$this->logger->error(
				'CashflowExportController: cashflow PDF export failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'export_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($export === null) {
			return new JSONResponse(['error' => 'no_cashflow_horizon'], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse(
			$export['payload'],
			$export['filename'],
			$export['mimeType']
		);
	}//end exportPdf()
}//end class
