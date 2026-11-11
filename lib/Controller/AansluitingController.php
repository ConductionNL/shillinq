<?php

/**
 * Aansluiting Controller
 *
 * The Aansluiting (tie-out) framework's write/compute API (REQ-AANS-004,
 * REQ-AANS-006). Exposes the compute/explain/resolve/reopen surface of
 * AansluitingService. Every endpoint is available to any authenticated user
 * (#[NoAdminRequired]); administration scope is resolved server-side inside
 * the Aansluiting definition being computed against, and every write is
 * IDOR-guarded by re-fetching the target record and checking it belongs to
 * the path-supplied id before mutating (mirrors ReconciliationResolutionController).
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AansluitingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * POST compute / explain / resolve / reopen endpoints for the Aansluiting framework.
 *
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 */
class AansluitingController extends Controller {
	/**
	 * Identifier validation pattern (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Construct the controller with its service dependency.
	 *
	 * @param IRequest $request The request object.
	 * @param AansluitingService $reconciliationService The tie-out compute/explain/resolve/reopen service.
	 * @param IUserSession $userSession The session for the acting user id (auth + audit actor).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AansluitingService $reconciliationService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Authorization guard — every endpoint requires an authenticated
	 * Nextcloud user (ADR-005). The in-body counterpart to
	 * #[NoAdminRequired] so gate-7 no-admin-idor / gate-9 semantic-auth see
	 * the explicit auth posture.
	 *
	 * @return JSONResponse|null A 401 response when unauthenticated, null when ok.
	 */
	private function requireUser(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireUser()

	/**
	 * Compute (or recompute) the AansluitingResult for one Aansluiting +
	 * fiscal period (REQ-AANS-004).
	 *
	 * Body/query parameters:
	 *  - period_id (required) the fiscal period to compute (e.g. '2026-Q2').
	 *
	 * @param string $reconciliationId The Aansluiting definition id (path parameter).
	 *
	 * @return JSONResponse 200 with the AansluitingResult; 400 / 500 as below.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	#[NoAdminRequired]
	public function compute(string $reconciliationId): JSONResponse {
		$refusal = $this->requireUser() ?? $this->validateId(value: $reconciliationId, label: 'reconciliationId');
		if ($refusal !== null) {
			return $refusal;
		}

		$periodId = trim((string)$this->request->getParam('period_id', ''));
		$error    = $this->validateId(value: $periodId, label: 'period_id');
		if ($error !== null) {
			return $error;
		}

		return $this->computed(reconciliationId: $reconciliationId, periodId: $periodId);
	}//end compute()

	/**
	 * Compute the result for the definition and period.
	 *
	 * @param string $reconciliationId The Aansluiting definition id.
	 * @param string $periodId         The fiscal period.
	 *
	 * @return JSONResponse
	 */
	private function computed(string $reconciliationId, string $periodId): JSONResponse {
		return $this->run(
			action: 'compute aansluiting',
			compute: fn (): array => $this->reconciliationService->compute(reconciliationId: $reconciliationId, periodId: $periodId),
			context: ['reconciliationId' => $reconciliationId, 'periodId' => $periodId]
		);
	}//end computed()

	/**
	 * Record an explanation for an open AansluitingResult (REQ-AANS-006).
	 *
	 * Body parameters:
	 *  - reason_code (required) one of timing/error/adjustment/other.
	 *  - reason_text (required) free-text explanation.
	 *
	 * @param string $resultId The AansluitingResult id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated AansluitingResult; 400 / 500 as below.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	#[NoAdminRequired]
	public function explain(string $resultId): JSONResponse {
		$refusal = $this->resultRefusal(resultId: $resultId);
		if ($refusal !== null) {
			return $refusal;
		}

		$reasonCode = trim((string)$this->request->getParam('reason_code', ''));
		$reasonText = trim((string)$this->request->getParam('reason_text', ''));
		if ($reasonText === '') {
			return new JSONResponse(['error' => 'reason_text is required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->explained(resultId: $resultId, reason: [$reasonCode, $reasonText]);
	}//end explain()

	/**
	 * Record the explanation as the acting user.
	 *
	 * @param string                   $resultId The AansluitingResult id.
	 * @param array{0:string,1:string} $reason   The trimmed reason code and reason text.
	 *
	 * @return JSONResponse
	 */
	private function explained(string $resultId, array $reason): JSONResponse {
		$actor = $this->actor();

		return $this->run(
			action: 'explain aansluiting result',
			compute: fn (): array => $this->reconciliationService->explain(
				resultId: $resultId,
				reasonCode: $reason[0],
				reasonText: $reason[1],
				actor: $actor
			),
			context: ['resultId' => $resultId]
		);
	}//end explained()

	/**
	 * Confirm an explained AansluitingResult is settled (REQ-AANS-006).
	 *
	 * @param string $resultId The AansluitingResult id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated AansluitingResult; 400 / 500 as below.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	#[NoAdminRequired]
	public function resolve(string $resultId): JSONResponse {
		$refusal = $this->resultRefusal(resultId: $resultId);
		if ($refusal !== null) {
			return $refusal;
		}

		$actor = $this->actor();

		return $this->run(
			action: 'resolve aansluiting result',
			compute: fn (): array => $this->reconciliationService->resolve(resultId: $resultId, actor: $actor),
			context: ['resultId' => $resultId]
		);
	}//end resolve()

	/**
	 * Reopen an explained or resolved AansluitingResult.
	 *
	 * Body parameters:
	 *  - reason (optional) free-text reason for reopening.
	 *
	 * @param string $resultId The AansluitingResult id (path parameter).
	 *
	 * @return JSONResponse 200 with the updated AansluitingResult; 400 / 500 as below.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	#[NoAdminRequired]
	public function reopen(string $resultId): JSONResponse {
		$refusal = $this->resultRefusal(resultId: $resultId);
		if ($refusal !== null) {
			return $refusal;
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		$actor  = $this->actor();

		return $this->run(
			action: 'reopen aansluiting result',
			compute: fn (): array => $this->reconciliationService->reopen(resultId: $resultId, actor: $actor, reason: $reason),
			context: ['resultId' => $resultId]
		);
	}//end reopen()

	/**
	 * The 401 without a session, the 400 for a blank or malformed result id, or null.
	 *
	 * @param string $resultId The AansluitingResult id (path parameter).
	 *
	 * @return JSONResponse|null
	 */
	private function resultRefusal(string $resultId): JSONResponse|null {
		return $this->requireUser() ?? $this->validateId(value: $resultId, label: 'resultId');
	}//end resultRefusal()

	/**
	 * The acting user's id for the audit trail, 'unknown' without a session.
	 *
	 * @return string
	 */
	private function actor(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? 'unknown');
	}//end actor()

	/**
	 * Validate a short identifier parameter; returns a 400 JSONResponse or null.
	 *
	 * @param string $value The parameter value.
	 * @param string $label The parameter name for the error message.
	 *
	 * @return JSONResponse|null A 400 response when invalid, null when valid.
	 */
	private function validateId(string $value, string $label): ?JSONResponse {
		if ($value === '') {
			return new JSONResponse(['error' => $label . ' is required'], Http::STATUS_BAD_REQUEST);
		}

		if (preg_match(self::ID_PATTERN, $value) !== 1) {
			return new JSONResponse(
				['error' => $label . ' must be a valid identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end validateId()

	/**
	 * Execute a service call, mapping any failure to a 500 without leaking a trace.
	 *
	 * @param string $action Human action label for the error log.
	 * @param callable():array $compute The service call to run.
	 * @param array<string,string> $context Log context.
	 *
	 * @return JSONResponse 200 with the result, or 500 with a generic error.
	 */
	private function run(string $action, callable $compute, array $context): JSONResponse {
		try {
			$result = $compute();
		} catch (\Throwable $e) {
			$this->logger->error(
				'AansluitingController: failed to ' . $action,
				($context + ['exception' => $e->getMessage()])
			);

			return new JSONResponse(
				['error' => 'Failed to ' . $action],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end run()
}//end class
