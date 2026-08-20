<?php

/**
 * SEPA Audit Controller
 *
 * Produces the per-mandate audit dossier required for the 7-year Dutch fiscal
 * bewaarplicht (REQ-SDD-010). Assembles the mandate, every collection against
 * it, every R-transaction, every pre-notification, and the archived pain.008 /
 * pain.002 fragments into a ZIP. Invoice PDFs and journal lines are added by
 * the accounts-receivable-core integration once that capability is merged
 * (see deferred tasks in tasks.md).
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\SepaAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Audit-dossier export endpoint for SEPA mandates (REQ-SDD-010).
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class SepaAuditController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request.
	 * @param SepaAuditService $auditService The audit assembly service.
	 * @param IUserSession $userSession Session for the auth body-guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly SepaAuditService $auditService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Export the audit dossier for a single mandate as a ZIP (REQ-SDD-010).
	 *
	 * Scoped to the authenticated user's accessible administrations by the
	 * service (IDOR-safe per ADR-005 / REQ-MA-001): a mandate the caller may not
	 * read yields a 404 rather than leaking its existence.
	 *
	 * ⚠️ This sentence was written before it was true. Until this change the
	 * service scoped to `appConfig('administration_id')` — an instance-wide
	 * constant, never the caller's memberships — and skipped the comparison
	 * entirely when that key was unset (its default). The dossier is mandate +
	 * collections + R-transactions + pre-notifications as a ZIP.
	 *
	 * @param string $mandateId The SepaMandate UUID/slug to export.
	 *
	 * @return DataDownloadResponse|JSONResponse The ZIP download or an error.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	#[NoAdminRequired]
	public function exportMandate(string $mandateId): DataDownloadResponse|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$bundle = $this->auditService->buildMandateDossier(mandateId: $mandateId);
			if ($bundle === null) {
				return new JSONResponse(
					['error' => 'mandate_not_found'],
					Http::STATUS_NOT_FOUND
				);
			}

			return new DataDownloadResponse(
				$bundle['data'],
				$bundle['filename'],
				'application/zip'
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SepaAuditController: mandate export failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'export_failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end exportMandate()
}//end class
