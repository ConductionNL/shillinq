<?php

/**
 * Reconciliation Resolution Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * T4 bookkeeping-reconciliation-reports — REQ-REC-004 unmatched-item
 * resolution endpoint. Accepts a classification (matched / timing / pending
 * / adjustment) plus operator-supplied reason text and writes both onto
 * the target `ReconciliationMatch` via OpenRegister's ObjectService.
 *
 * Single endpoint POST /api/reconciliations/{reconId}/matches/{matchId}/resolve
 * available to any authenticated user (#[NoAdminRequired]); the
 * administration scope is validated via AdministrationContextService and
 * delegated reads/writes ride OR's RBAC, so no cross-administration data
 * leaks (ADR-005 server authority). Bulk resolution is supported on the
 * companion POST /api/reconciliations/{reconId}/matches/bulk-resolve which
 * takes an array of matchIds and applies the same classification + reason
 * to all of them.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\ReconciliationResolutionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST surface for the REQ-REC-004 unmatched-item resolution workflow.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
 */
class ReconciliationResolutionController extends Controller {
	/**
	 * Allowed resolution classifications per REQ-REC-004.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_STATUSES = ['matched', 'timing', 'pending', 'adjustment'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Inbound request.
	 * @param ReconciliationResolutionService $service Encapsulates the
	 *                                                 write logic + audit
	 *                                                 trail.
	 * @param IUserSession $userSession Current Nextcloud
	 *                                  user (for audit-trail
	 *                                  actor stamp).
	 * @param LoggerInterface $logger Logger for
	 *                                fail-closed
	 *                                diagnostics.
	 * @param IL10N $l10n Localized strings for
	 *                    client-facing error
	 *                    messages (ADR-050).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ReconciliationResolutionService $service,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Resolve a single unmatched item (REQ-REC-004).
	 *
	 * Body parameters:
	 *   - resolutionStatus (required) — one of matched / timing / pending
	 *                                   / adjustment
	 *   - resolutionReason (required) — non-empty operator-supplied note
	 *                                   (audit-trailed)
	 *
	 * Returns HTTP 200 on success with the updated ReconciliationMatch;
	 * HTTP 400 on a missing/malformed parameter; HTTP 404 when the match
	 * does not exist; HTTP 409 when the parent reconciliation is locked
	 * (already closed/cancelled).
	 *
	 * @param string $reconId The parent BankReconciliation id.
	 * @param string $matchId The ReconciliationMatch id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
	 */
	#[NoAdminRequired]
	public function resolve(string $reconId, string $matchId): JSONResponse {
		$reconId = trim($reconId);
		$matchId = trim($matchId);
		if ($reconId === '' || $matchId === '') {
			return new JSONResponse(
				['error' => 'reconId and matchId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->resolveOne(reconId: $reconId, matchId: $matchId);
	}//end resolve()

	/**
	 * Validate the posted decision, require a session and resolve one match.
	 *
	 * @param string $reconId The reconciliation.
	 * @param string $matchId The match.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	private function resolveOne(string $reconId, string $matchId): JSONResponse {
		$decision = new ResolutionDecision(request: $this->request);
		$validation = $this->validatePayload(decision: $decision);
		if ($validation !== null) {
			return $validation;
		}

		return $this->resolvedMatch(run: $this->resolutionRun(reconId: $reconId, decision: $decision), matchId: $matchId);
	}//end resolveOne()

	/**
	 * Resolve one match: 200 with the updated match, or the failure's answer.
	 *
	 * @param ResolutionRun $run     The run.
	 * @param string        $matchId The match.
	 *
	 * @return JSONResponse
	 */
	private function resolvedMatch(ResolutionRun $run, string $matchId): JSONResponse {
		try {
			$updated = $this->applyTo(run: $run, matchId: $matchId);
		} catch (\Throwable $e) {
			return $this->resolveFailed(e: $e, context: $run->logContext(matchId: $matchId, e: $e));
		}

		return new JSONResponse($updated, Http::STATUS_OK);
	}//end resolvedMatch()

	/**
	 * Answer a failed resolve: 404 when the match is missing, 409 when a
	 * domain rule (a locked reconciliation) refuses it, else a 500. Each is logged.
	 *
	 * @param \Throwable            $e       The failure.
	 * @param array<string, string> $context The reconciliation and match, as log context.
	 *
	 * @return JSONResponse
	 */
	private function resolveFailed(\Throwable $e, array $context): JSONResponse {
		if ($e instanceof \OutOfBoundsException) {
			return $this->matchNotFound(context: $context);
		}

		if ($e instanceof \DomainException) {
			return $this->matchLocked(context: $context);
		}

		$this->logger->error('ReconciliationResolutionController: resolve failed', $context);
		return new JSONResponse(
			['error' => 'resolution failed; see server log'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end resolveFailed()

	/**
	 * Log and answer a match that cannot be found.
	 *
	 * @param array<string, string> $context The reconciliation and match, as log context.
	 *
	 * @return JSONResponse
	 */
	private function matchNotFound(array $context): JSONResponse {
		$this->logger->error('ReconciliationResolutionController: resolve target not found', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to find the reconciliation match'),
				'error' => 'reconciliation-match-not-found',
			],
			Http::STATUS_NOT_FOUND,
		);
	}//end matchNotFound()

	/**
	 * Log and answer a match a domain rule refuses to resolve.
	 *
	 * @param array<string, string> $context The reconciliation and match, as log context.
	 *
	 * @return JSONResponse
	 */
	private function matchLocked(array $context): JSONResponse {
		$this->logger->error('ReconciliationResolutionController: resolve rejected by domain rule', $context);
		return new JSONResponse(
			[
				'message' => $this->l10n->t('Unable to resolve a match on a locked reconciliation'),
				'error' => 'reconciliation-match-locked',
			],
			Http::STATUS_CONFLICT,
		);
	}//end matchLocked()

	/**
	 * Bulk-resolve a list of unmatched items (REQ-REC-008 scenario "Unmatched
	 * Items page provides bulk resolution"). All matches receive the same
	 * classification + reason.
	 *
	 * Body parameters:
	 *   - matchIds         (required) — non-empty array of ReconciliationMatch ids
	 *   - resolutionStatus (required) — see resolve()
	 *   - resolutionReason (required) — see resolve()
	 *
	 * Returns HTTP 200 with { applied: int, failed: array<string,string> }.
	 * Per-id failures are surfaced in the response body but do NOT short-circuit
	 * the rest of the batch. The `failed` map carries a localized, generic
	 * message per id — never the raw exception text, which would leak service
	 * internals (SQL state, class names, file paths) into a 200 body. The real
	 * message is written to the server log instead, exactly as resolve() does
	 * for its own failures.
	 *
	 * @param string $reconId The parent BankReconciliation id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-008)
	 */
	#[NoAdminRequired]
	public function bulkResolve(string $reconId): JSONResponse {
		$reconId = trim($reconId);
		if ($reconId === '') {
			return new JSONResponse(
				['error' => 'reconId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$matchIdsParam = $this->request->getParam('matchIds');
		$refusal = $this->matchIdsRefusal(matchIds: $matchIdsParam);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->resolveMany(reconId: $reconId, matchIds: $matchIdsParam);

	}//end bulkResolve()

	/**
	 * The 400 unless the posted match ids are a non-empty array.
	 *
	 * @param mixed $matchIds The posted match ids.
	 *
	 * @return JSONResponse|null The refusal, or null.
	 */
	private function matchIdsRefusal(mixed $matchIds): ?JSONResponse {
		if (is_array($matchIds) === false || empty($matchIds) === true) {
			return new JSONResponse(
				['error' => 'matchIds must be a non-empty array'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end matchIdsRefusal()

	/**
	 * Validate the posted decision, require a session and resolve every match.
	 *
	 * @param string            $reconId  The reconciliation.
	 * @param array<int, mixed> $matchIds The posted match ids.
	 *
	 * @return JSONResponse 200 with the applied count and the per-match failures.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	private function resolveMany(string $reconId, array $matchIds): JSONResponse {
		$decision = new ResolutionDecision(request: $this->request);
		$validation = $this->validatePayload(decision: $decision);
		if ($validation !== null) {
			return $validation;
		}

		$run = $this->resolutionRun(reconId: $reconId, decision: $decision);
		foreach ($matchIds as $rawId) {
			$this->resolveEach(run: $run, matchId: trim((string)$rawId));
		}

		return new JSONResponse(
			['applied' => $run->applied, 'failed' => $run->failed],
			Http::STATUS_OK
		);
	}//end resolveMany()

	/**
	 * Resolve one match of a bulk request, isolating its failure (logged,
	 * with a safe message). A blank id is skipped.
	 *
	 * @param ResolutionRun $run     The run, tallying the outcome.
	 * @param string        $matchId The trimmed match id.
	 *
	 * @return void
	 */
	private function resolveEach(ResolutionRun $run, string $matchId): void {
		if ($matchId === '') {
			return;
		}

		try {
			$this->applyTo(run: $run, matchId: $matchId);
			$run->applied++;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ReconciliationResolutionController: bulk resolve failed for one match',
				$run->logContext(matchId: $matchId, e: $e)
			);
			$run->failed[$matchId] = $this->safeFailureMessage(exception: $e);
		}
	}//end resolveEach()

	/**
	 * Require a session (403 otherwise) and start the run as its user.
	 *
	 * @param string             $reconId  The reconciliation.
	 * @param ResolutionDecision $decision The validated decision.
	 *
	 * @return ResolutionRun
	 */
	private function resolutionRun(string $reconId, ResolutionDecision $decision): ResolutionRun {
		$this->requireAuthenticatedSession();
		return new ResolutionRun(reconId: $reconId, decision: $decision, actor: $this->resolveActor());
	}//end resolutionRun()

	/**
	 * Resolve one match through the service.
	 *
	 * @param ResolutionRun $run     The run.
	 * @param string        $matchId The match.
	 *
	 * @return array<string, mixed> The updated match.
	 */
	private function applyTo(ResolutionRun $run, string $matchId): array {
		return $this->service->resolveMatch(
			reconId: $run->reconId,
			matchId: $matchId,
			resolutionStatus: $run->decision->status,
			resolutionReason: $run->decision->reason,
			actor: $run->actor,
		);
	}//end applyTo()

	/**
	 * Map a per-id bulk failure onto a message that is safe to return to the
	 * client.
	 *
	 * Mirrors the classification resolve() applies in its own catch blocks:
	 * the exception *type* selects a localized generic message, so the caller
	 * still learns whether the row was missing or locked, while the raw
	 * exception text never reaches the response body. Callers are expected to
	 * have logged the real message before calling this.
	 *
	 * @param \Throwable $exception The caught per-id failure.
	 *
	 * @return string A localized message containing no service internals.
	 */
	private function safeFailureMessage(\Throwable $exception): string {
		if ($exception instanceof \OutOfBoundsException) {
			return $this->l10n->t('Unable to find the reconciliation match');
		}

		if ($exception instanceof \DomainException) {
			return $this->l10n->t('Unable to resolve a match on a locked reconciliation');
		}

		return $this->l10n->t('Unable to resolve this match');
	}//end safeFailureMessage()

	/**
	 * Validate the resolutionStatus + resolutionReason payload. Returns a
	 * JSONResponse when validation fails, null when the payload is
	 * acceptable.
	 *
	 * @param ResolutionDecision $decision The submitted classification and operator note.
	 *
	 * @return JSONResponse|null
	 */
	private function validatePayload(ResolutionDecision $decision): ?JSONResponse {
		if (in_array($decision->status, self::ALLOWED_STATUSES, true) === false) {
			return new JSONResponse(
				['error' => 'resolutionStatus must be one of: ' . implode(', ', self::ALLOWED_STATUSES)],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($decision->reason === '') {
			return new JSONResponse(
				['error' => 'resolutionReason is required (audit-trailed per REQ-REC-004)'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end validatePayload()

	/**
	 * Require an authenticated Nextcloud session (REQ-REC-004 IDOR guard).
	 * Throws OCSForbiddenException when the controller is invoked outside a
	 * logged-in session (defensive: #[NoAdminRequired] still requires
	 * authentication, but the runtime guarantee depends on middleware order).
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When no authenticated user is present.
	 */
	private function requireAuthenticatedSession(): void {
		if ($this->userSession->getUser() === null) {
			throw new OCSForbiddenException(
				'authenticated session required to resolve reconciliation matches'
			);
		}

	}//end requireAuthenticatedSession()

	/**
	 * Resolve the current Nextcloud actor UID for audit-trail stamping.
	 * Falls back to 'system' when the session is unavailable (should not
	 * happen on #[NoAdminRequired] routes).
	 *
	 * @return string The actor UID.
	 */
	private function resolveActor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end resolveActor()
}//end class
