<?php

/**
 * Spend Analytics Service
 *
 * Single-dimension spend analysis over the Accounts-Payable sub-ledger,
 * built by CONSUMING OpenRegister's aggregation-api primitive (ADR-022) —
 * it does NOT re-implement grouping or summing in the leaf. Every view is
 * one server-side `SELECT sum(<amount>) ... GROUP BY <dimension>` executed
 * by OR's `AggregationRunner::runAdhocByRef()`, which enforces list-RBAC and
 * the active-organisation multi-tenant predicate before any SQL runs.
 *
 * Four dimensions, each a SINGLE top-level scalar field that OR's aggregation
 * engine honours today (`AggregationQuery.groupBy = {field: <name>}`):
 *
 *  - spend-by-supplier    → APTransaction, sum(totalAmount) GROUP BY vendorId,
 *                           over the open/posted invoice states.
 *  - spend-by-category    → GLLine, sum(amount) GROUP BY accountNumber, over
 *                           the debit AP expense postings.
 *  - spend-by-cost-centre → GLLine, sum(amount) GROUP BY costCenterCode, same
 *                           posting slice.
 *  - spend-by-period      → GLLine, sum(amount) GROUP BY periodId, same slice.
 *
 * CROSS-TAB (supplier × period, category × cost-centre) is intentionally NOT
 * offered here: OR's `AggregationQuery::getGroupByField(): ?string` reads a
 * single scalar groupBy field, so multi-field groupBy is inert engine-side
 * (see design.md verify-first findings + openregister multi-field-groupBy
 * issue). Cross-tab belongs in OpenRegister, not in this leaf.
 *
 * Money note: the supplier view sums gross invoice totals (APTransaction);
 * the category / cost-centre / period views sum posted expense debit amounts
 * (GLLine). The two need not reconcile to the cent (tax + the AP-payable
 * credit leg are excluded from the GL debit slice) — they answer different
 * questions and are labelled distinctly.
 *
 * ADMINISTRATION SCOPE IS UNIFORM ACROSS ALL FOUR VIEWS. Every one of them
 * takes the caller's administration and pushes it into the aggregation filter,
 * so the totals returned contain no other administration's money.
 *
 *  - `APTransaction` DECLARES `administrationId` (bookkeeping-accounts-payable-
 *    core.json), so `spendBySupplier()` has always been scoped.
 *  - `GLLine` NOW declares `administrationId` too, denormalised from the parent
 *    `GLTransaction` by `register.d/glline-administration-scope.json`
 *    (REQ-GLS-001). Until that fragment landed it declared no administration or
 *    organisation property at all — the administration lived one hop away on
 *    the parent, and OpenRegister's `filters` address an object's own JSON
 *    properties and cannot join — so the category / cost-centre / period views
 *    aggregated EVERY administration in the register while looking scoped.
 *    That is closed. The parent remains the source of truth; this column is a
 *    copy that exists purely so the filter has something to address.
 *
 * ⚠️ THE FILTER IS CONDITIONAL, AND THAT IS THE LOAD-BEARING PART. Filtering on
 * a property that some rows lack matches NOTHING for those rows, so switching
 * this on over a half-backfilled ledger returns a silent ZERO in a bookkeeping
 * total — a wrong number that looks like a real one, which is worse than the
 * cross-administration read it replaces. So the GL-backed views do not simply
 * filter: they first require `GlLineAdministrationBackfillMigrator::
 * GATE_CONFIG_KEY` to hold `GATE_CONTRACT_VERSION`, a value only
 * `BackfillGlLineAdministration` writes, and only after RE-READING every
 * `GLLine` row and counting zero without a scope. When the gate is shut the
 * views RAISE (see assertGlScopeIsEnforceable()). Raising is the right third
 * option: it leaks nothing, unlike serving unscoped totals, and it claims
 * nothing false, unlike serving zeros. Do not replace that raise with a
 * fallback to the unfiltered query — that is the original bug, re-armed.
 *
 * What DOES NOT scope these reads, verified rather than assumed:
 *  - OR's `AggregationRunner` applies a `_organisation = ?` predicate and a
 *    schema-level `list` RBAC gate. `_organisation` is OpenRegister's tenancy
 *    axis, NOT shillinq's administration — many administrations live inside
 *    one organisation, which is the entire point of AdministrationMembership.
 *  - `x-openregister-rbac` on APTransaction is read by ZERO PHP in
 *    OpenRegister (grep over its lib/ returns nothing); it is documentation.
 *    OR's RBAC reads `Schema::getAuthorization()`, and no schema in this app
 *    declares an `authorization` block, which OR treats as open.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Single-dimension spend analytics that consume OR's aggregation-api.
 *
 * @spec openspec/specs/spend-analytics/spec.md
 */
class SpendAnalyticsService {
	/**
	 * FQCN of OpenRegister's aggregation runner (fetched lazily from the DI
	 * container so the leaf does not hard-link the class at boot).
	 *
	 * @var string
	 */
	private const OR_AGGREGATION_RUNNER = 'OCA\OpenRegister\Service\Aggregation\AggregationRunner';

	/**
	 * APTransaction schema slug — source of the supplier view.
	 *
	 * @var string
	 */
	private const SCHEMA_AP_TRANSACTION = 'APTransaction';

	/**
	 * GLLine schema slug — source of the category / cost-centre / period views.
	 *
	 * @var string
	 */
	private const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Open / posted AP invoice states that represent committed spend. Excludes
	 * draft (not yet committed), voided and written-off (reversed).
	 *
	 * @var string[]
	 */
	private const AP_SPEND_STATES = [
		'issued',
		'partially-paid',
		'overdue',
		'disputed',
		'paid',
	];

	/**
	 * Constructor with lazy DI of OR's aggregation runner.
	 *
	 * @param ContainerInterface $container DI container — OR's AggregationRunner is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Nextcloud logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Spend grouped by supplier (vendorId) over the committed AP states, scoped
	 * to one administration.
	 *
	 * `APTransaction.administrationId` is a declared, filterable property, so
	 * the caller's administration is pushed into the aggregation filter and the
	 * returned totals contain no other administration's invoices. The caller
	 * MUST have proven membership of `$administrationId` first — this service
	 * treats the value purely as a scope term, exactly like every other
	 * administration-scoped service in the app.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 */
	public function spendBySupplier(string $administrationId): array {
		return $this->aggregate(
			dimension: 'supplier',
			schema: self::SCHEMA_AP_TRANSACTION,
			metricField: 'totalAmount',
			filter: [
				'administrationId' => $administrationId,
				'state' => ['in' => self::AP_SPEND_STATES],
			],
			groupField: 'vendorId'
		);

	}//end spendBySupplier()

	/**
	 * Spend grouped by expense category (GL accountNumber) over the debit AP
	 * expense postings, scoped to one administration.
	 *
	 * Scoped through `GLLine.administrationId`, denormalised from the parent
	 * `GLTransaction` (REQ-GLS-001). Raises rather than returning anything at
	 * all while the backfill gate is shut — see the class docblock.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByCategory(string $administrationId): array {
		return $this->aggregate(
			dimension: 'category',
			schema: self::SCHEMA_GL_LINE,
			metricField: 'amount',
			filter: $this->apExpenseDebitFilter(administrationId: $administrationId),
			groupField: 'accountNumber'
		);

	}//end spendByCategory()

	/**
	 * Spend grouped by analytical cost centre (GL costCenterCode) over the
	 * debit AP expense postings, scoped to one administration.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByCostCentre(string $administrationId): array {
		return $this->aggregate(
			dimension: 'costCentre',
			schema: self::SCHEMA_GL_LINE,
			metricField: 'amount',
			filter: $this->apExpenseDebitFilter(administrationId: $administrationId),
			groupField: 'costCenterCode'
		);

	}//end spendByCostCentre()

	/**
	 * Spend grouped by fiscal period (GL periodId) over the debit AP expense
	 * postings, scoped to one administration.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When the GLLine administration backfill is not proven complete.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function spendByPeriod(string $administrationId): array {
		return $this->aggregate(
			dimension: 'period',
			schema: self::SCHEMA_GL_LINE,
			metricField: 'amount',
			filter: $this->apExpenseDebitFilter(administrationId: $administrationId),
			groupField: 'periodId'
		);

	}//end spendByPeriod()

	/**
	 * The posting slice that constitutes AP expense spend, scoped to one
	 * administration: debit lines whose sub-ledger is accounts-payable.
	 *
	 * Asserts the backfill gate BEFORE building the filter, so a caller can
	 * never end up with a `GLLine` query carrying an `administrationId` term
	 * that some rows cannot match.
	 *
	 * @param string $administrationId The administration to scope to.
	 *
	 * @return array<string,mixed> The aggregation filter map.
	 *
	 * @throws RuntimeException When the backfill is not proven complete, or the scope is empty.
	 */
	private function apExpenseDebitFilter(string $administrationId): array {
		$this->assertGlScopeIsEnforceable();

		$scope = trim($administrationId);
		if ($scope === '') {
			// An empty scope would filter `administrationId = ''`, matching
			// nothing — the same silent zero by a different route.
			throw new RuntimeException(
				'SpendAnalyticsService: a GL-backed spend view requires a non-empty administrationId'
			);
		}

		return [
			'administrationId' => $scope,
			'side' => 'debit',
			'subLedgerType' => 'ap',
		];

	}//end apExpenseDebitFilter()

	/**
	 * Refuse the GL-backed views unless the `GLLine.administrationId` backfill
	 * has been PROVEN complete (REQ-GLS-003).
	 *
	 * The gate holds `GlLineAdministrationBackfillMigrator::
	 * GATE_CONTRACT_VERSION` only when `BackfillGlLineAdministration` re-read
	 * every `GLLine` row from the store and counted zero without a scope. It is
	 * cleared at the start of every run of that step, so an instance that has
	 * not run it, crashed inside it, or aborted its batch reads as shut.
	 *
	 * It is a VERSION rather than a boolean so that adding a new `GLLine`
	 * writer can invalidate every deployment's stored proof by bumping one
	 * constant — completeness is a claim about the code as well as the data,
	 * and a boolean would keep answering "yes" on evidence gathered before the
	 * new writer existed.
	 *
	 * Throwing is deliberate and must not be softened into a fallback: the
	 * controller turns it into an error status, whereas returning the
	 * unfiltered aggregation would restore the cross-administration read and
	 * returning the filtered one would report a zero total as though it were a
	 * measurement.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the gate is shut.
	 */
	private function assertGlScopeIsEnforceable(): void {
		$gate = $this->appConfig->getValueString(
			Application::APP_ID,
			GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY,
			''
		);

		if ($gate === GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION) {
			return;
		}

		$this->logger->error(
			'SpendAnalyticsService: refusing a GL-backed spend view — the GLLine administrationId '
			. 'backfill is not proven complete, so an administration filter would silently exclude '
			. 'un-backfilled lines. Run occ maintenance:repair to re-run BackfillGlLineAdministration.',
			[
				'gate' => $gate,
				'expected' => GlLineAdministrationBackfillMigrator::GATE_CONTRACT_VERSION,
			]
		);

		throw new RuntimeException(
			'GLLine administration backfill is not proven complete; the category / cost-centre / '
			. 'period spend views are unavailable until BackfillGlLineAdministration has re-read '
			. 'every GLLine and found zero without an administrationId.'
		);

	}//end assertGlScopeIsEnforceable()

	/**
	 * Build and dispatch one single-field aggregation through OR's
	 * aggregation-api, then shape the envelope for the client.
	 *
	 * @param string $dimension Stable dimension identifier (supplier/category/costCentre/period).
	 * @param string $schema Source schema slug.
	 * @param string $metricField Numeric field to sum.
	 * @param array<string,mixed> $filter OR aggregation filter map (operator-aware).
	 * @param string $groupField Single top-level scalar field to GROUP BY.
	 *
	 * @return array<string,mixed> `{ dimension, groups:[{key,amount}], total, backend }`.
	 *
	 * @throws RuntimeException When OR's aggregation runner is unavailable.
	 *
	 * @spec openspec/specs/spend-analytics/spec.md
	 */
	private function aggregate(
		string $dimension,
		string $schema,
		string $metricField,
		array $filter,
		string $groupField,
	): array {
		$runner = $this->getAggregationRunner();
		if ($runner === null) {
			throw new RuntimeException('OpenRegister aggregation runner is unavailable');
		}

		// Single-field groupBy — the ONLY grouping shape OR honours today.
		$query = AggregationQuery::create(
			metric: 'sum',
			field: $metricField,
			filter: $filter,
			groupBy: ['field' => $groupField]
		);

		$envelope = $runner->runAdhocByRef(
			registerRef: $this->getRegisterSlug(),
			schemaRef: $schema,
			query: $query
		);

		return $this->shape(dimension: $dimension, envelope: $envelope);
	}//end aggregate()

	/**
	 * Normalise OR's grouped envelope `{groups:[{key,value}], backend}` to the
	 * client shape `{dimension, groups:[{key,amount}], total, backend}`.
	 *
	 * @param string $dimension The dimension identifier.
	 * @param array<string,mixed> $envelope OR's aggregation result envelope.
	 *
	 * @return array<string,mixed> The shaped client payload.
	 */
	private function shape(string $dimension, array $envelope): array {
		$groups = [];
		$total = 0.0;

		$rawGroups = [];
		if (isset($envelope['groups']) === true && is_array($envelope['groups']) === true) {
			$rawGroups = $envelope['groups'];
		}

		foreach ($rawGroups as $group) {
			if (is_array($group) === false) {
				continue;
			}

			$key = ($group['key'] ?? null);
			$amount = (float)($group['value'] ?? 0);
			$total += $amount;

			$groups[] = [
				'key' => $key,
				'amount' => $amount,
			];
		}

		$backend = 'unknown';
		if (isset($envelope['backend']) === true && is_string($envelope['backend']) === true) {
			$backend = $envelope['backend'];
		}

		return [
			'dimension' => $dimension,
			'groups' => $groups,
			'total' => $total,
			'backend' => $backend,
		];

	}//end shape()

	/**
	 * Resolve OR's AggregationRunner from the container, or null when
	 * OpenRegister is unavailable (logged, never silently swallowed — the
	 * caller re-raises so the controller can surface a real error status).
	 *
	 * @return object|null The AggregationRunner, or null.
	 */
	private function getAggregationRunner(): ?object {
		try {
			$runner = $this->container->get(self::OR_AGGREGATION_RUNNER);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SpendAnalyticsService: OpenRegister AggregationRunner unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($runner) === false) {
			return null;
		}

		return $runner;
	}//end getAggregationRunner()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
