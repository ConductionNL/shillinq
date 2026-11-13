<?php

/**
 * Payroll Controller
 *
 * Read/compute API for the NL loonadministratie engine (REQ-PAY-001, REQ-PAY-011,
 * REQ-PAY-012). Exposes three GET endpoints that compute a payslip, the period
 * LH-afdracht aggregate and the balanced GL journal for one administration +
 * period. Every endpoint is available to any authenticated user (#[NoAdminRequired]);
 * the administrationId is validated and reads are delegated to OpenRegister's
 * ObjectService, which enforces multitenancy / RBAC, so no cross-administration
 * data leaks. No stack traces are returned to the client (ADR-005); BSN is never
 * echoed unmasked.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\PayrollService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Payroll compute endpoints (read-only, period-scoped).
 *
 * The endpoints are authenticated (#[NoAdminRequired]) AND authorised per
 * administration in this controller, via
 * AdministrationContextService::canAccess() (ADR-005, REQ-MA-001).
 *
 * ⚠️ Read-only is not the same as harmless: these three endpoints return
 * payslips, wage-tax remittance totals and the payroll journal. `PayrollScope`
 * is a FORMAT check — it proves the administration id is a safe slug, not
 * that the caller may have it — and PayrollService uses the id purely as a
 * query term. This app declares no `authorization` block on its schemas, and
 * OpenRegister treats an absent block as open to every authenticated user (see
 * the same note on VATReturnController), so nothing downstream refuses either.
 * The membership check is the whole guard.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
class PayrollController extends Controller {

	/**
	 * Constructor for the PayrollController.
	 *
	 * @param IRequest $request The request object.
	 * @param PayrollService $payrollService The payroll computation service.
	 * @param IUserSession $userSession User session for authentication guard.
	 * @param AdministrationContextServiceInterface $context Membership guard (REQ-MA-001).
	 * @param LoggerInterface $logger Logger (no stack traces to client, no raw BSN).
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PayrollService $payrollService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextServiceInterface $context,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Compute one employee's payslip for a period (REQ-PAY-001, REQ-PAY-010).
	 *
	 * Query parameters: administration_id, werknemer_id, periode_id (all required).
	 *
	 * @return JSONResponse 200 with the LoonStrook payload; 400 on a bad param;
	 *                      404 when a record is missing; 500 without a stack trace.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	#[NoAdminRequired]
	public function loonstrook(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notLoggedIn();
		}

		$scope = PayrollScope::forEmployeePeriod(request: $this->request);

		return $this->scopeRefusal(scope: $scope) ?? $this->computedSlip(scope: $scope);
	}//end loonstrook()

	/**
	 * Compute the period LH-afdracht aggregate (REQ-PAY-011).
	 *
	 * Query parameters: administration_id, periode_id (required); eindheffingen_wkr (optional).
	 *
	 * @return JSONResponse 200 with the LHAfdracht payload; 400/500 as above.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	#[NoAdminRequired]
	public function lhAfdracht(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notLoggedIn();
		}

		$scope = PayrollScope::forPeriod(request: $this->request);

		return $this->scopeRefusal(scope: $scope) ?? $this->computedRemittance(scope: $scope);
	}//end lhAfdracht()

	/**
	 * Build the balanced GL journal for a period's payroll (REQ-PAY-012).
	 *
	 * Query parameters: administration_id, periode_id (required).
	 *
	 * @return JSONResponse 200 with the Loonjournaalpost payload; 400/500 as above.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	#[NoAdminRequired]
	public function journaalpost(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->notLoggedIn();
		}

		$scope = PayrollScope::forPeriod(request: $this->request);

		return $this->scopeRefusal(scope: $scope) ?? $this->builtJournal(scope: $scope);
	}//end journaalpost()

	/**
	 * The 401 answer for a request without a session user.
	 *
	 * @return JSONResponse
	 */
	private function notLoggedIn(): JSONResponse {
		return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
	}//end notLoggedIn()

	/**
	 * Refuse a scope with a blank or malformed value (400), then one the caller
	 * holds no membership for (404).
	 *
	 * @param PayrollScope $scope The scope read from the request.
	 *
	 * @return JSONResponse|null A refusal, or null when the scope may be computed.
	 */
	private function scopeRefusal(PayrollScope $scope): JSONResponse|null {
		$error = $scope->firstBlank();
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		return $this->requireAccessibleAdministration(administrationId: $scope->administrationId());
	}//end scopeRefusal()

	/**
	 * Compute the payslip; a missing record answers 404, any other failure 500.
	 *
	 * @param PayrollScope $scope The authorised scope.
	 *
	 * @return JSONResponse
	 */
	private function computedSlip(PayrollScope $scope): JSONResponse {
		try {
			$slip = $this->payrollService->berekenLoonStrook(
				administrationId: $scope->administrationId(),
				employeeId: $scope->employeeId(),
				periodId: $scope->periodId()
			);
		} catch (\RuntimeException $e) {
			return $this->slipNotFound(context: $scope->logContext(e: $e));
		} catch (\Throwable $e) {
			$this->logger->error('PayrollController: failed to compute loonstrook', $scope->logContext(e: $e));
			return new JSONResponse(['error' => 'Kon loonstrook niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($slip, Http::STATUS_OK);
	}//end computedSlip()

	/**
	 * Log and answer a payslip whose records are missing (404).
	 *
	 * @param array<string,string> $context The log context.
	 *
	 * @return JSONResponse
	 */
	private function slipNotFound(array $context): JSONResponse {
		$this->logger->error('PayrollController: loonstrook not found', $context);

		return new JSONResponse(
			[
				'message' => $this->l10n->t('Payslip not found for the given employee and period'),
				'error' => 'payroll-loonstrook-not-found',
			],
			Http::STATUS_NOT_FOUND,
		);
	}//end slipNotFound()

	/**
	 * Compute the LH-afdracht with the optional WKR final levies.
	 *
	 * @param PayrollScope $scope The authorised scope.
	 *
	 * @return JSONResponse
	 */
	private function computedRemittance(PayrollScope $scope): JSONResponse {
		$wkr = $this->finalLeviesWkr();

		try {
			$remittance = $this->payrollService->berekenLHAfdracht(
				administrationId: $scope->administrationId(),
				periodId: $scope->periodId(),
				finalLeviesWKR: $wkr
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollController: failed to compute LH-afdracht', $scope->logContext(e: $e));
			return new JSONResponse(['error' => 'Kon LH-afdracht niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($remittance, Http::STATUS_OK);
	}//end computedRemittance()

	/**
	 * The optional eindheffingen_wkr parameter as a float, a negative value read as 0.
	 *
	 * @return float
	 */
	private function finalLeviesWkr(): float {
		$wkr = (float)$this->request->getParam('eindheffingen_wkr', 0);
		if ($wkr < 0.0) {
			$wkr = 0.0;
		}

		return $wkr;
	}//end finalLeviesWkr()

	/**
	 * Build the payroll journal.
	 *
	 * @param PayrollScope $scope The authorised scope.
	 *
	 * @return JSONResponse
	 */
	private function builtJournal(PayrollScope $scope): JSONResponse {
		try {
			$journaal = $this->payrollService->bouwLoonjournaalpost(
				administrationId: $scope->administrationId(),
				periodId: $scope->periodId()
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollController: failed to build journaalpost', $scope->logContext(e: $e));
			return new JSONResponse(['error' => 'Kon loonjournaalpost niet opbouwen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($journaal, Http::STATUS_OK);
	}//end builtJournal()

	/**
	 * Refuse the request unless the caller holds a membership for the
	 * administration it named (ADR-005 / REQ-MA-001).
	 *
	 * 404, never 403 — AdministrationContextService::canAccess()'s own
	 * documented contract. A 403 would confirm the administration exists and
	 * turn these endpoints into an enumeration oracle for the tenant list.
	 *
	 * @param string $administrationId The format-checked administration id.
	 *
	 * @return JSONResponse|null A refusal to return to the client, or null when authorised.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	private function requireAccessibleAdministration(string $administrationId): ?JSONResponse {
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end requireAccessibleAdministration()
}//end class
