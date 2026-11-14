<?php

/**
 * Trial Balance Controller
 *
 * Tier-2 read-only trial-balance API (REQ-TB-009, REQ-TB-015, REQ-TB-016).
 * Exposes a single GET endpoint that returns the per-account opening / movement /
 * closing breakdown for one administration + fiscal period. The endpoint is
 * available to any authenticated user (#[NoAdminRequired]); the administration
 * scope is validated and reads are delegated to OpenRegister's ObjectService,
 * which enforces multitenancy / RBAC, so no cross-administration data leaks
 * (REQ-TB-017). Trial balance is read-only — there is no create/update/delete
 * route (REQ-TB-007).
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 * KNOWINGLY DANGLING until shillinq#500 — the endpoint's only canonical
 * description would be REQ-TB-009, which was never canonical, and REQ-TB-001
 * forbids the service behind it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\TrialBalanceServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/trial-balance — period-scoped per-account trial balance.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
 * KNOWINGLY DANGLING until shillinq#500 — the endpoint's only canonical
 * description would be REQ-TB-009, which was never canonical, and REQ-TB-001
 * forbids the service behind it.
 */
class TrialBalanceController extends Controller {
	/**
	 * Constructor for the TrialBalanceController.
	 *
	 * @param IRequest $request The request object.
	 * @param TrialBalanceServiceInterface $trialBalanceService The trial-balance computation service.
	 * @param AdministrationContextServiceInterface $context Admin-membership / RBAC context (REQ-TB-016).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly TrialBalanceServiceInterface $trialBalanceService,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the trial balance for a period (REQ-TB-009).
	 *
	 * Query parameters:
	 *  - period_id         (required) fiscal period identifier (REQ-TB-015).
	 *  - administration_id (required) administration scope (REQ-TB-016, REQ-TB-017).
	 *  - prior_period_id   (optional) period whose closing balances seed the opening (REQ-TB-002).
	 *
	 * Returns HTTP 200 with { data, total, totals, isBalanced } on success;
	 * HTTP 400 on a missing/blank parameter; HTTP 500 (without a stack trace) on
	 * an unexpected GL fetch failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-4-1
	 * KNOWINGLY DANGLING until shillinq#500 — the endpoint's only canonical
	 * description would be REQ-TB-009, which was never canonical, and REQ-TB-001
	 * forbids the service behind it.
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		// Authentication gate — anonymous requests cannot read trial balances
		// (REQ-TB-016). Although the NC SecurityMiddleware enforces this for
		// #[NoAdminRequired] routes, we check explicitly so the IDOR guard below
		// can rely on a resolved user id.
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(
				['error' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Reject missing and obviously malformed identifiers before touching
		// the data layer (REQ-TB-015).
		$query = new TrialBalanceQuery(request: $this->request);
		$refusal = self::inputRefusal(query: $query) ?? $this->accessRefusal(administrationId: $query->administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->compute(query: $query);
	}//end index()

	/**
	 * A 400 for a missing or malformed identifier, otherwise null.
	 *
	 * @param TrialBalanceQuery $query The query.
	 *
	 * @return JSONResponse|null
	 */
	private static function inputRefusal(TrialBalanceQuery $query): ?JSONResponse {
		$inputError = $query->inputError();
		if ($inputError !== null) {
			return new JSONResponse(['error' => $inputError], Http::STATUS_BAD_REQUEST);
		}

		return null;

	}//end inputRefusal()

	/**
	 * Per-administration IDOR guard (REQ-TB-016, REQ-TB-017): the user must be
	 * a member of the administration. Non-membership is masked as a 404 so
	 * foreign administration ids are not enumerable via a 403 oracle.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return JSONResponse|null
	 */
	private function accessRefusal(string $administrationId): ?JSONResponse {
		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			return $this->accessCheckFailure(administrationId: $administrationId, failure: $e);
		}

		if ($allowed === false) {
			return new JSONResponse(
				['error' => 'Administration not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		return null;

	}//end accessRefusal()

	/**
	 * Log an access check that failed and answer a 500.
	 *
	 * @param string $administrationId The administration.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function accessCheckFailure(string $administrationId, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'TrialBalanceController: failed to check administration access',
			[
				'administrationId' => $administrationId,
				'exception' => $failure->getMessage(),
			]
		);

		return new JSONResponse(
			['error' => 'Failed to authorise trial balance'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end accessCheckFailure()

	/**
	 * Compute the trial balance; a failure is a logged 500.
	 *
	 * @param TrialBalanceQuery $query The query.
	 *
	 * @return JSONResponse
	 */
	private function compute(TrialBalanceQuery $query): JSONResponse {
		try {
			$result = $this->trialBalanceService->compute(
				administrationId: $query->administrationId,
				periodId: $query->periodId,
				filters: $query->filters()
			);
		} catch (\Throwable $e) {
			return $this->computeFailure(query: $query, failure: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);

	}//end compute()

	/**
	 * Log a computation that failed and answer a 500.
	 *
	 * @param TrialBalanceQuery $query The query.
	 * @param \Throwable $failure The failure.
	 *
	 * @return JSONResponse
	 */
	private function computeFailure(TrialBalanceQuery $query, \Throwable $failure): JSONResponse {
		$this->logger->error(
			'TrialBalanceController: failed to compute trial balance',
			[
				'administrationId' => $query->administrationId,
				'periodId' => $query->periodId,
				'exception' => $failure->getMessage(),
			]
		);

		return new JSONResponse(
			['error' => 'Failed to compute trial balance'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);

	}//end computeFailure()
}//end class
