<?php

/**
 * Spend Analytics Controller
 *
 * Read-only API for single-dimension spend analysis over the Accounts-Payable
 * sub-ledger. `GET /api/analytics/spend?administration_id=<id>&dimension=
 * <supplier|category|costCentre|period>` returns `{ dimension, label,
 * groups:[{key,amount}], total, backend }`. Reads are delegated to
 * OpenRegister's aggregation-api (`AggregationRunner::runAdhocByRef`,
 * ADR-022). There is no create/update/delete route.
 *
 * ⚠️ CORRECTION (gate-7). An earlier version of this docblock claimed the
 * endpoint had "no IDOR surface" because it took no object identifier and
 * because OR's aggregation-api "enforces list-RBAC and the active-organisation
 * multi-tenant predicate". Both halves were checked against the source and
 * neither holds for THIS app's tenancy:
 *
 *  - OR's predicate is `_organisation = ?` (AggregationRunner). That is
 *    OpenRegister's organisation, not shillinq's *administration*. Many
 *    administrations live inside one organisation — that is what
 *    AdministrationMembership exists for — so the predicate does not separate
 *    two administrations from each other.
 *  - OR's list-RBAC reads `Schema::getAuthorization()`. No schema in this app
 *    declares an `authorization` block (`grep -c authorization
 *    lib/Settings/register.d/*.json` finds only prose), and OpenRegister
 *    treats an absent block as OPEN. The `x-openregister-rbac` key on
 *    APTransaction is read by zero PHP in OpenRegister — it is documentation,
 *    not enforcement.
 *  - "no client-supplied object identifier" was true and irrelevant: an
 *    unscoped aggregate discloses other tenants' money without anyone naming
 *    an id.
 *
 * The endpoint therefore now requires `administration_id` and refuses, with a
 * 404 rather than a 403, any administration the caller holds no membership
 * for (AdministrationContextService::canAccess(), ADR-005 / REQ-MA-001) — a
 * 403 would confirm the administration exists and turn this into an
 * enumeration oracle for the tenant list.
 *
 * That guard is now COMPLETE for all four dimensions. It used to be complete
 * for `dimension=supplier` only: the supplier view reads APTransaction, which
 * declares `administrationId`, while the category / cost-centre / period views
 * read GLLine, which declared no administration property at all (the
 * administration lived on the parent GLTransaction and OpenRegister's filters
 * cannot join) — so those three aggregated every administration in the
 * register, and the membership check reduced their audience from "any
 * authenticated Nextcloud user" to "a member of some administration" without
 * isolating one administration from another. `glline-administration-scope`
 * denormalises `administrationId` onto GLLine, backfills the existing rows and
 * pushes the caller's administration into all four filters.
 *
 * ⚠️ The three GL-backed views raise, and this endpoint returns 500, whenever
 * the backfill is not proven complete — see SpendAnalyticsService's class
 * docblock. That is deliberate and must not be softened into "just return what
 * the filter matches": a filter on a property some rows lack matches nothing
 * for those rows, and a silently-zeroed bookkeeping total is a wrong number
 * that looks like a real one.
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
 * @spec openspec/specs/spend-analytics/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SpendAnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/analytics/spend?administration_id=...&dimension=...
 *
 * @spec openspec/specs/spend-analytics/spec.md
 */
class SpendAnalyticsController extends Controller {
	/**
	 * The closed set of supported spend dimensions.
	 *
	 * @var string[]
	 */
	private const DIMENSIONS = [
		'supplier',
		'category',
		'costCentre',
		'period',
	];

	/**
	 * Constructor for the SpendAnalyticsController.
	 *
	 * @param IRequest $request The request object.
	 * @param SpendAnalyticsService $service The spend-aggregation service (consumes OR aggregation-api).
	 * @param AdministrationContextService $context Authenticated-user context (ADR-005).
	 * @param IL10N $l10n Translation of the human-readable dimension label.
	 * @param LoggerInterface $logger Logger for diagnostics (no stack traces to client).
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SpendAnalyticsService $service,
		private readonly AdministrationContextService $context,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Single-dimension spend analysis, scoped to one administration.
	 *
	 * Query parameters:
	 *  - administration_id (required) the administration to report on. The
	 *    caller must hold a valid AdministrationMembership for it.
	 *  - dimension (required) one of supplier|category|costCentre|period.
	 *
	 * Returns HTTP 200 with { dimension, label, groups:[{key,amount}], total,
	 * backend }; HTTP 400 on a missing/malformed administration_id or an
	 * unknown dimension; HTTP 401 when anonymous; HTTP 404 when the caller has
	 * no membership for the named administration (masked, never 403 — see the
	 * class docblock); HTTP 500 without a stack trace on an unexpected failure.
	 *
	 * @return JSONResponse The spend payload or an error envelope.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 */
	#[NoAdminRequired]
	public function spend(): JSONResponse {
		// Authentication gate (ADR-005). NOT the authorisation guard — that is
		// the membership check below. #[NoAdminRequired] has already settled
		// whether anyone is logged in; this only turns a null uid into a clean
		// 401 instead of letting canAccess() answer "no memberships" as a 404.
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(
				['error' => 'Not authenticated'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$administrationId = trim((string)$this->request->getParam('administration_id', ''));
		if ($administrationId === '') {
			return new JSONResponse(
				['error' => 'administration_id is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $administrationId) !== 1) {
			return new JSONResponse(
				['error' => 'administration_id must be a valid identifier'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// IDOR guard (ADR-005 / REQ-MA-001): the caller must hold a valid
		// membership for the administration they named. 404, never 403.
		try {
			$allowed = $this->context->canAccess(administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SpendAnalyticsController: administration access check failed',
				['exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Authorization failure'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($allowed === false) {
			return new JSONResponse(
				['error' => 'Administration not found'],
				Http::STATUS_NOT_FOUND
			);
		}

		$dimension = trim((string)$this->request->getParam('dimension', ''));
		if (in_array($dimension, self::DIMENSIONS, true) === false) {
			return new JSONResponse(
				['error' => 'dimension must be one of: ' . implode(', ', self::DIMENSIONS)],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->dispatch(dimension: $dimension, administrationId: $administrationId);
			$result['label'] = $this->label(dimension: $dimension);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SpendAnalyticsController: failed to compute spend-by-' . $dimension,
				['exception' => $e->getMessage()]
			);

			return new JSONResponse(
				['error' => 'Failed to compute spend analysis'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end spend()

	/**
	 * Dispatch to the matching service method for the validated dimension.
	 *
	 * All four views are narrowed to the administration the caller proved
	 * membership of, so all four receive `$administrationId`. The three
	 * GL-backed views additionally refuse to run at all until the
	 * `GLLine.administrationId` backfill is proven complete; that raise
	 * surfaces through the caller's catch as an error status rather than as a
	 * zero total. See SpendAnalyticsService's class docblock.
	 *
	 * @param string $dimension The validated dimension.
	 * @param string $administrationId The administration the caller proved membership of.
	 *
	 * @return array<string,mixed> The service payload.
	 */
	private function dispatch(string $dimension, string $administrationId): array {
		switch ($dimension) {
			case 'supplier':
				return $this->service->spendBySupplier(administrationId: $administrationId);
			case 'category':
				return $this->service->spendByCategory(administrationId: $administrationId);
			case 'costCentre':
				return $this->service->spendByCostCentre(administrationId: $administrationId);
			default:
				return $this->service->spendByPeriod(administrationId: $administrationId);
		}

	}//end dispatch()

	/**
	 * Human-readable, translated label for the dimension (i18n EN + NL).
	 *
	 * @param string $dimension The validated dimension.
	 *
	 * @return string The translated label.
	 */
	private function label(string $dimension): string {
		switch ($dimension) {
			case 'supplier':
				return $this->l10n->t('Spend by supplier');
			case 'category':
				return $this->l10n->t('Spend by category');
			case 'costCentre':
				return $this->l10n->t('Spend by cost centre');
			default:
				return $this->l10n->t('Spend by period');
		}

	}//end label()
}//end class
