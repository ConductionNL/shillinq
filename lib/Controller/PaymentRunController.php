<?php

/**
 * Payment-run export + reconcile controller
 *
 * The REST surface behind the "Export to bank" and "Reconcile / import
 * statement" actions on the PaymentRun detail page (payment-run-sepa-export):
 *
 *   POST /api/v1/payment-runs/{id}/export    — generate the SEPA pain.001 / CSV
 *                                               bank file, store + tag it, set
 *                                               exportedFileRef / exportedAt and
 *                                               drive approved → exported.
 *   POST /api/v1/payment-runs/{id}/reconcile — import a CAMT.053 statement,
 *                                               match its booked entries to the
 *                                               run's lines, set reconciledAt and
 *                                               drive exported → reconciled on a
 *                                               full match (partial stays
 *                                               exported with a mismatch note).
 *
 * Both endpoints are authenticated (#[NoAdminRequired]) with a manual
 * user-session guard, and authorise the caller for the resolved PaymentRun's
 * administration via AdministrationContextService::canAccess — IDOR-safe per
 * ADR-005; the controller never trusts a client-supplied administrationId.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * HTTP API for exporting + reconciling a PaymentRun.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
class PaymentRunController extends Controller {

	/**
	 * Register slug all shillinq objects live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'shillinq';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request Request.
	 * @param PaymentRunExportService $exportService SEPA export orchestration.
	 * @param PaymentRunReconciliationService $reconciliationService CAMT.053 reconciliation.
	 * @param AdministrationContextService $administrationContext Tenant scope (ADR-005 guard).
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		IRequest $request,
		private readonly PaymentRunExportService $exportService,
		private readonly PaymentRunReconciliationService $reconciliationService,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Export an approved PaymentRun to a SEPA bank file
	 * (POST /api/v1/payment-runs/{id}/export).
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * `$this->administrationContext->canAccess()` below is an enforced
	 * per-administration membership check (masked 404 on denial), not a
	 * syntactic no-op. The mechanical `hydra-gate-no-admin-idor` scan flagged
	 * this method only because `canAccess(` is not shaped like
	 * `authorize*`/`require*`/`ensure*` — a documented false positive, not a
	 * missing guard. No code change needed; recorded in the change's verdict
	 * table for traceability.
	 *
	 * @param string $id The PaymentRun id / uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function export(string $id): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$run = $this->resolveRun(id: $id);
			if ($run === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			// ADR-005 authorisation guard: the caller must be authorised for the
			// run's (server-resolved) administration.
			$administrationId = (string)($run['administrationId'] ?? '');
			if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
				// Mask as 404 — never leak the run's existence to an unauthorised caller.
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			$result = $this->exportService->export(paymentRun: $run);

			if (isset($result['error']) === true) {
				$status = Http::STATUS_UNPROCESSABLE_ENTITY;
				if ($result['error'] === 'not-approved') {
					$status = Http::STATUS_CONFLICT;
				}

				return new JSONResponse($result, $status);
			}

			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error('PaymentRunController.export failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end export()

	/**
	 * Reconcile an exported PaymentRun against a CAMT.053 statement
	 * (POST /api/v1/payment-runs/{id}/reconcile).
	 *
	 * Re-verified during security-endpoint-guards (REQ-001): ALREADY-GUARDED —
	 * same enforced `canAccess()` membership check as {@see export()}; a
	 * false positive of the mechanical scan, not a missing guard.
	 *
	 * @param string $id The PaymentRun id / uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function reconcile(string $id): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$run = $this->resolveRun(id: $id);
			if ($run === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			$administrationId = (string)($run['administrationId'] ?? '');
			if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			$contents = $this->readStatement();
			if (trim($contents) === '') {
				return new JSONResponse(['error' => 'Empty statement — nothing to reconcile'], Http::STATUS_BAD_REQUEST);
			}

			$result = $this->reconciliationService->reconcile(paymentRun: $run, contents: $contents);

			if (isset($result['error']) === true) {
				$status = Http::STATUS_UNPROCESSABLE_ENTITY;
				if ($result['error'] === 'not-exported') {
					$status = Http::STATUS_CONFLICT;
				}

				return new JSONResponse($result, $status);
			}

			return new JSONResponse($result, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error('PaymentRunController.reconcile failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end reconcile()

	/**
	 * Resolve a PaymentRun by id through OpenRegister, normalised to an array.
	 *
	 * @param string $id The PaymentRun id / uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveRun(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$run = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema('PaymentRun')
			->find($id);

		if ($run === null) {
			return null;
		}

		// ADR-084: find() is declared `: ?ObjectEntityInterface`, which extends
		// JsonSerializable — the is_array() arm below it was unreachable by type.
		return (array)$run->jsonSerialize();
	}//end resolveRun()

	/**
	 * Read the CAMT.053 statement from a multipart upload or a JSON/raw body.
	 *
	 * Resolution order: a 'file' multipart upload first; then a JSON body with
	 * 'contents' (raw or base64); then plain request params.
	 *
	 * @return string The raw CAMT.053 XML (empty when none supplied).
	 */
	private function readStatement(): string {
		// 1) Multipart file upload.
		$uploaded = $this->request->getUploadedFile('file');
		if (is_array($uploaded) === true && isset($uploaded['tmp_name']) === true
			&& is_uploaded_file((string)$uploaded['tmp_name']) === true
		) {
			$raw = file_get_contents((string)$uploaded['tmp_name']);
			if ($raw === false) {
				return '';
			}

			return $raw;
		}

		// 2) JSON body with raw/base64 contents.
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== false && $rawBody !== '') {
			$decoded = json_decode($rawBody, true);
			if (is_array($decoded) === true) {
				$contents = (string)($decoded['contents'] ?? '');
				if ($contents !== '' && ($decoded['encoding'] ?? '') === 'base64') {
					$maybe = base64_decode($contents, true);
					if ($maybe !== false) {
						$contents = $maybe;
					}
				}

				return $contents;
			}
		}

		// 3) Plain request param fallback.
		return (string)$this->request->getParam('contents', '');
	}//end readStatement()
}//end class
