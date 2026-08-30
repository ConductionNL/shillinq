<?php

/**
 * Dunning Controller
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
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
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
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
		parent::__construct(appName: Application::APP_ID, request: $request);
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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$principal = (float)$this->request->getParam('principal', 0.0);
		$partyType = (string)$this->request->getParam('partyType', 'B2B');
		$daysInArrears = (int)$this->request->getParam('dagenVerzuim', 0);
		$invoiceId = (string)$this->request->getParam('invoiceId', '');
		$ingangsRaw = (string)$this->request->getParam('effectiveDate', '');
		$calculatedRaw = (string)$this->request->getParam('calculatedOn', '');
		$rateB2B = $this->request->getParam('tariefB2B');
		$rateB2C = $this->request->getParam('tariefB2C');

		if ($principal < 0) {
			return new JSONResponse(['error' => 'hoofdsom must be non-negative'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($partyType, ['B2B', 'B2C', 'GOVERNMENT'], true) === false) {
			return new JSONResponse(['error' => 'partyType must be one of B2B/B2C/GOVERNMENT'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->bik->isCalculationPermitted(partyType: $partyType, daysInArrears: $daysInArrears) === false) {
			return new JSONResponse(
				[
					'error' => 'B2C incassokostenberekening niet toegestaan voor dag 44 (14-dagenperiode per art. 6:96 BW)',
					'code' => 'B2C_GRACE_PERIOD',
				],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			if ($ingangsRaw === '') {
				$effectiveDate = new DateTimeImmutable();
			} else {
				$effectiveDate = new DateTimeImmutable($ingangsRaw);
			}

			if ($calculatedRaw === '') {
				$calculatedOn = new DateTimeImmutable();
			} else {
				$calculatedOn = new DateTimeImmutable($calculatedRaw);
			}
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => 'Invalid ingangsdatum / berekendOp'], Http::STATUS_BAD_REQUEST);
		}

		$rateB2BValue = null;
		if ($rateB2B !== null) {
			$rateB2BValue = (float)$rateB2B;
		}

		$rateB2CValue = null;
		if ($rateB2C !== null) {
			$rateB2CValue = (float)$rateB2C;
		}

		$body = $this->bik->compose(
			invoiceId: $invoiceId,
			administrationId: $administrationId,
			partyType: $partyType,
			principal: $principal,
			effectiveDate: $effectiveDate,
			calculatedOn: $calculatedOn,
			rateB2B: $rateB2BValue,
			rateB2C: $rateB2CValue,
		);

		return new JSONResponse($body, Http::STATUS_OK);
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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$params = $this->request->getParams();
		if (($params['invoiceId'] ?? '') === '') {
			return new JSONResponse(['error' => 'factuurId is required'], Http::STATUS_BAD_REQUEST);
		}

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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = (string)$this->request->getParam('invoiceId', '');
		$reason = (string)$this->request->getParam('reason', '');
		$details = (string)$this->request->getParam('details', '');
		$byUser = (string)$this->context->currentUserId();
		$evidenceRefs = $this->request->getParam('evidenceRefs');

		if ($invoiceId === '' || in_array($reason, ['DISPUTED', 'PAYMENT_PLAN', 'OTHER'], true) === false) {
			return new JSONResponse(['error' => 'factuurId + valid reden are required'], Http::STATUS_BAD_REQUEST);
		}

		$evidenceRefsValue = null;
		if (is_array($evidenceRefs) === true) {
			$evidenceRefsValue = $evidenceRefs;
		}

		try {
			$persisted = $this->runs->pause(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				reason: $reason,
				details: $details,
				pausedBy: $byUser,
				evidenceRefs: $evidenceRefsValue,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: pause failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to create dunning pause'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($persisted, Http::STATUS_CREATED);
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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
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
		$existingPause = $this->fetchDunningPauseDispute(pauseId: $pauseId);
		$pauseAdministrationId = (string)($existingPause['administrationId'] ?? '');
		if ($existingPause === null || $this->context->canAccess(administrationId: $pauseAdministrationId) === false) {
			// Masked 404 — matches the sibling not-found response above so
			// the existence of another tenant's pause is never disclosed.
			return new JSONResponse(['error' => 'Dunning pause not found'], Http::STATUS_NOT_FOUND);
		}

		$resolution = (string)$this->request->getParam('resolution', 'resolve');
		$partial = $this->request->getParam('partialSettlement');
		if (in_array($resolution, ['resolve', 'expire'], true) === false) {
			return new JSONResponse(['error' => 'resolution must be resolve or expire'], Http::STATUS_BAD_REQUEST);
		}

		$partialSettlement = null;
		if ($partial !== null) {
			$partialSettlement = (float)$partial;
		}

		try {
			$persisted = $this->runs->resumePause(
				administrationId: $administrationId,
				pauseId: $pauseId,
				resolution: $resolution,
				partialSettlement: $partialSettlement,
			);
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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$params = $this->request->getParams();
		if (($params['invoiceId'] ?? '') === ''
			|| ($params['art29OBDeclaration'] ?? '') === ''
			|| ((float)($params['principalDepreciated'] ?? 0)) <= 0
		) {
			return new JSONResponse(
				['error' => 'factuurId, hoofdsomAfgeschreven (>0) and art29OBVerklaring are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$persisted = $this->runs->writeOff(administrationId: $administrationId, params: $params);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: writeOff failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to record write-off'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($persisted, Http::STATUS_CREATED);
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

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error('DunningController: admin access check failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$invoiceId = (string)$this->request->getParam('invoiceId', '');
		$customerId = (string)$this->request->getParam('customerId', '');
		if ($invoiceId === '' || $customerId === '') {
			return new JSONResponse(['error' => 'factuurId + klantId required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$bundle = $this->dossier->compose(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				customerId: $customerId,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: dossier compose failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to compose dossier'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($bundle, Http::STATUS_OK);
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

		$invoiceId = (string)$this->request->getParam('invoiceId', '');
		$customerId = (string)$this->request->getParam('customerId', '');
		$dunningRunId = (string)$this->request->getParam('dunningRunId', '');
		if ($invoiceId === '' || $customerId === '' || $dunningRunId === '') {
			return new JSONResponse(
				['error' => 'factuurId + klantId + dunningRunId required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$bundle = $this->dossier->compose(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				customerId: $customerId,
			);

			$outcome = $this->runs->transferToIncasso(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				dossier: $bundle,
				dunningRunId: $dunningRunId,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: incasso transfer failed: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Failed to transfer dossier'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// A non-DELIVERED outcome is reported as a 200 carrying the status, not
		// as an HTTP error: the dispatch attempt itself succeeded and the
		// operator needs the provider's own verdict (and errorMessage) to
		// decide between a retry and a manual escalation.
		return new JSONResponse(
			[
				'channel' => $outcome->channel,
				'deliveryStatus' => $outcome->deliveryStatus,
				'extras' => $outcome->extras,
				'errorMessage' => $outcome->errorMessage,
			],
			Http::STATUS_OK
		);
	}//end transfer()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
