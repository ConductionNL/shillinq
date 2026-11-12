<?php

/**
 * Dunning Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-2 credit-control & dunning ladder API (REQ-CCD-002 .. REQ-CCD-010).
 * Exposes a handful of imperative endpoints layered on top of the declarative
 * registers + the DunningRunService orchestrator:
 *
 *  - POST /api/dunning/bik           — compute the IncassoKostenBerekening for
 *                                      an arbitrary hoofdsom + partyType
 *                                      (REQ-CCD-003). Pure compute, no write.
 *  - POST /api/dunning/runs/execute  — execute a single DunningRun stage
 *                                      (REQ-CCD-002 / 016). Refuses when a
 *                                      DunningPauseDispute is active.
 *  - POST /api/dunning/pauses        — create a DunningPauseDispute (REQ-CCD-004).
 *  - POST /api/dunning/pauses/{id}/resume — resolve / expire the pause.
 *  - POST /api/dunning/writeoffs     — record an OninbaarAfschrijving (REQ-CCD-010).
 *  - POST /api/dunning/incasso/dossier — assemble the stage-5 dossier bundle
 *                                      (REQ-CCD-008).
 *
 * Every route is admin-scope guarded via AdministrationContextService so the
 * server-resolved administration_id is the only trust boundary; no client-
 * supplied tenant id is honoured directly.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use OCA\Shillinq\Service\Dunning\IncassoDossierComposer;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Dunning API surface.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
class DunningController extends Controller {
	/**
	 * Wire the dunning controller dependencies.
	 *
	 * @param IRequest $request NC request.
	 * @param BIKStaffelCalculator $bik Pure BIK + rente calculator.
	 * @param DunningRunService $runs Run orchestrator.
	 * @param IncassoDossierComposer $dossier Stage-5 dossier composer.
	 * @param AdministrationContextService $context Admin-scope context.
	 * @param LoggerInterface $logger Logger.
	 * @param IAppConfig $appConfig App config for the register slug (security-endpoint-guards).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per
	 *                                              ADR-084 — used to fetch a DunningPauseDispute
	 *                                              by id so its OWN administrationId can be
	 *                                              checked (security-endpoint-guards REQ-001).
	 * @param IL10N $l10n Localized error messages (ADR-050).
	 */
	public function __construct(
		IRequest $request,
		private readonly BIKStaffelCalculator $bik,
		private readonly DunningRunService $runs,
		private readonly IncassoDossierComposer $dossier,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compute the IncassoKostenBerekening for a hypothetical hoofdsom.
	 *
	 * Body JSON: { hoofdsom, partyType, dagenVerzuim, administration_id,
	 *              ingangsdatum?, berekendOp?, tariefB2B?, tariefB2C? }.
	 *
	 * @return JSONResponse 200 with the calc shape; 400 on validation; 422 when
	 *                      B2C is below the 14-day grace cut-off.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-13
	 */
	#[NoAdminRequired]
	public function bik(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$query = new BikCalculationRequest(request: $this->request, administrationId: $administrationId);
		$refusal = $this->bikRefusal(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->composeBik(query: $query);
	}//end bik()

	/**
	 * Execute one DunningRun (stage dispatch + evidence capture).
	 *
	 * @return JSONResponse 201 with the persisted DunningRun; 400 on validation;
	 *                      409 when the invoice is paused or admin-error detected.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 *
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function executeRun(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$params = $this->request->getParams();
		if (($params['invoiceId'] ?? '') === '') {
			return new JSONResponse(['error' => 'factuurId is required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->executeStage(administrationId: $administrationId, params: $params);
	}//end executeRun()

	/**
	 * Create a DunningPauseDispute (REQ-CCD-004).
	 *
	 * @return JSONResponse 201 with the persisted pause; 400 on validation.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 */
	#[NoAdminRequired]
	public function pause(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$pause = new DunningPauseRequest(request: $this->request, pausedBy: (string)$this->context->currentUserId());
		if ($pause->isComplete() === false) {
			return new JSONResponse(['error' => 'factuurId + valid reden are required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->createPause(administrationId: $administrationId, pause: $pause);
	}//end pause()

	/**
	 * Resume a DunningPauseDispute.
	 *
	 * @param string $pauseId The pause record id.
	 *
	 * @return JSONResponse 200 with the updated pause; 404 when not found.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-17
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-003
	 *
	 * @e2e exclude API-only endpoint, no UI surface (security-endpoint-guards)
	 */
	#[NoAdminRequired]
	public function resumePause(string $pauseId): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		// GUARD (ownership/tenant) — security-endpoint-guards REQ-001. The
		// check above only confirms the CALLER belongs to the request-
		// supplied administration_id; it never confirms the
		// DunningPauseDispute named by $pauseId actually belongs to that
		// administration. DunningRunService::resumePause() fetches the
		// pause purely by id and never reads its own $administrationId
		// parameter (see that method's own
		// @SuppressWarnings(PHPMD.UnusedFormalParameter) note) — without
		// this check, a member of ANY administration could resume/expire
		// another organisation's dunning pause, and read its details
		// (reason, evidenceRefs), by guessing/enumerating its id.
		if ($this->canAccessPause(pauseId: $pauseId) === false) {
			// Masked 404 — matches the sibling not-found response above so
			// the existence of another tenant's pause is never disclosed.
			return new JSONResponse(['error' => 'Dunning pause not found'], Http::STATUS_NOT_FOUND);
		}

		$resumption = new PauseResumptionRequest(request: $this->request, pauseId: $pauseId);
		if ($resumption->hasKnownResolution() === false) {
			return new JSONResponse(['error' => 'resolution must be resolve or expire'], Http::STATUS_BAD_REQUEST);
		}

		return $this->resume(administrationId: $administrationId, resumption: $resumption);
	}//end resumePause()

	/**
	 * Record an OninbaarAfschrijving (REQ-CCD-010).
	 *
	 * @return JSONResponse 201 with the persisted write-off.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-22
	 */
	#[NoAdminRequired]
	public function writeOff(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$params = $this->request->getParams();
		if ($this->isCompleteWriteOff(params: $params) === false) {
			return new JSONResponse(
				['error' => 'factuurId, hoofdsomAfgeschreven (>0) and art29OBVerklaring are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->recordWriteOff(administrationId: $administrationId, params: $params);
	}//end writeOff()

	/**
	 * Compose the stage-5 incasso dossier bundle (REQ-CCD-008).
	 *
	 * @return JSONResponse 200 with the dossier; 400 on validation.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	#[NoAdminRequired]
	public function dossier(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$incasso = new IncassoTransferRequest(request: $this->request);
		if ($incasso->invoiceId === '' || $incasso->customerId === '') {
			return new JSONResponse(['error' => 'factuurId + klantId required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->composeDossier(administrationId: $administrationId, incasso: $incasso);
	}//end dossier()

	/**
	 * Dispatch the stage-5 incasso dossier to the bound bureau (REQ-CCD-008).
	 *
	 * `dossier()` above composes the bundle and hands it back to the caller;
	 * nothing then SENT it. `DunningRunService::transferToIncasso()` — the
	 * method that performs the dispatch and seals the run on a DELIVERED
	 * outcome — had zero production callers, so the whole of task-20's
	 * transfer half was implemented, unit-tested and spec'd done while being
	 * unreachable at runtime. This route is the missing half.
	 *
	 * The dossier is composed HERE rather than accepted from the request: it
	 * is the evidence bundle a debt-collection agency acts on, so it must be
	 * derived from stored records, never from a client-supplied body.
	 *
	 * Body JSON: { administration_id, invoiceId, customerId, dunningRunId }.
	 *
	 * @return JSONResponse 200 with the dispatch outcome; 400 on validation;
	 *                      401 when anonymous; 404 when the administration is
	 *                      out of scope; 500 without a stack trace on failure.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-20
	 */
	#[NoAdminRequired]
	public function transfer(): JSONResponse {
		$administrationId = $this->requireAdministration();
		if ($administrationId instanceof JSONResponse) {
			return $administrationId;
		}

		$incasso = new IncassoTransferRequest(request: $this->request);
		if ($incasso->invoiceId === '' || $incasso->customerId === '' || $incasso->dunningRunId === '') {
			return new JSONResponse(
				['error' => 'factuurId + klantId + dunningRunId required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->transferDossier(administrationId: $administrationId, incasso: $incasso);
	}//end transfer()

	/**
	 * The access prelude of every action: an authenticated caller, a valid
	 * administration_id and access to that administration.
	 *
	 * @return string|JSONResponse The trimmed administration id, or the refusal.
	 */
	private function requireAdministration(): string|JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		if ($administrationId === '') {
			return new JSONResponse(['error' => 'administration_id is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administration_id must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		return $this->requireAccess(administrationId: $administrationId);

	}//end requireAdministration()

	/**
	 * Check that the caller may act on the administration.
	 *
	 * @param string $administrationId The validated administration id.
	 *
	 * @return string|JSONResponse The administration id, or the refusal.
	 */
	private function requireAccess(string $administrationId): string|JSONResponse {
		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// A 404 rather than a 403, so the response cannot become an existence
		// oracle for another tenant's administration ids.
		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return $administrationId;

	}//end requireAccess()

	/**
	 * Refuse a BIK calculation the input does not allow.
	 *
	 * @param BikCalculationRequest $query The calculation input.
	 *
	 * @return JSONResponse|null
	 */
	private function bikRefusal(BikCalculationRequest $query): ?JSONResponse {
		if ($query->principal < 0) {
			return new JSONResponse(['error' => 'hoofdsom must be non-negative'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($query->partyType, ['B2B', 'B2C', 'GOVERNMENT'], true) === false) {
			return new JSONResponse(['error' => 'partyType must be one of B2B/B2C/GOVERNMENT'], Http::STATUS_BAD_REQUEST);
		}

		return $this->gracePeriodRefusal(query: $query);

	}//end bikRefusal()

	/**
	 * Refuse a B2C calculation inside the 14-day grace period (art. 6:96 BW).
	 *
	 * @param BikCalculationRequest $query The calculation input.
	 *
	 * @return JSONResponse|null
	 */
	private function gracePeriodRefusal(BikCalculationRequest $query): ?JSONResponse {
		if ($this->bik->isCalculationPermitted(partyType: $query->partyType, daysInArrears: $query->daysInArrears) === false) {
			return new JSONResponse(
				[
					'error' => 'B2C incassokostenberekening niet toegestaan voor dag 44 (14-dagenperiode per art. 6:96 BW)',
					'code' => 'B2C_GRACE_PERIOD',
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return null;

	}//end gracePeriodRefusal()

	/**
	 * Compose the BIK calculation; a malformed date is a 400.
	 *
	 * @param BikCalculationRequest $query The calculation input.
	 *
	 * @return JSONResponse
	 */
	private function composeBik(BikCalculationRequest $query): JSONResponse {
		try {
			$dates = $query->dates();
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'Invalid ingangsdatum / berekendOp'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->bikBody(query: $query, dates: $dates), Http::STATUS_OK);

	}//end composeBik()

	/**
	 * Run the BIK calculator for the input.
	 *
	 * @param BikCalculationRequest $query The calculation input.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $dates Effective date and calculation date.
	 *
	 * @return array<string,mixed>
	 */
	private function bikBody(BikCalculationRequest $query, array $dates): array {
		return $this->bik->compose(
			invoiceId: $query->invoiceId,
			administrationId: $query->administrationId,
			partyType: $query->partyType,
			principal: $query->principal,
			effectiveDate: $dates[0],
			calculatedOn: $dates[1],
			rateB2B: $query->rateB2B,
			rateB2C: $query->rateB2C,
		);

	}//end bikBody()

	/**
	 * Execute one dunning run stage; a refusal (e.g. an active pause) is a 409.
	 *
	 * @param string $administrationId The administration.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return JSONResponse
	 */
	private function executeStage(string $administrationId, array $params): JSONResponse {
		try {
			$persisted = $this->runs->executeStage(administrationId: $administrationId, params: $params);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController.executeRun failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to execute the dunning run'),
					'error' => 'dunning-run-execution-failed',
				],
				Http::STATUS_CONFLICT,
			);
		}

		return new JSONResponse($persisted, Http::STATUS_CREATED);

	}//end executeStage()

	/**
	 * Create the dunning pause; a failure is a logged 500.
	 *
	 * @param string $administrationId The administration.
	 * @param DunningPauseRequest $pause The pause to create.
	 *
	 * @return JSONResponse
	 */
	private function createPause(string $administrationId, DunningPauseRequest $pause): JSONResponse {
		try {
			$persisted = $this->persistPause(administrationId: $administrationId, pause: $pause);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: pause failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to create dunning pause'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($persisted, Http::STATUS_CREATED);

	}//end createPause()

	/**
	 * Hand the pause to the run orchestrator.
	 *
	 * @param string $administrationId The administration.
	 * @param DunningPauseRequest $pause The pause to create.
	 *
	 * @return array<string,mixed>
	 */
	private function persistPause(string $administrationId, DunningPauseRequest $pause): array {
		return $this->runs->pause(
			administrationId: $administrationId,
			invoiceId: $pause->invoiceId,
			reason: $pause->reason,
			details: $pause->details,
			pausedBy: $pause->pausedBy,
			evidenceRefs: $pause->evidenceRefs,
		);

	}//end persistPause()

	/**
	 * Whether the pause exists and belongs to an administration the caller may access.
	 *
	 * @param string $pauseId The DunningPauseDispute id.
	 *
	 * @return bool
	 */
	private function canAccessPause(string $pauseId): bool {
		$existingPause = $this->fetchDunningPauseDispute(pauseId: $pauseId);
		$pauseAdministrationId = (string)($existingPause['administrationId'] ?? '');
		if ($existingPause === null || $this->context->canAccess(administrationId: $pauseAdministrationId) === false) {
			return false;
		}

		return true;

	}//end canAccessPause()

	/**
	 * Resolve or expire the pause; a failure is reported as a stable slug.
	 *
	 * @param string $administrationId The administration.
	 * @param PauseResumptionRequest $resumption The resumption.
	 *
	 * @return JSONResponse
	 */
	private function resume(string $administrationId, PauseResumptionRequest $resumption): JSONResponse {
		try {
			$persisted = $this->persistResumption(administrationId: $administrationId, resumption: $resumption);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController.resumePause failed', ['exception' => $e]);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to resume the dunning pause'),
					'error' => 'dunning-pause-not-found',
				],
				Http::STATUS_NOT_FOUND,
			);
		}

		return new JSONResponse($persisted, Http::STATUS_OK);

	}//end resume()

	/**
	 * Hand the resumption to the run orchestrator.
	 *
	 * @param string $administrationId The administration.
	 * @param PauseResumptionRequest $resumption The resumption.
	 *
	 * @return array<string,mixed>
	 */
	private function persistResumption(string $administrationId, PauseResumptionRequest $resumption): array {
		return $this->runs->resumePause(
			administrationId: $administrationId,
			pauseId: $resumption->pauseId,
			resolution: $resumption->resolution,
			partialSettlement: $resumption->partialSettlement,
		);

	}//end persistResumption()

	/**
	 * Whether a write-off names the invoice, the art. 29 OB declaration and a
	 * positive written-off principal.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return bool
	 */
	private function isCompleteWriteOff(array $params): bool {
		return ($params['invoiceId'] ?? '') !== ''
			&& ($params['art29OBDeclaration'] ?? '') !== ''
			&& ((float)($params['principalDepreciated'] ?? 0)) > 0;

	}//end isCompleteWriteOff()

	/**
	 * Record the write-off; a failure is a logged 500.
	 *
	 * @param string $administrationId The administration.
	 * @param array<string,mixed> $params The request parameters.
	 *
	 * @return JSONResponse
	 */
	private function recordWriteOff(string $administrationId, array $params): JSONResponse {
		try {
			$persisted = $this->runs->writeOff(administrationId: $administrationId, params: $params);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: writeOff failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to record write-off'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($persisted, Http::STATUS_CREATED);

	}//end recordWriteOff()

	/**
	 * Compose the stage-5 dossier; a failure is a logged 500.
	 *
	 * @param string $administrationId The administration.
	 * @param IncassoTransferRequest $incasso The invoice and customer.
	 *
	 * @return JSONResponse
	 */
	private function composeDossier(string $administrationId, IncassoTransferRequest $incasso): JSONResponse {
		try {
			$bundle = $this->dossierFor(administrationId: $administrationId, incasso: $incasso);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: dossier compose failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to compose dossier'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($bundle, Http::STATUS_OK);

	}//end composeDossier()

	/**
	 * Compose the dossier server-side from the invoice and customer.
	 *
	 * @param string $administrationId The administration.
	 * @param IncassoTransferRequest $incasso The invoice and customer.
	 *
	 * @return array<string,mixed>
	 */
	private function dossierFor(string $administrationId, IncassoTransferRequest $incasso): array {
		return $this->dossier->compose(
			administrationId: $administrationId,
			invoiceId: $incasso->invoiceId,
			customerId: $incasso->customerId,
		);

	}//end dossierFor()

	/**
	 * Compose the dossier and transfer it to the incasso provider; a failure is
	 * a logged 500.
	 *
	 * @param string $administrationId The administration.
	 * @param IncassoTransferRequest $incasso The invoice, customer and run.
	 *
	 * @return JSONResponse
	 */
	private function transferDossier(string $administrationId, IncassoTransferRequest $incasso): JSONResponse {
		try {
			$verdict = $this->sendToIncasso(administrationId: $administrationId, incasso: $incasso);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: incasso transfer failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to transfer dossier'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// A non-DELIVERED outcome is reported as a 200 carrying the status, not
		// as an HTTP error: the dispatch attempt itself succeeded and the
		// operator needs the provider's own verdict (and errorMessage) to
		// decide between a retry and a manual escalation.
		return new JSONResponse($verdict, Http::STATUS_OK);

	}//end transferDossier()

	/**
	 * Compose the dossier server-side, transfer it and report the provider's verdict.
	 *
	 * @param string $administrationId The administration.
	 * @param IncassoTransferRequest $incasso The invoice, customer and run.
	 *
	 * @return array{channel: mixed, deliveryStatus: mixed, extras: mixed, errorMessage: mixed}
	 */
	private function sendToIncasso(string $administrationId, IncassoTransferRequest $incasso): array {
		$outcome = $this->runs->transferToIncasso(
			administrationId: $administrationId,
			invoiceId: $incasso->invoiceId,
			dossier: $this->dossierFor(administrationId: $administrationId, incasso: $incasso),
			dunningRunId: $incasso->dunningRunId,
		);

		return [
			'channel' => $outcome->channel,
			'deliveryStatus' => $outcome->deliveryStatus,
			'extras' => $outcome->extras,
			'errorMessage' => $outcome->errorMessage,
		];

	}//end sendToIncasso()

	/**
	 * Fetch a DunningPauseDispute by id via the real ObjectService API, so
	 * its OWN administrationId can be checked before resumePause() mutates
	 * it (security-endpoint-guards REQ-001 — see resumePause() above).
	 *
	 * @param string $pauseId The pause record id.
	 *
	 * @return array<string,mixed>|null The record, or null when genuinely absent.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function fetchDunningPauseDispute(string $pauseId): ?array {
		try {
			$scoped = $this->objectService
				->setRegister($this->register())
				->setSchema('DunningPauseDispute');
		} catch (\Throwable $e) {
			$this->logger->error(
				'DunningController.resumePause: failed to scope ObjectService for DunningPauseDispute',
				['exception' => $e]
			);
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $scoped, id: $pauseId);
	}//end fetchDunningPauseDispute()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
