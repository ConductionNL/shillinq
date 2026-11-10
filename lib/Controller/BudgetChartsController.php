<?php

/**
 * Budget Charts Controller
 *
 * Read-only API for `budget-charts` (REQ-BCH-003): `GET
 * /apps/shillinq/api/budget-charts/series` returns the actual / projected /
 * begroot trend+cumulative envelope for every in-scope `Account` and
 * `LedgerGroup` in one administration + period range, composed by
 * {@see BudgetChartSeriesService} from the three sibling changes'
 * already-specced reader/calculator classes. There is no create/update/
 * delete route.
 *
 * IDOR guard (ADR-005 / REQ-MA-001): mirrors
 * {@see SpendAnalyticsController}'s own posture exactly — the caller must
 * hold a valid `AdministrationMembership` for the requested
 * `administrationId`, checked via
 * {@see AdministrationContextService::canAccess()}, refused with a masked
 * 404 (never 403) so an unauthorized caller cannot use this endpoint to
 * enumerate which administration ids exist. This is a STRICTER posture than
 * `FinancialDashboardController`'s own ("has at least one membership
 * somewhere") — chosen because this endpoint, unlike that one, already
 * takes a caller-supplied `administrationId` to scope against, so the
 * narrower per-administration check is both available and correct here.
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
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetChartSeriesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * GET /apps/shillinq/api/budget-charts/series.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */
class BudgetChartsController extends Controller {
	/**
	 * Constructor for the BudgetChartsController.
	 *
	 * @param IRequest $request The request object.
	 * @param BudgetChartSeriesService $service The chart-series composition service.
	 * @param AdministrationContextService $context Authenticated-user context (ADR-005).
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly BudgetChartSeriesService $service,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The actual/projected/begroot trend+cumulative envelope for every
	 * in-scope `Account` and `LedgerGroup` in one administration.
	 *
	 * Query parameters:
	 *  - administrationId (required) the administration to report on. The
	 *    caller must hold a valid AdministrationMembership for it.
	 *  - from / to (required) `YYYY-MM` inclusive bounds.
	 *  - annualBudgetId (optional) an explicit AnnualBudget override for its
	 *    own fiscal year; other fiscal years in range still resolve their
	 *    own `isDefault` budget.
	 *
	 * Returns HTTP 200 with { months, accounts, ledgerGroups }; HTTP 400 on
	 * a missing/malformed parameter; HTTP 401 when anonymous; HTTP 404 when
	 * the caller has no membership for the named administration (masked,
	 * never 403); HTTP 500 without a stack trace on an unexpected failure.
	 *
	 * @return JSONResponse The chart-series payload or an error envelope.
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
	 * @e2e exclude API-only endpoint, no UI surface of its own — exercised through
	 * the budget-charts::grid-row-trend-toggle-renders-chart and
	 * budget-charts::account-detail-chart-renders Playwright scenarios
	 */
	#[NoAdminRequired]
	public function series(): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$query = new ChartSeriesQuery(request: $this->request);
		$refusal = $this->administrationRefusal(query: $query) ?? $this->parameterRefusal(query: $query);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->resolve(query: $query);
	}//end series()

	/**
	 * The administration checks: well-formed, then the membership check
	 * (a failing check is a logged 500, a refusal a masked 404).
	 *
	 * @param ChartSeriesQuery $query The request parameters.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function administrationRefusal(ChartSeriesQuery $query): ?JSONResponse {
		if ($query->administrationValid() === false) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$allowed = $this->context->canAccess(administrationId: $query->administrationId);
		} catch (Throwable $e) {
			$this->logger->error('BudgetChartsController: administration access check failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			// Masked 404, never 403 — see the class docblock.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end administrationRefusal()

	/**
	 * The shape checks of the month range and the annual budget override.
	 *
	 * @param ChartSeriesQuery $query The request parameters.
	 *
	 * @return JSONResponse|null The refusal, or null when the parameters are valid.
	 */
	private function parameterRefusal(ChartSeriesQuery $query): ?JSONResponse {
		if ($query->monthsValid() === false) {
			return new JSONResponse(['error' => 'from and to must be YYYY-MM'], Http::STATUS_BAD_REQUEST);
		}

		if ($query->budgetValid() === false) {
			return new JSONResponse(['error' => 'annualBudgetId must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end parameterRefusal()

	/**
	 * Compute the series; a service refusal is a 400 with its message, any
	 * other failure a logged 500 without a stack trace.
	 *
	 * @param ChartSeriesQuery $query The validated request parameters.
	 *
	 * @return JSONResponse The chart-series payload or an error envelope.
	 */
	private function resolve(ChartSeriesQuery $query): JSONResponse {
		try {
			$result = $this->service->resolveSeries(
				administrationId: $query->administrationId, from: $query->from, to: $query->to, annualBudgetId: $query->annualBudget()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			return $this->seriesFailed(administrationId: $query->administrationId, e: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end resolve()

	/**
	 * Log an unexpected series failure and answer a generic 500 (no stack trace).
	 *
	 * @param string    $administrationId The administration reported on.
	 * @param Throwable $e                The failure.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function seriesFailed(string $administrationId, Throwable $e): JSONResponse {
		$this->logger->error(
			'BudgetChartsController: failed to compute chart series',
			['administrationId' => $administrationId, 'exception' => $e->getMessage()]
		);
		return new JSONResponse(['error' => 'Failed to compute chart series'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end seriesFailed()
}//end class
