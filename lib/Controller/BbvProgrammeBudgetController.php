<?php

/**
 * BBV Provincie Programme Budget Controller
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BbvProgrammeBudgetService;
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
	 * @param BbvProgrammeBudgetService $budgets Computes the envelopes.
	 * @param AdministrationContextService $adminContext Authentication gate (ADR-005).
	 */
	public function __construct(
		IRequest $request,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly BbvProgrammeBudgetService $budgets,
		private readonly AdministrationContextService $adminContext,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
		if ($this->adminContext->currentUserId() === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Not logged in')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$fiscalYearRaw = trim((string)$this->request->getParam('fiscalYear', ''));
		$fiscalYear = null;
		if ($fiscalYearRaw !== '' && $fiscalYearRaw !== 'all') {
			if (preg_match('/^\d{4}$/', $fiscalYearRaw) !== 1) {
				return new JSONResponse(
					['error' => $this->l10n->t('fiscalYear must be a four-digit year')],
					Http::STATUS_BAD_REQUEST
				);
			}

			$fiscalYear = (int)$fiscalYearRaw;
		}

		$programmes = $this->listParam(
			name: 'programme',
			allowed: BbvProgrammeBudgetService::PROGRAMMES
		);
		if ($programmes === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('programme must be one of the declared BBV programmes')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$statuses = $this->listParam(name: 'status', allowed: self::BUDGET_STATUSES);
		if ($statuses === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('status must be one of the declared budget statuses')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$envelope = $this->budgets->programmeBudgetVsActuals(
				fiscalYear: $fiscalYear,
				programmes: $programmes,
				statuses: $statuses
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BbvProgrammeBudgetController: failed to compute programme budget-vs-actuals',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => $this->l10n->t('Could not compute the BBV compliance dashboard')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($envelope);

	}//end programmeBudgetVsActuals()

	/**
	 * Filter facets for the Budget-to-Programme Linker index (REQ-BBL-001).
	 *
	 * @return JSONResponse The facet envelope, or an error envelope.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	#[NoAdminRequired]
	public function glLineFacets(): JSONResponse {
		if ($this->adminContext->currentUserId() === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Not logged in')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$facets = $this->budgets->glLineFacets();
		} catch (Throwable $e) {
			$this->logger->error(
				'BbvProgrammeBudgetController: failed to build GL-line facets',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => $this->l10n->t('Could not load the Budget Links filters')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($facets);

	}//end glLineFacets()

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

		$entries = $raw;
		if (is_array($entries) === false) {
			$entries = explode(',', (string)$entries);
		}

		$candidates = [];
		foreach ($entries as $entry) {
			$candidates[] = trim((string)$entry);
		}

		$values = [];
		foreach ($candidates as $candidate) {
			if ($candidate === '' || $candidate === 'all') {
				// An "All" selection is the absence of a filter, not a value.
				return null;
			}

			if (in_array($candidate, $allowed, true) === false) {
				return false;
			}

			$values[] = $candidate;
		}

		return $values;

	}//end listParam()
}//end class
