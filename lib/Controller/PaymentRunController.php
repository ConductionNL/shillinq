<?php

/**
 * Payment-run export + reconcile controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\PaymentRunProposalService;
use OCA\Shillinq\PaymentRun\PaymentRunReconciliationService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
	 * @param AdministrationContextServiceInterface $administrationContext Tenant scope (ADR-005 guard).
	 * @param IUserSession $session User session.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param PaymentRunProposalService $proposalService Drafts a run from the invoices due (banking-payment-run REQ-BPR-002).
	 */
	public function __construct(
		IRequest $request,
		private readonly PaymentRunExportService $exportService,
		private readonly PaymentRunReconciliationService $reconciliationService,
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly PaymentRunProposalService $proposalService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
			$run = $this->accessibleRun(id: $id);
			if ($run instanceof JSONResponse) {
				return $run;
			}

			return $this->resultResponse(result: $this->exportService->export(paymentRun: $run), conflictError: 'not-approved');
		} catch (\Throwable $e) {
			$this->logger->error('PaymentRunController.export failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end export()

	/**
	 * The run the caller may act on, or the 404 that answers it.
	 *
	 * ADR-005 authorisation guard: the caller must be authorised for the
	 * run's (server-resolved) administration; a refusal is masked as 404 —
	 * never leak the run's existence to an unauthorised caller.
	 *
	 * @param string $id The PaymentRun id / uuid.
	 *
	 * @return array<string, mixed>|JSONResponse The run, or the 404.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function accessibleRun(string $id): array|JSONResponse {
		$run = $this->resolveRun(id: $id);
		if ($run === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$administrationId = (string)($run['administrationId'] ?? '');
		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return $run;
	}//end accessibleRun()

	/**
	 * Answer a service result: 200, or for an error 409 when it is the
	 * conflicting-state error and 422 otherwise.
	 *
	 * @param array<string, mixed> $result        The service result.
	 * @param string               $conflictError The error that means the run is in the wrong state.
	 *
	 * @return JSONResponse
	 */
	private function resultResponse(array $result, string $conflictError): JSONResponse {
		if (isset($result['error']) === false) {
			return new JSONResponse($result, Http::STATUS_OK);
		}

		$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		if ($result['error'] === $conflictError) {
			$status = Http::STATUS_CONFLICT;
		}

		return new JSONResponse($result, $status);
	}//end resultResponse()

	/**
	 * Propose a draft payment run from the supplier invoices due.
	 *
	 * Request: administrationId, dueOnOrBefore, debtorAccountIban,
	 * executionDate, payOnDueDate. 201 with the draft run and the invoices
	 * left out; 422 when nothing is payable; 403 outside the administration.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
	 */
	#[NoAdminRequired]
	public function propose(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '' || $this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not allowed for this administration'], Http::STATUS_FORBIDDEN);
		}

		$criteria = new DraftRunCriteria(request: $this->request, administrationId: $administrationId);
		if ($criteria->isComplete() === false) {
			return new JSONResponse(['error' => 'invalid-request'], Http::STATUS_BAD_REQUEST);
		}

		return $this->proposed(criteria: $criteria);

	}//end propose()

	/**
	 * Draft the run; a failing proposal is a logged 500.
	 *
	 * @param DraftRunCriteria $criteria The complete proposal form.
	 *
	 * @return JSONResponse 201 with the draft run, 422 when nothing is due, or 500.
	 *
	 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
	 */
	private function proposed(DraftRunCriteria $criteria): JSONResponse {
		try {
			$result = $this->proposalService->propose(
				administrationId: $criteria->administrationId,
				dueOnOrBefore: $criteria->dueOnOrBefore,
				debtorAccountIban: $criteria->debtorIban,
				executionDate: $criteria->executionDate,
				lineDates: $this->lineDates()
			);
		} catch (\Throwable $e) {
			$this->logger->error('PaymentRunController: proposal failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'proposal-failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $this->proposalResponse(result: $result);
	}//end proposed()

	/**
	 * 201 with the draft run, or 422 with the skipped list when nothing is due.
	 *
	 * @param array<string, mixed> $result The proposal result.
	 *
	 * @return JSONResponse
	 */
	private function proposalResponse(array $result): JSONResponse {
		if ($result['paymentRun'] === null) {
			return new JSONResponse($result + ['error' => 'nothing-due'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end proposalResponse()

	/**
	 * The proposal's line date mode from the payOnDueDate flag.
	 *
	 * @return string
	 */
	private function lineDates(): string {
		if (filter_var($this->request->getParam('payOnDueDate', false), FILTER_VALIDATE_BOOLEAN) === true) {
			return PaymentRunProposalService::DATES_DUE;
		}

		return PaymentRunProposalService::DATES_RUN;

	}//end lineDates()

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
			$run = $this->accessibleRun(id: $id);
			if ($run instanceof JSONResponse) {
				return $run;
			}

			return $this->reconciled(run: $run);
		} catch (\Throwable $e) {
			$this->logger->error('PaymentRunController.reconcile failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end reconcile()

	/**
	 * Reconcile the run against the supplied statement.
	 *
	 * @param array<string, mixed> $run The accessible PaymentRun.
	 *
	 * @return JSONResponse 400 on an empty statement, else the mapped service result.
	 *
	 * @spec openspec/specs/payment-run-sepa-export/spec.md
	 */
	private function reconciled(array $run): JSONResponse {
		$contents = $this->readStatement();
		if (trim($contents) === '') {
			return new JSONResponse(['error' => 'Empty statement — nothing to reconcile'], Http::STATUS_BAD_REQUEST);
		}

		return $this->resultResponse(
			result: $this->reconciliationService->reconcile(paymentRun: $run, contents: $contents),
			conflictError: 'not-exported'
		);
	}//end reconciled()

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

		$run = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister(self::REGISTER_SLUG)->setSchema('PaymentRun'),
			id: $id
		);

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
		$reader = new StatementBodyReader(request: $this->request);

		// 1) Multipart file upload.
		$uploaded = $reader->uploadedContents();
		if ($uploaded !== null) {
			return $uploaded;
		}

		// 2) JSON body with raw/base64 contents.
		$decoded = $reader->jsonBody();
		if ($decoded !== null) {
			return $reader->bodyContents(decoded: $decoded);
		}

		// 3) Plain request param fallback.
		return (string)$this->request->getParam('contents', '');
	}//end readStatement()
}//end class
