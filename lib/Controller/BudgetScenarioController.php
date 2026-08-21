<?php

/**
 * Budget Scenario Controller
 *
 * Two endpoints:
 *   - `promote` (REQ-BSC-002): the REST surface `BudgetScenarioDefaultPromoter`
 *     needs — `isDefault` is set exclusively via this endpoint (a service
 *     call), never via an `x-openregister-lifecycle` transition — see
 *     `BudgetScenarioDefaultPromoter`'s own docblock for why.
 *   - `evaluate` (REQ-BSC-008, `design.md` §9): a read-only GET the
 *     `BudgetScenarioComparison` page calls, wiring
 *     `BudgetScenarioReader::loadContext()` into
 *     `BudgetScenarioEvaluator::evaluate()` for one scenario + fiscal year.
 *     Never writes anything (REQ-BSC-005).
 *
 * Every endpoint is available to authenticated users (`#[NoAdminRequired]`);
 * the target scenario's own `administrationId` is resolved server-side and
 * checked against the caller's membership via
 * `AdministrationContextService::canAccess()` — the same ADR-005/REQ-MA-001
 * pattern `PeriodCloseController` already establishes. Authorisation is NOT
 * delegated downstream: this app declares no `authorization` block on its
 * schemas, and OpenRegister treats an absent block as open to every
 * authenticated user, so this membership check is the only thing standing
 * between an authenticated user and another administration's scenario data.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetScenarioDefaultPromoter;
use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use OCA\Shillinq\Service\BudgetScenarioReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * REST surface for `BudgetScenarioDefaultPromoter`.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
 */
class BudgetScenarioController extends Controller {
	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request object.
	 * @param BudgetScenarioDefaultPromoter $promoter The one-default enforcement service.
	 * @param BudgetScenarioReader $reader Batched store access for evaluate().
	 * @param BudgetScenarioEvaluator $evaluator Pure base-vs-scenario evaluator.
	 * @param AdministrationContextService $context Membership guard (ADR-005, REQ-MA-001).
	 * @param IUserSession $userSession Session for the acting user id.
	 * @param LoggerInterface $logger Logger (no stack traces to client).
	 */
	public function __construct(
		IRequest $request,
		private readonly BudgetScenarioDefaultPromoter $promoter,
		private readonly BudgetScenarioReader $reader,
		private readonly BudgetScenarioEvaluator $evaluator,
		private readonly AdministrationContextService $context,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST promote a BudgetScenario to default, atomically demoting the
	 * previous default for the same administration in the same call
	 * (REQ-BSC-002).
	 *
	 * @param string $scenarioId The BudgetScenario id (path).
	 *
	 * @return JSONResponse 200 with the promotion outcome; 401/404/500 on error.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-002
	 */
	#[NoAdminRequired]
	public function promote(string $scenarioId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$scenarioId = trim($scenarioId);
		if ($this->validId(value: $scenarioId) === false) {
			return $this->error(message: 'scenarioId must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$outcome = $this->promoter->promote(scenarioId: $scenarioId);
		} catch (RuntimeException) {
			return $this->error(message: 'Scenario not found', status: Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->serverError(action: 'promote', e: $e);
		}

		// Authorisation happens AFTER resolving the scenario's own
		// administrationId (there is nothing to check membership against
		// beforehand) — a 404, never 403, on refusal, matching
		// PeriodCloseController's own IDOR-safe convention: a 403 would
		// confirm the scenario exists in another administration, turning
		// this endpoint into an enumeration oracle.
		if ($this->context->canAccess(administrationId: $outcome['administrationId']) === false) {
			return $this->error(message: 'Scenario not found', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['data' => $outcome], Http::STATUS_OK);

	}//end promote()

	/**
	 * GET evaluate one scenario for one fiscal year: base vs. scenario vs.
	 * delta, per `(ledgerGroupId, month)` cell (REQ-BSC-005, REQ-BSC-008).
	 * Read-only — never writes any object.
	 *
	 * @param string $scenarioId The BudgetScenario id (path).
	 *
	 * @return JSONResponse 200 with `{data: {scenarioId, fiscalYear, cells}}`; 400/401/404/500 on error.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
	 */
	#[NoAdminRequired]
	public function evaluate(string $scenarioId): JSONResponse {
		if ($this->requireUser() === false) {
			return $this->error(message: 'Authentication required', status: Http::STATUS_UNAUTHORIZED);
		}

		$scenarioId = trim($scenarioId);
		if ($this->validId(value: $scenarioId) === false) {
			return $this->error(message: 'scenarioId must be a valid identifier', status: Http::STATUS_BAD_REQUEST);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		if ($this->validId(value: $administrationId) === false) {
			return $this->error(message: 'administration_id is required', status: Http::STATUS_BAD_REQUEST);
		}

		// Identity + membership BEFORE any store read, matching
		// PeriodCloseController's own ordering — canAccess() fails closed for
		// an anonymous caller and a refusal reads 404, never 403 (IDOR-safe:
		// a 403 would confirm the administration exists).
		if ($this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->error(message: 'Scenario not found', status: Http::STATUS_NOT_FOUND);
		}

		$fiscalYear = (int)$this->request->getParam('fiscal_year', (string)((int)date('Y')));
		if ($fiscalYear < 2000 || $fiscalYear > 2100) {
			return $this->error(message: 'fiscal_year must be a plausible year', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$annualBudgetIds = $this->reader->resolveAnnualBudgetIds(
				administrationId: $administrationId,
				fiscalYear: $fiscalYear
			);
			$context = $this->reader->loadContext(administrationId: $administrationId, annualBudgetIds: $annualBudgetIds);
		} catch (Throwable $e) {
			return $this->serverError(action: 'evaluate', e: $e);
		}

		$scenarioExists = false;
		foreach ($context['scenarios'] as $scenario) {
			$id = (string)($scenario['id'] ?? $scenario['@self']['id'] ?? '');
			if ($id === $scenarioId) {
				$scenarioExists = true;
				break;
			}
		}

		if ($scenarioExists === false) {
			// Either the scenario does not exist, or it belongs to a
			// different administration than the one already authorised above
			// — either way, 404, not 403 (IDOR-safe).
			return $this->error(message: 'Scenario not found', status: Http::STATUS_NOT_FOUND);
		}

		try {
			$modifiers = ($context['modifiersByScenarioId'][$scenarioId] ?? []);
			$cells = $this->evaluator->evaluate(
				baseBudgetLines: $context['budgetLines'],
				ledgerGroups: $context['ledgerGroups'],
				cashflowRecurringRows: $context['cashflowRecurringRows'],
				modifiers: $modifiers,
				fiscalYear: $fiscalYear
			);
		} catch (Throwable $e) {
			return $this->serverError(action: 'evaluate', e: $e);
		}

		return new JSONResponse(
			[
				'data' => [
					'scenarioId' => $scenarioId,
					'fiscalYear' => $fiscalYear,
					'cells' => array_values($cells),
				],
			],
			Http::STATUS_OK
		);

	}//end evaluate()

	/**
	 * Authorization body-guard: the in-body counterpart to #[NoAdminRequired].
	 * gate-7 no-admin-idor reads this `->require*(` call to recognise the auth posture.
	 *
	 * @return bool True when authenticated, false otherwise.
	 */
	private function requireUser(): bool {
		return $this->userSession->getUser() !== null;
	}//end requireUser()

	/**
	 * Validate a short slug/UUID identifier.
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
	 * The log entry names the throwable's CLASS and origin as well as its
	 * message. A message alone is not enough to identify the fault: a
	 * `TypeError` raised by a return-type mismatch deep in the promoter reads
	 * as ordinary prose, and this handler's `catch (Throwable)` is the only
	 * place it is ever observed. `promote()` returned an opaque 500 on every
	 * demotion for exactly that reason (e2e budget-scenarios, CI run
	 * 32462209787) — the cause had to be reconstructed from source rather
	 * than read from the log. The client response is unchanged: still the
	 * generic message, still no stack trace.
	 *
	 * @param string $action The controller action for the log entry.
	 * @param \Throwable $e The caught throwable.
	 *
	 * @return JSONResponse A generic 500 response.
	 */
	private function serverError(string $action, \Throwable $e): JSONResponse {
		$this->logger->error(
			'BudgetScenarioController: ' . $action . ' failed',
			[
				'exceptionClass' => $e::class,
				'exception' => $e->getMessage(),
				'origin' => $e->getFile() . ':' . $e->getLine(),
			]
		);

		return new JSONResponse(['error' => 'An unexpected error occurred'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end serverError()
}//end class
