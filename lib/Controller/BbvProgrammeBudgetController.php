<?php

/**
 * BBV Provincie Programme Budget Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The route half of #866/#862: the two read-only endpoints the provincies-BBV
 * Compliance Dashboard and the Budget-to-Programme Linker bind to.
 *
 *  - `GET /api/bbv-provincie/programme-budget-vs-actuals` — the KPI bag, the
 *    two chart series and the exception list (REQ-BBC-001..003).
 *  - `GET /api/bbv-provincie/gl-line-facets` — the Linker's three filter
 *    facets, each resolved to a filter over a property `GLLine` really
 *    declares (REQ-BBL-001).
 *
 * ## Authorisation posture
 *
 * `#[NoAdminRequired]` — a compliance dashboard is a controller/finance-officer
 * capability, not an admin one. Neither method accepts an object identifier:
 * the administration scope is resolved server-side from the caller's own
 * `AdministrationMembership` set (REQ-MA-001) inside
 * {@see \OCA\Shillinq\Service\BbvProgrammeBudgetService}. The only inputs are
 * the three REQ-BBC-002 value filters, which are validated against a closed
 * vocabulary here before they reach the service. With no caller-supplied
 * identifier crossing the boundary there is no IDOR surface, which is why no
 * per-object guard appears in these method bodies — the absence is the design.
 *
 * No `#[NoCSRFRequired]`: both are GETs issued by the SPA's declarative
 * `endpointSource` through `@nextcloud/axios`, which carries the request token.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BbvProgrammeBudgetServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only JSON endpoints for the provincies-BBV dashboards.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
class BbvProgrammeBudgetController extends Controller {
	/**
	 * The closed set of Budget statuses REQ-BBC-002 offers.
	 *
	 * @var array<int,string>
	 */
	private const BUDGET_STATUSES = ['approved', 'provisional', 'amended'];

	/**
	 * Construct the controller.
	 *
	 * @param IRequest $request The request.
	 * @param IL10N $l10n Translation service for response messages (ADR-007).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param BbvProgrammeBudgetServiceInterface $budgets Computes the envelopes.
	 * @param AdministrationContextServiceInterface $adminContext Authentication gate (ADR-005).
	 */
	public function __construct(
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly BbvProgrammeBudgetServiceInterface $budgets,
		private readonly AdministrationContextServiceInterface $adminContext,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Programme budget-vs-actuals for the caller's administrations.
	 *
	 * Query parameters (all optional, all REQ-BBC-002 filters):
	 *
	 *  - `fiscalYear` — a four-digit year. Anything else is refused with 400
	 *    rather than coerced to 0, which would silently report an empty year.
	 *  - `programme` — a programme code, or `all`. Repeatable / comma-separated.
	 *  - `status` — a Budget status, or `all`. Repeatable / comma-separated.
	 *
	 * @return JSONResponse The dashboard envelope, or an error envelope.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	#[NoAdminRequired]
	public function programmeBudgetVsActuals(): JSONResponse {
		$refusal = $this->unauthenticated();
		if ($refusal !== null) {
			return $refusal;
		}

		$query = $this->dashboardQuery();
		if ($query instanceof JSONResponse) {
			return $query;
		}

		return $this->dashboard(query: $query);

	}//end programmeBudgetVsActuals()

	/**
	 * The 401 for an anonymous caller, or null.
	 *
	 * @return JSONResponse|null
	 */
	private function unauthenticated(): ?JSONResponse {
		if ($this->adminContext->currentUserId() === null) {
			return $this->errorResponse(error: $this->l10n->t('Not logged in'), status: Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end unauthenticated()

	/**
	 * The validated filters, or the 400 for a malformed fiscal year, an
	 * undeclared programme or an undeclared status — in that order.
	 *
	 * @return BbvDashboardQuery|JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	private function dashboardQuery(): BbvDashboardQuery|JSONResponse {
		$fiscalYear = $this->requestedFiscalYear();
		if ($fiscalYear instanceof JSONResponse) {
			return $fiscalYear;
		}

		return $this->filteredQuery(fiscalYear: $fiscalYear);
	}//end dashboardQuery()

	/**
	 * The requested fiscal year: null for none or "all", or the 400 for one
	 * that is not four digits.
	 *
	 * @return int|JSONResponse|null
	 */
	private function requestedFiscalYear(): int|JSONResponse|null {
		$fiscalYearRaw = trim((string)$this->request->getParam('fiscalYear', ''));
		if ($fiscalYearRaw === '' || $fiscalYearRaw === 'all') {
			return null;
		}

		if (preg_match('/^\d{4}$/', $fiscalYearRaw) !== 1) {
			return $this->errorResponse(error: $this->l10n->t('fiscalYear must be a four-digit year'), status: Http::STATUS_BAD_REQUEST);
		}

		return (int)$fiscalYearRaw;
	}//end requestedFiscalYear()

	/**
	 * The query with the programme and status filters, or the 400 for an undeclared one.
	 *
	 * @param int|null $fiscalYear The fiscal year, null for all years.
	 *
	 * @return BbvDashboardQuery|JSONResponse
	 */
	private function filteredQuery(int|null $fiscalYear): BbvDashboardQuery|JSONResponse {
		$programmes = $this->listParam(
			name: 'programme',
			allowed: BbvProgrammeBudgetServiceInterface::PROGRAMMES
		);
		if ($programmes === false) {
			return $this->errorResponse(error: $this->l10n->t('programme must be one of the declared BBV programmes'), status: Http::STATUS_BAD_REQUEST);
		}

		$statuses = $this->listParam(name: 'status', allowed: self::BUDGET_STATUSES);
		if ($statuses === false) {
			return $this->errorResponse(error: $this->l10n->t('status must be one of the declared budget statuses'), status: Http::STATUS_BAD_REQUEST);
		}

		return new BbvDashboardQuery(fiscalYear: $fiscalYear, programmes: $programmes, statuses: $statuses);
	}//end filteredQuery()

	/**
	 * The programme budget-vs-actuals envelope; a failure is a logged 500.
	 *
	 * @param BbvDashboardQuery $query The validated filters.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	private function dashboard(BbvDashboardQuery $query): JSONResponse {
		try {
			$envelope = $this->budgets->programmeBudgetVsActuals(
				fiscalYear: $query->fiscalYear,
				programmes: $query->programmes,
				statuses: $query->statuses
			);
		} catch (Throwable $e) {
			return $this->dashboardFailed(e: $e);
		}

		return new JSONResponse($envelope);
	}//end dashboard()

	/**
	 * Log a failed dashboard and answer 500 without a stack trace.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function dashboardFailed(Throwable $e): JSONResponse {
		$this->logger->error(
			'BbvProgrammeBudgetController: failed to compute programme budget-vs-actuals',
			['exception' => $e->getMessage()]
		);
		return $this->errorResponse(error: $this->l10n->t('Could not compute the BBV compliance dashboard'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end dashboardFailed()

	/**
	 * An `{error}` answer.
	 *
	 * @param string $error  The translated error.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function errorResponse(string $error, int $status): JSONResponse {
		return new JSONResponse(['error' => $error], $status);
	}//end errorResponse()

	/**
	 * Filter facets for the Budget-to-Programme Linker index (REQ-BBL-001).
	 *
	 * @return JSONResponse The facet envelope, or an error envelope.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	#[NoAdminRequired]
	public function glLineFacets(): JSONResponse {
		$refusal = $this->unauthenticated();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$facets = $this->budgets->glLineFacets();
		} catch (Throwable $e) {
			return $this->facetsFailed(e: $e);
		}

		return new JSONResponse($facets);

	}//end glLineFacets()

	/**
	 * Log failed facets and answer 500 without a stack trace.
	 *
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse
	 */
	private function facetsFailed(Throwable $e): JSONResponse {
		$this->logger->error(
			'BbvProgrammeBudgetController: failed to build GL-line facets',
			['exception' => $e->getMessage()]
		);
		return $this->errorResponse(error: $this->l10n->t('Could not load the Budget Links filters'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end facetsFailed()

	/**
	 * Read a repeatable / comma-separated enum parameter.
	 *
	 * Returns `null` when the parameter is absent or explicitly `all` (no
	 * filter), `false` when a value is outside the closed vocabulary, and the
	 * value list otherwise. `false` is distinct from `[]` on purpose: an empty
	 * list is REQ-BBC-002's "no programme selected ⇒ show no data", so
	 * answering `[]` on a typo would render an empty dashboard that looked
	 * like a deliberate selection.
	 *
	 * @param string $name The query parameter name.
	 * @param array<int,string> $allowed The closed vocabulary.
	 *
	 * @return array<int,string>|null|false The values, null for "no filter", false when invalid.
	 */
	private function listParam(string $name, array $allowed): array|null|false {
		$raw = $this->request->getParam($name, null);
		if ($raw === null) {
			return null;
		}

		return $this->allowedValues(candidates: $this->listEntries(raw: $raw), allowed: $allowed);

	}//end listParam()

	/**
	 * The trimmed entries of a list parameter: its array, or its comma-separated string.
	 *
	 * @param mixed $raw The raw parameter.
	 *
	 * @return array<int, string>
	 */
	private function listEntries(mixed $raw): array {
		$entries = $raw;
		if (is_array($entries) === false) {
			$entries = explode(',', (string)$entries);
		}

		$candidates = [];
		foreach ($entries as $entry) {
			$candidates[] = trim((string)$entry);
		}

		return $candidates;
	}//end listEntries()

	/**
	 * The allowed values: null for an "All" selection (the absence of a
	 * filter, not a value), false for any undeclared value.
	 *
	 * @param array<int, string> $candidates The trimmed entries.
	 * @param array<int, string> $allowed    The declared values.
	 *
	 * @return array<int, string>|null|false
	 */
	private function allowedValues(array $candidates, array $allowed): array|null|false {
		$values = [];
		foreach ($candidates as $candidate) {
			if ($candidate === '' || $candidate === 'all') {
				return null;
			}

			if (in_array($candidate, $allowed, true) === false) {
				return false;
			}

			$values[] = $candidate;
		}

		return $values;
	}//end allowedValues()
}//end class
