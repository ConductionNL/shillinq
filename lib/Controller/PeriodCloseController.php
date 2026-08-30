<?php

/**
 * Period Close Controller
 *
 * Tier-2 guided period-close API (REQ-PC-005, REQ-PC-006, REQ-PC-008). Exposes
 * the read endpoint plus the four lifecycle actions (start-close/close, reopen,
 * lock-audit) and the AI close-assistant flags. Every endpoint is available to
 * authenticated users (#[NoAdminRequired]); the administration scope is resolved
 * server-side from the request and reads are delegated to OpenRegister's
 * ObjectService, and each privileged transition is role-gated inside
 * PeriodCloseService (period-closer for close/reopen, auditor for audit-lock).
 * Errors return a static client-safe message + the right HTTP status — no stack
 * trace ever reaches the client (ADR-005).
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
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PeriodCloseAssistantService;
use OCA\Shillinq\Service\PeriodCloseException;
use OCA\Shillinq\Service\PeriodCloseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST surface for the PeriodClose lifecycle and close-assistant flags.
 *
 * The endpoints are authenticated (#[NoAdminRequired]) AND authorised per
 * administration in this controller, via
 * AdministrationContextService::canAccess() (ADR-005, REQ-MA-001).
 *
 * ⚠️ Authorisation is NOT delegated downstream, and it must not be assumed to
 * be. `administration_id` arrives on the request; PeriodCloseService and
 * PeriodCloseAssistantService use it purely as a QUERY TERM — they filter the
 * data to the administration that was asked for, which is not the same thing as
 * checking the caller may ask for it. Nor does OpenRegister supply a backstop:
 * this app declares no `authorization` block on its schemas, and OpenRegister
 * treats an absent block as open to every authenticated user (see the same note
 * on VATReturnController). The membership check below is therefore the only
 * thing standing between an authenticated user and another administration's
 * close data.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
 */
class PeriodCloseController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param PeriodCloseService $periodCloseService Lifecycle orchestration service.
	 * @param PeriodCloseAssistantService $assistantService Close-assistant detection service.
	 * @param AdministrationContextService $context Membership guard (REQ-MA-001).
	 * @param IUserSession $userSession Session for the acting user id.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 */
	public function __construct(
		IRequest $request,
		private readonly PeriodCloseService $periodCloseService,
		private readonly PeriodCloseAssistantService $assistantService,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET the period detail + checklist + AI flags (REQ-PC-005).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with the period + aiFlags; 400 on bad input; 404 when not found.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function show(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$periodId = trim($periodId);
		if ($this->validId(value: $periodId) === false) {
			return $this->error(message: 'period_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === null) {
			return $this->error(message: 'administration_id is required', status: Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$record = $this->periodCloseService->getPeriodForClose(
				periodId: $periodId,
				administrationId: $administrationId
			);
			if ($record === null) {
				return $this->error(message: 'Period not found', status: Http::STATUS_NOT_FOUND);
			}

			$flags = $this->assistantService->analyse(
				administrationId: $administrationId,
				periodId: (string)($record['periodId'] ?? $periodId),
				endDate: (string)($record['endDate'] ?? '')
			);
			$record['aiFlags'] = $flags;

			return new JSONResponse(['data' => $record], Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->serverError(action: 'show', e: $e);
		}//end try

	}//end show()

	/**
	 * GET the close-assistant flags for a period (REQ-PC-004).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with { data: flags[] }; 400/404 on error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function aiFlags(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$periodId = trim($periodId);
		if ($this->validId(value: $periodId) === false) {
			return $this->error(message: 'period_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === null) {
			return $this->error(message: 'administration_id is required', status: Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$record = $this->periodCloseService->getPeriodForClose(
				periodId: $periodId,
				administrationId: $administrationId
			);
			if ($record === null) {
				return $this->error(message: 'Period not found', status: Http::STATUS_NOT_FOUND);
			}

			$flags = $this->assistantService->analyse(
				administrationId: $administrationId,
				periodId: (string)($record['periodId'] ?? $periodId),
				endDate: (string)($record['endDate'] ?? '')
			);

			return new JSONResponse(['data' => $flags], Http::STATUS_OK);
		} catch (\Throwable $e) {
			return $this->serverError(action: 'aiFlags', e: $e);
		}//end try

	}//end aiFlags()

	/**
	 * POST close a period (closing/open → closed) (REQ-PC-002).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with the updated record; 403/404/409/422 mapped from the service.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function close(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		return $this->transition(
			periodId: $periodId,
			action: static fn (PeriodCloseService $s, string $pid, string $adm, string $uid): array
				=> $s->closePeriod(periodId: $pid, administrationId: $adm, userId: $uid)
		);

	}//end close()

	/**
	 * POST start the guided close (open → closing) (REQ-PC-002).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with the updated record; 403/404/409 mapped from the service.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function startClose(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		return $this->transition(
			periodId: $periodId,
			action: static fn (PeriodCloseService $s, string $pid, string $adm, string $uid): array
				=> $s->startClose(periodId: $pid, administrationId: $adm, userId: $uid)
		);

	}//end startClose()

	/**
	 * POST reopen a closed period (closed → open) with a close reason (REQ-PC-006).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with the updated record; 403/404/409/422 mapped from the service.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function reopen(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$closeReason = trim((string)$this->request->getParam('closeReason', ''));

		return $this->transition(
			periodId: $periodId,
			action: static fn (PeriodCloseService $s, string $pid, string $adm, string $uid): array
				=> $s->reopenPeriod(periodId: $pid, administrationId: $adm, closeReason: $closeReason, userId: $uid)
		);

	}//end reopen()

	/**
	 * POST audit-lock a closed period (closed → audit-locked, irreversible) (REQ-PC-002).
	 *
	 * @param string $periodId The PeriodClose id or business periodId (path).
	 *
	 * @return JSONResponse 200 with the updated record; 403/404/409 mapped from the service.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
	 */
	#[NoAdminRequired]
	public function lockAudit(string $periodId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		return $this->transition(
			periodId: $periodId,
			action: static fn (PeriodCloseService $s, string $pid, string $adm, string $uid): array
				=> $s->lockForAudit(periodId: $pid, administrationId: $adm, userId: $uid)
		);

	}//end lockAudit()

	/**
	 * Shared transition wrapper: validate, resolve scope + user, run, map errors.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param callable $action fn(PeriodCloseService, periodId, administrationId, userId): array.
	 *
	 * @return JSONResponse The mapped response.
	 */
	private function transition(string $periodId, callable $action): JSONResponse {
		$periodId = trim($periodId);
		if ($this->validId(value: $periodId) === false) {
			return $this->error(message: 'period_id must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		// Identity first, then the membership check: canAccess() fails closed for
		// an anonymous caller, so without this order an unauthenticated request
		// would be answered 404 instead of 401.
		$userId = $this->userId();
		if ($userId === '') {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === null) {
			return $this->error(message: 'administration_id is required', status: Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$record = $action($this->periodCloseService, $periodId, $administrationId, $userId);

			return new JSONResponse(['data' => $record], Http::STATUS_OK);
		} catch (PeriodCloseException $e) {
			return $this->error(message: $e->getMessage(), status: $this->mapStatus(status: $e->getStatus()));
		} catch (\Throwable $e) {
			return $this->serverError(action: 'transition', e: $e);
		}//end try

	}//end transition()

	/**
	 * Map a PeriodCloseService status sentinel to an HTTP status code.
	 *
	 * @param string $status The PeriodCloseService::ERR_* sentinel.
	 *
	 * @return int The HTTP status code.
	 */
	private function mapStatus(string $status): int {
		return match ($status) {
			PeriodCloseService::ERR_FORBIDDEN => Http::STATUS_FORBIDDEN,
			PeriodCloseService::ERR_NOT_FOUND => Http::STATUS_NOT_FOUND,
			PeriodCloseService::ERR_VALIDATION => Http::STATUS_UNPROCESSABLE_ENTITY,
			default => Http::STATUS_CONFLICT,
		};

	}//end mapStatus()

	/**
	 * Resolve the administration scope from the request (REQ-PC-008).
	 *
	 * ⚠️ This is a FORMAT check and nothing more. A well-formed id is not an
	 * authorised one — call requireAccessibleAdministration() on the result
	 * before it reaches any service.
	 *
	 * @return string|null The validated administration id, or null when missing/invalid.
	 */
	private function administrationId(): ?string {
		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		if ($this->validId(value: $administrationId) === false) {
			return null;
		}

		return $administrationId;
	}//end administrationId()

	/**
	 * Refuse the request unless the caller holds a membership for the
	 * administration it named (ADR-005 / REQ-MA-001).
	 *
	 * Returns 400 when no usable administration id was supplied and 404 — never
	 * 403 — when the caller may not access it. The 404 is deliberate and is
	 * AdministrationContextService::canAccess()'s own documented contract: a 403
	 * would confirm that the administration exists, turning this endpoint into
	 * an enumeration oracle for the instance's tenant list.
	 *
	 * @param string $administrationId The format-checked id.
	 *
	 * @return JSONResponse|null A refusal to return to the client, or null when authorised.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->error(message: 'Period not found', status: Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleAdministration()

	/**
	 * Resolve the acting user id from the session.
	 *
	 * @return string The user id, or '' when unauthenticated.
	 */
	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end userId()

	/**
	 * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
	 * Every endpoint requires an authenticated user (ADR-005); gate-7
	 * no-admin-idor reads this `->require*(` call to recognise the auth posture.
	 *
	 * @return bool True when authenticated, false otherwise.
	 */
	private function requireUser(): bool {
		return $this->userSession->getUser() !== null;
	}//end requireUser()

	/**
	 * Validate a short slug identifier (period / administration id).
	 *
	 * @param string $value The value to validate.
	 *
	 * @return bool True when the value is a safe short identifier.
	 */
	private function validId(string $value): bool {
		return $value !== '' && preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $value) === 1;
	}//end validId()

	/**
	 * Build a client-safe error response.
	 *
	 * @param string $message The static, client-safe message.
	 * @param int $status The HTTP status code.
	 *
	 * @return JSONResponse The error response.
	 */
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}//end error()

	/**
	 * Log an unexpected error server-side and return a generic 500 (no stack trace).
	 *
	 * @param string $action The controller action for the log entry.
	 * @param \Throwable $e The caught throwable.
	 *
	 * @return JSONResponse A generic 500 response.
	 */
	private function serverError(string $action, \Throwable $e): JSONResponse {
		$this->logger->error(
			'PeriodCloseController: ' . $action . ' failed',
			['exception' => $e->getMessage()]
		);

		return new JSONResponse(['error' => 'An unexpected error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end serverError()
}//end class
