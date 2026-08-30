<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  BudgetTrendChart — actual / projected / begroot trend+cumulative chart for
  either a grootboek (Account) or a verzamelpost (LedgerGroup), consumed
  both inline in BudgetGrid (per-row "view trend" toggle) and in
  ChartOfAccountsDetail's sidebar tab — one shared component, registered
  once (REQ-BCH-007).

  Custom kind:"widget" (not a declarative type:"chart"), per design.md §6:
  the actual/projected seam needs per-series dashed/dimmed styling and a
  point-level `unprojectable` marker+tooltip no declarative series[].path
  array can express, and Budgeted is a genuinely separate data source.

  Accessibility is the PRIMARY path here, not a courtesy (design.md §9):
  ApexCharts' own tooltip/legend interaction is not fully keyboard-
  navigable, so the toggleable data table below IS what makes this
  information reachable without a mouse or a screen reader that can parse
  an SVG.

  @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
  @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
  @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
  @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
-->
<template>
	<div
		class="budget-trend-chart"
		data-testid="budget-trend-chart"
		role="group"
		:aria-label="accessibleLabel">
		<div class="budget-trend-chart__toolbar">
			<div class="budget-trend-chart__toggle-group">
				<button
					type="button"
					class="budget-trend-chart__toggle-btn"
					:aria-pressed="mode === 'trend'"
					@click="setMode('trend')">
					{{ t('shillinq', 'Trend') }}
				</button>
				<button
					type="button"
					class="budget-trend-chart__toggle-btn"
					data-testid="budget-trend-chart-toggle-cumulative"
					:aria-pressed="mode === 'cumulative'"
					:disabled="cumulativeDisabled"
					:title="
						cumulativeDisabled
							? t(
									'shillinq',
									'Cumulative equals trend for balance-sheet accounts',
								)
							: null
					"
					@click="setMode('cumulative')">
					{{ t('shillinq', 'Cumulative') }}
				</button>
			</div>
			<button
				type="button"
				class="budget-trend-chart__toggle-btn"
				data-testid="budget-trend-chart-view-table"
				:aria-pressed="showTable"
				@click="showTable = !showTable">
				{{ t('shillinq', 'View as table') }}
			</button>
		</div>

		<NcLoadingIcon
			v-if="loading"
			:size="32"
			class="budget-trend-chart__loading" />

		<CnChartWidget
			v-show="!loading && !showTable"
			type="line"
			:series="series"
			:categories="categories"
			:height="260"
			:options="chartOptions" />

		<table v-if="showTable" class="budget-trend-chart__table">
			<caption>
				{{
					accessibleLabel
				}}
			</caption>
			<thead>
				<tr>
					<th scope="col">{{ t('shillinq', 'Series') }}</th>
					<th v-for="month in months" :key="month" scope="col">
						{{ month }}
					</th>
					<th scope="col">{{ t('shillinq', 'TOTAAL') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<th scope="row">{{ t('shillinq', 'Actual') }}</th>
					<td
						v-for="month in months"
						:key="month"
						data-testid="budget-trend-chart-table-cell">
						{{ actualCellText(month) }}
					</td>
					<td>{{ formatTotal(actualTotal) }}</td>
				</tr>
				<tr>
					<th scope="row">{{ t('shillinq', 'Projected') }}</th>
					<td
						v-for="month in months"
						:key="month"
						data-testid="budget-trend-chart-table-cell">
						{{ projectedCellText(month) }}
						<span
							v-if="isPartialMonth(month)"
							class="budget-trend-chart__partial-badge">
							{{ t('shillinq', 'partial') }}
						</span>
					</td>
					<td>{{ formatTotal(projectedTotal) }}</td>
				</tr>
				<tr>
					<th scope="row">{{ t('shillinq', 'Begroot') }}</th>
					<td
						v-for="month in months"
						:key="month"
						data-testid="budget-trend-chart-table-cell">
						{{ budgetedCellText(month) }}
					</td>
					<td>{{ formatTotal(budgetedTotal) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import {
	cellText,
	centsToEur,
	defaultRange,
	flatSeries,
	formatEurCents,
	isAllStock,
	partialMonths,
	splitActualProjected,
	unprojectableMonths,
} from './budgetChartSeries.js'
import { useBudgetChartData } from './useBudgetChartData.js'

export default {
	name: 'BudgetTrendChart',

	components: { NcLoadingIcon, CnChartWidget },

	props: {
		/** Which entity kind this chart is scoped to. */
		scope: {
			type: String,
			required: true,

			validator: (v) => ['ledgerGroup', 'account'].includes(v),
		},

		/**
		 * The account number (scope: "account") or LedgerGroup id/slug
		 * (scope: "ledgerGroup"). Optional when `objectData` is supplied
		 * instead (the ChartOfAccountsDetail sidebar-tab placement, §1b —
		 * CnObjectSidebar's `widgets[].props` are static manifest JSON, so
		 * that placement hands this component the loaded Account object
		 * rather than pre-shaped scalar props; BudgetGrid's inline
		 * placement, by contrast, passes these directly since it already
		 * has the row's own data).
		 */
		id: { type: String, default: null },
		/** Display name for the accessible group label. Optional when `objectData` is supplied. */
		name: { type: String, default: null },
		/** The administration to scope the chart-series request to. Optional when `objectData` is supplied. */
		administrationId: { type: String, default: null },
		/** `{ from, to }` YYYY-MM period range. Defaults to a trailing-12-months + 3-months-projection window when omitted (the sidebar-tab placement, which has no page-level range of its own). */
		range: { type: Object, default: null },
		/** Optional AnnualBudget override (design.md §2a's forward-compatible scenario stub). */
		annualBudgetId: { type: String, default: null },
		/**
		 * The loaded object (an Account row) — supplied by
		 * CnObjectSidebar's `widgets[]` binding (`widgetBindings()` always
		 * passes `objectData`) instead of pre-shaped `id`/`name`/
		 * `administrationId` props. Null until the sidebar's own object
		 * fetch resolves.
		 */
		objectData: { type: Object, default: null },
	},

	data() {
		return {
			mode: 'trend',
			showTable: false,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-007
		 * @return {string|null} The effective entity id — the `id` prop, falling back to `objectData.accountNumber` (the sidebar-tab placement, §1b).
		 */
		effectiveId() {
			return this.id || this.objectData?.accountNumber || null
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-007
		 * @return {string} The effective accessible-name source — `name`, falling back to `objectData.name`/`accountNumber`.
		 */
		effectiveName() {
			return (
				this.name
				|| this.objectData?.name
				|| this.objectData?.accountNumber
				|| ''
			)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-007
		 * @return {string|null} The effective administration id — `administrationId`, falling back to `objectData.administrationId`.
		 */
		effectiveAdministrationId() {
			return this.administrationId || this.objectData?.administrationId || null
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-007
		 * @return {{from: string, to: string}} The effective period range — `range`, falling back to a trailing-12-months + 3-months default (no page-level range exists on the sidebar-tab placement).
		 */
		effectiveRange() {
			return this.range || defaultRange()
		},

		/** @return {boolean} Whether the entity id and administration are resolved yet (false while the sidebar's own object fetch is still in flight). */
		isReady() {
			return Boolean(this.effectiveId && this.effectiveAdministrationId)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
		 * @return {boolean} Whether the shared fetch-once request is in flight.
		 */
		loading() {
			if (!this.isReady) return false
			return useBudgetChartData(
				this.effectiveAdministrationId,
				this.effectiveRange,
				this.annualBudgetId,
			).loading.value
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
		 * @return {object|null} The shared `{ months, accounts, ledgerGroups }` payload.
		 */
		chartData() {
			if (!this.isReady) return null
			return useBudgetChartData(
				this.effectiveAdministrationId,
				this.effectiveRange,
				this.annualBudgetId,
			).data.value
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
		 * @return {string[]} The requested months, chronological.
		 */
		months() {
			return this.chartData?.months ?? []
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
		 * @return {object|null} This chart's own entry (account or LedgerGroup) from the shared payload.
		 */
		entry() {
			if (!this.chartData) return null
			const collection =
				this.scope === 'account'
					? this.chartData.accounts
					: this.chartData.ledgerGroups
			const key = this.scope === 'account' ? 'accountNumber' : 'ledgerGroupKey'
			return (
				(collection || []).find((row) => row[key] === this.effectiveId)
				|| null
			)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
		 * @return {string[]} The account type(s) in scope (one for an account, the resolved set for a LedgerGroup).
		 */
		accountTypes() {
			if (!this.entry) return []
			return this.scope === 'account'
				? [this.entry.accountType]
				: this.entry.accountTypes || []
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
		 * @return {boolean} REQ-BCH-005: disable the Cumulative toggle when every in-scope account type is stock-typed.
		 */
		cumulativeDisabled() {
			return isAllStock(this.accountTypes)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
		 * @return {string} The effective mode, falling back to trend when cumulative is disabled.
		 */
		effectiveMode() {
			return this.mode === 'cumulative' && this.cumulativeDisabled
				? 'trend'
				: this.mode
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
		 * @return {{[month: string]: object}} Month => typed trend result (REQ-BPE-006's combined actual/projected/unprojectable series).
		 */
		trend() {
			return this.entry?.trend ?? {}
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {{actual: (number|null)[], projected: (number|null)[]}} The Actual/Projected gap-series pair for the TREND view.
		 */
		trendSplit() {
			return splitActualProjected(this.months, this.trend)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {{actual: (number|null)[], projected: (number|null)[]}} The Actual/Projected gap-series pair for the CUMULATIVE view — same seam, cumulative amounts.
		 */
		cumulativeSplit() {
			// The cumulative series carries no `kind` of its own — it is
			// keyed by the SAME months as `trend`, so the seam (which
			// month is Actual vs Projected) is read from `trend`, only the
			// plotted VALUE comes from `entry.cumulative`.
			const cumulative = this.entry?.cumulative ?? {}
			const actual = []
			const projected = []
			for (const month of this.months) {
				const kind = this.trend?.[month]?.kind
				const value = centsToEur(cumulative[month])
				actual.push(kind === 'actual' ? value : null)
				projected.push(kind === 'projected' ? value : null)
			}
			return { actual, projected }
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
		 * @return {(number|null)[]} The Begroot series for the active mode.
		 */
		budgetedPoints() {
			const source =
				this.effectiveMode === 'cumulative'
					? this.entry?.budgetedCumulative
					: this.entry?.budgeted
			return flatSeries(this.months, source ?? {})
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {(number|null)[]} The Actual series for the active mode.
		 */
		actualPoints() {
			return (
				this.effectiveMode === 'cumulative'
					? this.cumulativeSplit
					: this.trendSplit
			).actual
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {(number|null)[]} The Projected series for the active mode.
		 */
		projectedPoints() {
			return (
				this.effectiveMode === 'cumulative'
					? this.cumulativeSplit
					: this.trendSplit
			).projected
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
		 * @return {string[]} Months whose typed result is `unprojectable` — REQ-BCH-004's marker+tooltip months.
		 */
		unprojectableMonthsList() {
			return unprojectableMonths(this.months, this.trend)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
		 * @return {string[]} Months carrying a `partial` group roll-up tag (REQ-BPE-007).
		 */
		partialMonthsList() {
			return partialMonths(this.months, this.trend)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @return {string[]} Localised month labels for the chart x-axis and table header.
		 */
		categories() {
			return this.months
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {Array<object>} The three named ApexCharts series for the active mode (REQ-BCH-006).
		 */
		series() {
			return [
				{
					name: t('shillinq', 'Actual'),
					type: 'line',
					data: this.actualPoints,
				},
				{
					name: t('shillinq', 'Projected'),
					type: 'line',
					data: this.projectedPoints,
				},
				{
					name: t('shillinq', 'Begroot'),
					type: 'line',
					data: this.budgetedPoints,
				},
			]
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
		 * @return {object} ApexCharts options: colors (ADR-053 `var(--token, fallback)`), dashed Projected stroke, unprojectable-aware tooltip + annotations.
		 */
		chartOptions() {
			const actualColor = 'var(--color-success, #46ba61)'
			const projectedColor =
				'color-mix(in srgb, var(--color-success, #46ba61) 55%, transparent)'
			const budgetedColor = 'var(--color-primary-element, #0082c9)'
			const unprojectable = this.unprojectableMonthsList
			const months = this.months

			return {
				colors: [actualColor, projectedColor, budgetedColor],
				stroke: {
					width: 3,
					// Shape difference, not colour-only (WCAG 1.4.11 / design.md §9):
					// Actual + Begroot solid, Projected dashed.
					dashArray: [0, 6, 0],
				},

				markers: { size: 3 },
				yaxis: {
					labels: {
						formatter: (value) =>
							formatEurCents(
								value === null ? null : Math.round(value * 100),
							),
					},
				},

				tooltip: {
					y: {
						formatter: (value, opts) => {
							const isProjectedSeries = opts?.seriesIndex === 1
							const month = months[opts?.dataPointIndex]
							if (isProjectedSeries && unprojectable.includes(month)) {
								return t('shillinq', 'Cannot project yet')
							}
							return value === null
								? '—'
								: formatEurCents(Math.round(value * 100))
						},
					},
				},

				annotations: {
					points: unprojectable.map((month) => ({
						x: month,
						y: 0,
						marker: { size: 0 },
						label: {
							text: '—',
							borderWidth: 0,
							style: {
								color: 'var(--color-text-maxcontrast)',
								background: 'transparent',
							},
						},
					})),
				},
			}
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @return {string} The chart's accessible group name (REQ-BCH-009).
		 */
		accessibleLabel() {
			return t(
				'shillinq',
				'Trend chart for {name}: actual, projected and budgeted amounts',
				{ name: this.effectiveName },
			)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @return {number} Sum of the plotted Actual series, in EUR cents, for the TOTAAL column.
		 */
		actualTotal() {
			return this.sumCents(
				this.months.map((m) =>
					this.trend[m]?.kind === 'actual' ? this.trend[m].amount : null,
				),
			)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @return {number} Sum of the plotted Projected series, in EUR cents (unprojectable months contribute nothing, never a fabricated amount).
		 */
		projectedTotal() {
			return this.sumCents(
				this.months.map((m) =>
					this.trend[m]?.kind === 'projected'
						? this.trend[m].amount
						: null,
				),
			)
		},

		/**
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @return {number} Sum of the Begroot series, in EUR cents.
		 */
		budgetedTotal() {
			const budgeted = this.entry?.budgeted ?? {}
			return this.sumCents(this.months.map((m) => budgeted[m] ?? null))
		},
	},

	watch: {
		effectiveAdministrationId: 'load',
		effectiveRange: { handler: 'load', deep: true },
		annualBudgetId: 'load',
		// objectData starts null on the sidebar-tab placement until
		// CnDetailPage/CnObjectSidebar's own object fetch resolves — once
		// it does, effectiveId/effectiveAdministrationId become non-null
		// and the chart's first fetch can fire.
		objectData: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Trigger the shared fetch-once load (no-op if already cached for
		 * this key, and a no-op entirely until `isReady`).
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
		 * @return {Promise<object>|undefined}
		 */
		load() {
			if (!this.isReady) return undefined
			return useBudgetChartData(
				this.effectiveAdministrationId,
				this.effectiveRange,
				this.annualBudgetId,
			).load()
		},

		/**
		 * Set the Trend/Cumulative mode, ignoring an attempt to switch to
		 * Cumulative when it is disabled (REQ-BCH-005).
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
		 * @param {string} next The requested mode.
		 * @return {void}
		 */
		setMode(next) {
			if (next === 'cumulative' && this.cumulativeDisabled) return
			this.mode = next
		},

		/**
		 * Accessible data-table cell text for the Actual row.
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @param {string} month The `YYYY-MM` month.
		 * @return {string}
		 */
		actualCellText(month) {
			const entry = this.trend[month]
			if (!entry || entry.kind !== 'actual') return '—'
			return cellText(t, entry, null)
		},

		/**
		 * Accessible data-table cell text for the Projected row —
		 * `unprojectable` renders the literal localised "Cannot project
		 * yet" text, never a blank cell and never "€0" (REQ-BCH-004).
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
		 * @param {string} month The `YYYY-MM` month.
		 * @return {string}
		 */
		projectedCellText(month) {
			const entry = this.trend[month]
			if (!entry) return '—'
			if (entry.kind === 'unprojectable') return cellText(t, entry, null)
			if (entry.kind !== 'projected') return '—'
			return cellText(t, entry, null)
		},

		/**
		 * Accessible data-table cell text for the Begroot row.
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @param {string} month The `YYYY-MM` month.
		 * @return {string}
		 */
		budgetedCellText(month) {
			const budgeted = this.entry?.budgeted ?? {}
			return formatEurCents(budgeted[month] ?? 0)
		},

		/**
		 * Whether a month carries the `partial` group roll-up tag.
		 *
		 * @param {string} month The `YYYY-MM` month.
		 * @return {boolean}
		 */
		isPartialMonth(month) {
			return this.partialMonthsList.includes(month)
		},

		/**
		 * Sum a list of nullable EUR-cent amounts, treating a null entry
		 * as a non-contribution (never a fabricated zero counted as a real
		 * value, mirroring BudgetProjectionCalculator's own "unprojectable
		 * contributes 0" cumulative rule for the TOTAAL column).
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @param {(number|null)[]} amounts The amounts to sum, in EUR cents.
		 * @return {number} The total, in EUR cents.
		 */
		sumCents(amounts) {
			return amounts.reduce((sum, amount) => sum + (amount ?? 0), 0)
		},

		/**
		 * Format a EUR-cents total for the TOTAAL column.
		 *
		 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
		 * @param {number} cents The total, in EUR cents.
		 * @return {string}
		 */
		formatTotal(cents) {
			return formatEurCents(cents)
		},
	},
}
</script>

<style scoped>
.budget-trend-chart__loading {
	margin: 24px auto;
}

.budget-trend-chart__toolbar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.budget-trend-chart__toggle-group {
	display: flex;
	gap: 4px;
}

.budget-trend-chart__toggle-btn {
	border: 1px solid var(--color-border, #dbdbdb);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border-radius: var(--border-radius, 4px);
	padding: 6px 12px;
	cursor: pointer;
	font-size: 0.9rem;
}

.budget-trend-chart__toggle-btn[aria-pressed='true'] {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #0082c9);
}

.budget-trend-chart__toggle-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.budget-trend-chart__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.85rem;
}

.budget-trend-chart__table th,
.budget-trend-chart__table td {
	border: 1px solid var(--color-border, #dbdbdb);
	padding: 4px 8px;
	text-align: right;
	white-space: nowrap;
}

.budget-trend-chart__table th[scope='row'] {
	text-align: left;
}

.budget-trend-chart__partial-badge {
	display: inline-block;
	margin-left: 4px;
	padding: 0 4px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-warning, #ffcc00);
	color: var(--color-main-text, #222);
	font-size: 0.7rem;
}
</style>
