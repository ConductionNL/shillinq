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

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
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
 * payslips, wage-tax remittance totals and the payroll journal. `scopeParam()`
 * below is a FORMAT check — it proves the administration id is a safe slug, not
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
	 * Identifier pattern shared by all scope parameters (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor for the PayrollController.
	 *
	 * @param IRequest $request The request object.
	 * @param PayrollService $payrollService The payroll computation service.
	 * @param IUserSession $userSession User session for authentication guard.
	 * @param AdministrationContextService $context Membership guard (REQ-MA-001).
	 * @param LoggerInterface $logger Logger (no stack traces to client, no raw BSN).
	 * @param IL10N $l10n Localized strings for client-facing error messages (ADR-050).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PayrollService $payrollService,
		private readonly IUserSession $userSession,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administration_id');
		$employeeId = $this->scopeParam(name: 'werknemer_id');
		$periodId = $this->scopeParam(name: 'periode_id');

		$error = $this->firstBlank(
			values: [
				'administration_id' => $administrationId,
				'werknemer_id' => $employeeId,
				'periode_id' => $periodId,
			]
		);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$slip = $this->payrollService->berekenLoonStrook(
				administrationId: $administrationId,
				employeeId: $employeeId,
				periodId: $periodId
			);
		} catch (\RuntimeException $e) {
			$this->logger->error(
				'PayrollController: loonstrook not found',
				['administrationId' => $administrationId, 'periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				[
					'message' => $this->l10n->t('Payslip not found for the given employee and period'),
					'error' => 'payroll-loonstrook-not-found',
				],
				Http::STATUS_NOT_FOUND,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PayrollController: failed to compute loonstrook',
				['administrationId' => $administrationId, 'periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Kon loonstrook niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse($slip, Http::STATUS_OK);
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
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administration_id');
		$periodId = $this->scopeParam(name: 'periode_id');

		$error = $this->firstBlank(values: ['administration_id' => $administrationId, 'periode_id' => $periodId]);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		$wkr = (float)$this->request->getParam('eindheffingen_wkr', 0);
		if ($wkr < 0.0) {
			$wkr = 0.0;
		}

		try {
			$remittance = $this->payrollService->berekenLHAfdracht(
				administrationId: $administrationId,
				periodId: $periodId,
				finalLeviesWKR: $wkr
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PayrollController: failed to compute LH-afdracht',
				['administrationId' => $administrationId, 'periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Kon LH-afdracht niet berekenen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($remittance, Http::STATUS_OK);
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
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->scopeParam(name: 'administration_id');
		$periodId = $this->scopeParam(name: 'periode_id');

		$error = $this->firstBlank(values: ['administration_id' => $administrationId, 'periode_id' => $periodId]);
		if ($error !== null) {
			return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->requireAccessibleAdministration(administrationId: $administrationId);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$journaal = $this->payrollService->bouwLoonjournaalpost(
				administrationId: $administrationId,
				periodId: $periodId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'PayrollController: failed to build journaalpost',
				['administrationId' => $administrationId, 'periodId' => $periodId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Kon loonjournaalpost niet opbouwen'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($journaal, Http::STATUS_OK);
	}//end journaalpost()

	/**
	 * Read and validate a scope parameter, returning '' when blank or malformed.
	 *
	 * @param string $name Query-parameter name.
	 *
	 * @return string The validated value or '' (blank/malformed).
	 */
	private function scopeParam(string $name): string {
		$value = trim((string)$this->request->getParam($name, ''));
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end scopeParam()

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

	/**
	 * Return a validation error message for the first blank/invalid scope value.
	 *
	 * @param array<string,string> $values Name => value map.
	 *
	 * @return string|null Error message or null when all values are valid.
	 */
	private function firstBlank(array $values): ?string {
		foreach ($values as $name => $value) {
			if ($value === '') {
				return sprintf('%s is verplicht en moet een geldige identifier zijn', $name);
			}
		}

		return null;
	}//end firstBlank()
}//end class
