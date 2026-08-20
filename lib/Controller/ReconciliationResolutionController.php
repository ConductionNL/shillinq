<?php

/**
 * Reconciliation Resolution Controller
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
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
		parent::__construct(appName: Application::APP_ID, request: $request);

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

		$resolutionStatus = trim((string)$this->request->getParam('resolutionStatus', ''));
		$resolutionReason = trim((string)$this->request->getParam('resolutionReason', ''));

		$validation = $this->validatePayload(
			resolutionStatus: $resolutionStatus,
			resolutionReason: $resolutionReason,
		);
		if ($validation !== null) {
			return $validation;
		}

		$this->requireAuthenticatedSession();
		$actor = $this->resolveActor();

		try {
			$updated = $this->service->resolveMatch(
				reconId: $reconId,
				matchId: $matchId,
				resolutionStatus: $resolutionStatus,
				resolutionReason: $resolutionReason,
				actor: $actor,
			);
		} catch (\OutOfBoundsException $e) {
			$this->logger->error(
				'ReconciliationResolutionController: resolve target not found',
				['reconId' => $reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to find the reconciliation match'),
					'error' => 'reconciliation-match-not-found',
				],
				Http::STATUS_NOT_FOUND,
			);
		} catch (\DomainException $e) {
			$this->logger->error(
				'ReconciliationResolutionController: resolve rejected by domain rule',
				['reconId' => $reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Unable to resolve a match on a locked reconciliation'),
					'error' => 'reconciliation-match-locked',
				],
				Http::STATUS_CONFLICT,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'ReconciliationResolutionController: resolve failed',
				['reconId' => $reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'resolution failed; see server log'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($updated, Http::STATUS_OK);
	}//end resolve()

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
		if (is_array($matchIdsParam) === false || empty($matchIdsParam) === true) {
			return new JSONResponse(
				['error' => 'matchIds must be a non-empty array'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$resolutionStatus = trim((string)$this->request->getParam('resolutionStatus', ''));
		$resolutionReason = trim((string)$this->request->getParam('resolutionReason', ''));

		$validation = $this->validatePayload(
			resolutionStatus: $resolutionStatus,
			resolutionReason: $resolutionReason,
		);
		if ($validation !== null) {
			return $validation;
		}

		$this->requireAuthenticatedSession();
		$actor = $this->resolveActor();
		$applied = 0;
		$failed = [];
		foreach ($matchIdsParam as $rawId) {
			$matchId = trim((string)$rawId);
			if ($matchId === '') {
				continue;
			}

			try {
				$this->service->resolveMatch(
					reconId: $reconId,
					matchId: $matchId,
					resolutionStatus: $resolutionStatus,
					resolutionReason: $resolutionReason,
					actor: $actor,
				);
				$applied++;
			} catch (\Throwable $e) {
				$this->logger->error(
					'ReconciliationResolutionController: bulk resolve failed for one match',
					['reconId' => $reconId, 'matchId' => $matchId, 'exception' => $e->getMessage()]
				);
				$failed[$matchId] = $this->safeFailureMessage(exception: $e);
			}
		}

		return new JSONResponse(
			['applied' => $applied, 'failed' => $failed],
			Http::STATUS_OK
		);

	}//end bulkResolve()

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
	 * @param string $resolutionStatus The submitted classification.
	 * @param string $resolutionReason The submitted operator note.
	 *
	 * @return JSONResponse|null
	 */
	private function validatePayload(string $resolutionStatus, string $resolutionReason): ?JSONResponse {
		if (in_array($resolutionStatus, self::ALLOWED_STATUSES, true) === false) {
			return new JSONResponse(
				['error' => 'resolutionStatus must be one of: ' . implode(', ', self::ALLOWED_STATUSES)],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($resolutionReason === '') {
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
