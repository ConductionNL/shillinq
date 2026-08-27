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
use OCA\Shillinq\AppInfo\Application;
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
	 * Accepted shape for `from`/`to`: a `YYYY-MM` fiscal month.
	 *
	 * @var string
	 */
	private const MONTH_PATTERN = '/^\d{4}-\d{2}$/';

	/**
	 * Accepted shape for an OpenRegister object identifier (id, UUID or slug).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

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
		parent::__construct(appName: Application::APP_ID, request: $request);
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

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '' || preg_match(self::ID_PATTERN, $administrationId) !== 1) {
			return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetChartsController: administration access check failed',
				['exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Authorization failure'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($allowed === false) {
			// Masked 404, never 403 — see the class docblock.
			return new JSONResponse(['error' => 'Administration not found'], Http::STATUS_NOT_FOUND);
		}

		$from = trim((string)$this->request->getParam('from', ''));
		$to = trim((string)$this->request->getParam('to', ''));
		if (preg_match(self::MONTH_PATTERN, $from) !== 1 || preg_match(self::MONTH_PATTERN, $to) !== 1) {
			return new JSONResponse(['error' => 'from and to must be YYYY-MM'], Http::STATUS_BAD_REQUEST);
		}

		$annualBudgetId = trim((string)$this->request->getParam('annualBudgetId', ''));
		if ($annualBudgetId !== '' && preg_match(self::ID_PATTERN, $annualBudgetId) !== 1) {
			return new JSONResponse(['error' => 'annualBudgetId must be a valid identifier'], Http::STATUS_BAD_REQUEST);
		}

		$resolvedAnnualBudgetId = null;
		if ($annualBudgetId !== '') {
			$resolvedAnnualBudgetId = $annualBudgetId;
		}

		try {
			$result = $this->service->resolveSeries(
				administrationId: $administrationId,
				from: $from,
				to: $to,
				annualBudgetId: $resolvedAnnualBudgetId
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetChartsController: failed to compute chart series',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to compute chart series'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end series()
}//end class
