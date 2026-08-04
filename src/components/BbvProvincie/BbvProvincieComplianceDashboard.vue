<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — provincies variant
 (bookkeeping-provincies-bbv-variant, REQ-BBC-001 / 002 / 003).

 The page body is driven entirely by the `dashboard` block the manifest
 fragment declares for the `BbvComplianceDashboard` page: the four KPI
 cards, the two charts, the exceptions block and the three filter facets
 are rendered by iterating that config, so adding a KPI or a facet is a
 manifest edit, not a Vue edit. Every element's `data-testid` is derived
 from the declared id / key (`bbv-kpi-${kpi.id}`, `bbv-chart-${chart.id}`,
 `bbv-filter-${filter.key}`), which is what keeps the manifest and the
 Playwright coverage in lockstep.

 Why a `type: "custom"` page and not `type: "dashboard"`: the shared
 CnDashboardPage renders library-owned widgets on a GridStack canvas and
 emits library-owned `cn-*` testids. The BBV dashboard is a fixed
 statutory layout (KPI row → two charts → exceptions) whose surfaces are
 addressed by name in the BBV spec, and whose exceptions block is a
 domain rule (Remaining < 0) rather than a widget. Same call the
 waterschappen variant made for its own dashboard.

 Numbers are server-authoritative (ADR-031): the per-programme and
 per-period roll-ups come from OpenRegister's `grouped` aggregation
 endpoint, and the Budget totals from the Budget collection. This
 component projects and formats them; it does not re-derive spend from
 raw GL lines.

 Registered in src/registry.js as a kind:"page" component so the manifest
 router can dispatch `component: "BbvProvincieComplianceDashboard"`.
 ADR-036 / ADR-037.

 @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
-->
<template>
	<div class="bbv-provincie-dashboard" data-testid="bbv-compliance-dashboard">
		<header class="bbv-provincie-dashboard__header">
			<div>
				<h2 class="bbv-provincie-dashboard__title">
					{{ title }}
				</h2>
				<p class="bbv-provincie-dashboard__description">
					{{ t('shillinq', 'Budget health per BBV programme: committed and spent against the approved budget, with overspend exceptions.') }}
				</p>
			</div>
			<button
				type="button"
				class="bbv-provincie-dashboard__refresh"
				data-testid="bbv-dashboard-refresh"
				@click="load">
				{{ t('shillinq', 'Refresh') }}
			</button>
		</header>

		<!-- REQ-BBC-002 — the three declared filter facets. -->
		<div
			v-if="filters.length"
			class="bbv-provincie-dashboard__filters"
			data-testid="bbv-dashboard-filters">
			<label
				v-for="filter in filters"
				:key="filter.key"
				class="bbv-provincie-dashboard__filter">
				<span class="bbv-provincie-dashboard__filter-label">{{ filter.label || filter.key }}</span>
				<select
					v-model="filterState[filter.key]"
					class="bbv-provincie-dashboard__filter-control"
					:multiple="isMultiSelect(filter)"
					:size="isMultiSelect(filter) ? 3 : 1"
					:aria-label="filter.label || filter.key"
					:data-testid="`bbv-filter-${filter.key}`"
					@change="onFilterChange">
					<option v-if="!isMultiSelect(filter)" value="">
						{{ t('shillinq', 'All') }}
					</option>
					<option
						v-for="option in filterOptions(filter)"
						:key="option"
						:value="option">
						{{ option }}
					</option>
				</select>
			</label>
		</div>

		<!-- REQ-BBC-001 — the four declared KPI cards. -->
		<div class="bbv-provincie-dashboard__kpis">
			<div
				v-for="kpi in kpis"
				:key="kpi.id"
				class="bbv-provincie-dashboard__kpi"
				:class="kpiBandClass(kpi)"
				:data-testid="`bbv-kpi-${kpi.id}`">
				<CnStatsBlock
					:title="kpi.label || kpi.id"
					:count="kpiCount(kpi)"
					:show-zero-count="true"
					:loading="loading"
					:count-label="kpiCountLabel(kpi)">
					<template #value>
						{{ kpiDisplayValue(kpi) }}
					</template>
				</CnStatsBlock>
			</div>
		</div>

		<!-- REQ-BBC-001 — budget-vs-actuals + cumulative spend trend. -->
		<div class="bbv-provincie-dashboard__charts">
			<section
				v-for="chart in charts"
				:key="chart.id"
				class="bbv-provincie-dashboard__chart"
				:data-testid="`bbv-chart-${chart.id}`">
				<h3 class="bbv-provincie-dashboard__chart-title">
					{{ chart.title || chart.id }}
				</h3>
				<CnChartWidget
					:type="chart.type || 'bar'"
					:series="chartSeries(chart)"
					:categories="chartCategories(chart)"
					:height="300"
					:legend="true"
					:options="chartOptions(chart)"
					:empty-label="t('shillinq', 'No budget or ledger data for the current selection.')"
					:unavailable-label="t('shillinq', 'Chart library not available')" />
			</section>
		</div>

		<!-- REQ-BBC-003 — overspend exceptions, largest overspend first. -->
		<section
			v-if="exceptions"
			class="bbv-provincie-dashboard__exceptions"
			data-testid="bbv-dashboard-exceptions">
			<h3 class="bbv-provincie-dashboard__exceptions-title">
				{{ exceptions.title || t('shillinq', 'Exceptions') }}
			</h3>
			<p
				v-if="!exceptionRows.length"
				class="bbv-provincie-dashboard__exceptions-empty">
				{{ exceptions.emptyState || t('shillinq', 'No overspends') }}
			</p>
			<table v-else class="bbv-provincie-dashboard__exceptions-table">
				<thead>
					<tr>
						<th
							v-for="column in exceptionColumns"
							:key="column.key"
							scope="col">
							{{ column.label || column.key }}
						</th>
						<th scope="col">
							<span class="bbv-provincie-dashboard__sr-only">{{ t('shillinq', 'Remediation') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in exceptionRows"
						:key="row.programmaStructure"
						:data-testid="`bbv-exception-${row.programmaStructure}`">
						<td
							v-for="column in exceptionColumns"
							:key="column.key">
							{{ exceptionCell(row, column) }}
						</td>
						<td>
							<button
								v-if="exceptions.linkRoute"
								type="button"
								class="bbv-provincie-dashboard__exception-link"
								@click="openRemediation(row)">
								{{ t('shillinq', 'Open Budget Links') }}
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</section>

		<p
			v-if="error"
			class="bbv-provincie-dashboard__error"
			role="alert"
			data-testid="bbv-dashboard-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnChartWidget, CnStatsBlock } from '@conduction/nextcloud-vue'
import {
	fetchGroupedTotals,
	fetchObjects,
	formatMetric,
	toNumber,
	trafficLightBand,
} from './bbvProvincieData.js'

/** Schema holding the approved budget per programme + fiscal year. */
const BUDGET_SCHEMA = 'Budget'

/** Schema holding the general-ledger lines the roll-ups aggregate. */
const GL_SCHEMA = 'GLLine'

/** Programme grouping key shared by Budget and GLLine. */
const PROGRAMME_FIELD = 'programmaStructure'

export default {
	name: 'BbvProvincieComplianceDashboard',

	components: {
		CnChartWidget,
		CnStatsBlock,
	},

	/**
	 * CnPageRenderer forwards the whole manifest `config` object to the
	 * dispatched page component. Everything this page reads is a declared
	 * prop; opting out of attribute fallthrough keeps any remaining config
	 * key (documentation notes, keys a future manifest revision adds) from
	 * landing on the root element as a stray DOM attribute.
	 */
	inheritAttrs: false,

	props: {
		/** Page title, lifted out of the manifest page entry. */
		title: {
			type: String,
			default: 'BBV Compliance Dashboard',
		},
		/** Register slug the Budget + GLLine schemas live in. */
		register: {
			type: String,
			default: 'shillinq',
		},
		/**
		 * Primary schema of the page (`Budget`). Declared so the manifest's
		 * `config.schema` binds to a prop instead of falling through onto
		 * the root element as a stray DOM attribute.
		 */
		schema: {
			type: String,
			default: 'Budget',
		},
		/**
		 * The manifest `config.dashboard` block: `kpis[]`, `charts[]`,
		 * `exceptions` and `filters[]`. Everything the page renders is
		 * read from here.
		 */
		dashboard: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			/** Budget rows for the active fiscal-year / status selection. */
			budgets: [],
			/** Server-grouped debit totals per programme. */
			spentByProgramme: {},
			/** Server-grouped credit totals per programme. */
			committedByProgramme: {},
			/** Server-grouped debit totals per accounting period. */
			spentByPeriod: {},
			/** Active facet selection, keyed by the manifest filter key. */
			filterState: {},
		}
	},

	computed: {
		/**
		 * The declared KPI descriptors.
		 *
		 * @return {Array<object>} The `dashboard.kpis` entries.
		 */
		kpis() {
			return Array.isArray(this.dashboard?.kpis) ? this.dashboard.kpis : []
		},
		/**
		 * The declared chart descriptors.
		 *
		 * @return {Array<object>} The `dashboard.charts` entries.
		 */
		charts() {
			return Array.isArray(this.dashboard?.charts) ? this.dashboard.charts : []
		},
		/**
		 * The declared exceptions block.
		 *
		 * @return {?object} The `dashboard.exceptions` entry.
		 */
		exceptions() {
			return this.dashboard?.exceptions ?? null
		},
		/**
		 * The declared exception table columns.
		 *
		 * @return {Array<object>} The column descriptors.
		 */
		exceptionColumns() {
			return Array.isArray(this.exceptions?.columns) ? this.exceptions.columns : []
		},
		/**
		 * The declared filter facets.
		 *
		 * @return {Array<object>} The `dashboard.filters` entries.
		 */
		filters() {
			return Array.isArray(this.dashboard?.filters) ? this.dashboard.filters : []
		},
		/**
		 * Programmes currently in scope — the multi-select selection when
		 * one is active, otherwise every declared programme.
		 *
		 * @return {Array<string>} The programme codes.
		 */
		activeProgrammes() {
			const selected = this.filterState[PROGRAMME_FIELD]
			if (Array.isArray(selected) && selected.length) {
				return selected
			}
			const facet = this.filters.find((f) => f.key === PROGRAMME_FIELD)
			return Array.isArray(facet?.options) ? facet.options : []
		},
		/**
		 * Budget rows narrowed by the active fiscal-year / status / programme
		 * facets. REQ-BBC-002 applies the facets cumulatively (AND).
		 *
		 * @return {Array<object>} The in-scope Budget rows.
		 */
		filteredBudgets() {
			const year = this.filterState.fiscalYear
			const statuses = this.filterState.status
			const programmes = this.activeProgrammes
			return this.budgets.filter((row) => {
				if (programmes.length && !programmes.includes(row[PROGRAMME_FIELD])) {
					return false
				}
				if (year && String(row.fiscalYear) !== String(year)) {
					return false
				}
				if (Array.isArray(statuses) && statuses.length && !statuses.includes(row.status)) {
					return false
				}
				return true
			})
		},
		/**
		 * Approved budget total per programme, over the filtered rows.
		 *
		 * @return {object} Map of programme code → EUR total.
		 */
		budgetByProgramme() {
			const totals = {}
			this.filteredBudgets.forEach((row) => {
				const programme = row[PROGRAMME_FIELD]
				const amount = toNumber(row.totalAmount)
				if (!programme || amount === null) {
					return
				}
				totals[programme] = (totals[programme] ?? 0) + amount
			})
			return totals
		},
		/**
		 * Headline totals the KPI cards and the exceptions rule read from.
		 * `remaining` follows REQ-BBC-001: Total Budget − (Committed + Spent).
		 *
		 * @return {object} `{ totalBudget, committed, spent, remaining }`.
		 */
		totals() {
			const inScope = this.activeProgrammes
			const sumInScope = (map) => Object.entries(map)
				.filter(([key]) => !inScope.length || inScope.includes(key))
				.reduce((acc, [, value]) => acc + value, 0)

			const totalBudget = sumInScope(this.budgetByProgramme)
			const spent = sumInScope(this.spentByProgramme)
			const committed = sumInScope(this.committedByProgramme)
			return {
				'total-budget': totalBudget,
				spent,
				committed,
				remaining: totalBudget - committed - spent,
			}
		},
		/**
		 * Overspent programmes (REQ-BBC-003) — Remaining < 0, largest
		 * overspend first, as the manifest's `sort` block declares.
		 *
		 * @return {Array<object>} The exception rows.
		 */
		exceptionRows() {
			const programmes = new Set([
				...Object.keys(this.budgetByProgramme),
				...Object.keys(this.spentByProgramme),
				...Object.keys(this.committedByProgramme),
			])
			const inScope = this.activeProgrammes
			const rows = []
			programmes.forEach((programme) => {
				if (inScope.length && !inScope.includes(programme)) {
					return
				}
				const totalAmount = this.budgetByProgramme[programme] ?? 0
				const spent = this.spentByProgramme[programme] ?? 0
				const committed = this.committedByProgramme[programme] ?? 0
				const remaining = totalAmount - committed - spent
				if (remaining >= 0) {
					return
				}
				rows.push({
					programmaStructure: programme,
					totalAmount,
					spent,
					committed,
					remaining,
					overspent: Math.abs(remaining),
				})
			})
			rows.sort((a, b) => a.remaining - b.remaining)
			return rows
		},
	},

	created() {
		this.initFilterState()
		this.load()
	},

	methods: {
		/**
		 * Seed the facet state from the URL query so a filtered dashboard is
		 * linkable, falling back to "everything selected" (REQ-BBC-002's
		 * default).
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		initFilterState() {
			const query = this.$route?.query ?? {}
			const state = {}
			this.filters.forEach((filter) => {
				const raw = query[filter.key]
				if (this.isMultiSelect(filter)) {
					if (raw === undefined) {
						state[filter.key] = [...(filter.options ?? [])]
					} else {
						state[filter.key] = String(raw).split(',').filter(Boolean)
					}
					return
				}
				state[filter.key] = raw === undefined ? '' : String(raw)
			})
			this.filterState = state
		},
		/**
		 * Whether a declared facet renders as a multi-select.
		 *
		 * @param {object} filter The manifest filter descriptor.
		 * @return {boolean} True for `multi-select`.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		isMultiSelect(filter) {
			return filter?.type === 'multi-select'
		},
		/**
		 * Option list for a facet: the declared `options[]` when present,
		 * otherwise the distinct values of the declared `source`/`field`
		 * discovered in the loaded rows (that is how the fiscal-year facet
		 * is populated — REQ-BBC-002 calls for auto-discovery).
		 *
		 * @param {object} filter The manifest filter descriptor.
		 * @return {Array<string|number>} The option values.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		filterOptions(filter) {
			if (Array.isArray(filter?.options) && filter.options.length) {
				return filter.options
			}
			if (filter?.source === BUDGET_SCHEMA && filter.field) {
				const seen = new Set()
				this.budgets.forEach((row) => {
					const value = row[filter.field]
					if (value !== null && value !== undefined && value !== '') {
						seen.add(value)
					}
				})
				return [...seen].sort().reverse()
			}
			return []
		},
		/**
		 * Persist the facet selection in the query string and re-query the
		 * ledger roll-ups (the fiscal-year facet narrows them server-side).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async onFilterChange() {
			const query = {}
			Object.entries(this.filterState).forEach(([key, value]) => {
				if (Array.isArray(value)) {
					if (value.length) {
						query[key] = value.join(',')
					}
					return
				}
				if (value !== '' && value !== null && value !== undefined) {
					query[key] = String(value)
				}
			})
			if (this.$router) {
				this.$router.replace({ query }).catch(() => {})
			}
			await this.loadLedgerTotals()
		},
		/**
		 * Load the Budget collection and the ledger roll-ups.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.budgets = await fetchObjects(this.register, BUDGET_SCHEMA, { _limit: 500 })
			} catch (e) {
				this.budgets = []
				this.error = this.t('shillinq', 'Failed to load budgets.')
			}
			await this.loadLedgerTotals()
			this.loading = false
		},
		/**
		 * Ask OpenRegister for the per-programme and per-period ledger
		 * roll-ups. Debit lines are spend, credit lines are commitments —
		 * the same split the schema's `programmeBudgetVsActuals`
		 * aggregation declares.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async loadLedgerTotals() {
			const fiscalYear = this.filterState.fiscalYear
			const scope = fiscalYear ? { fiscalYearId: fiscalYear } : {}
			const request = (groupBy, side) => fetchGroupedTotals(this.register, GL_SCHEMA, {
				groupBy,
				metric: 'sum',
				field: 'amount',
				filter: { ...scope, side },
			}).catch(() => ({}))

			const [spent, committed, byPeriod] = await Promise.all([
				request(PROGRAMME_FIELD, 'debit'),
				request(PROGRAMME_FIELD, 'credit'),
				request('periodId', 'debit'),
			])
			this.spentByProgramme = spent
			this.committedByProgramme = committed
			this.spentByPeriod = byPeriod
		},
		/**
		 * Resolve a KPI descriptor to its numeric value.
		 *
		 * `remaining` is the one descriptor that cannot be expressed in the
		 * manifest's source/field vocabulary — REQ-BBC-001 defines it as
		 * Total Budget − (Committed + Spent), a subtraction spanning two
		 * sources — so the formula is applied here and the manifest entry
		 * carries only the traffic-light bands.
		 *
		 * @param {object} kpi The manifest KPI descriptor.
		 * @return {?number} The value, or null when it cannot be resolved.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		kpiValue(kpi) {
			const value = this.totals[kpi?.id]
			return value === undefined ? null : value
		},
		/**
		 * Formatted KPI value for the card's prominent slot.
		 *
		 * @param {object} kpi The manifest KPI descriptor.
		 * @return {string} The formatted value.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		kpiDisplayValue(kpi) {
			return formatMetric(this.kpiValue(kpi), kpi?.format, kpi?.currency)
		},
		/**
		 * Raw count handed to CnStatsBlock. The card renders the formatted
		 * string through the `value` slot; this only drives the component's
		 * own empty/loading branching.
		 *
		 * @param {object} kpi The manifest KPI descriptor.
		 * @return {number} The numeric value (0 when unresolved).
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		kpiCount(kpi) {
			return this.kpiValue(kpi) ?? 0
		},
		/**
		 * Sub-label under a KPI value. `remaining` additionally shows its
		 * share of the total budget, which is the number the traffic-light
		 * bands are expressed in (REQ-BBC-001).
		 *
		 * @param {object} kpi The manifest KPI descriptor.
		 * @return {string} The label.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		kpiCountLabel(kpi) {
			if (kpi?.id !== 'remaining') {
				return ''
			}
			const ratio = this.remainingRatio()
			return ratio === null ? '' : formatMetric(ratio, 'percent')
		},
		/**
		 * Remaining budget as a share of the total budget.
		 *
		 * @return {?number} The ratio, or null when there is no budget.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		remainingRatio() {
			const total = this.totals['total-budget']
			if (!total) {
				return null
			}
			return this.totals.remaining / total
		},
		/**
		 * Traffic-light modifier class for a KPI card that declares bands.
		 *
		 * @param {object} kpi The manifest KPI descriptor.
		 * @return {string} The CSS modifier class (empty when not banded).
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		kpiBandClass(kpi) {
			if (!kpi?.trafficLight) {
				return ''
			}
			const band = trafficLightBand(this.remainingRatio(), kpi.trafficLight)
			return band === 'unknown' ? '' : `bbv-provincie-dashboard__kpi--${band}`
		},
		/**
		 * X-axis categories for a declared chart: the in-scope programmes
		 * for a `groupBy` chart, the sorted period keys for an `xAxis` one.
		 *
		 * @param {object} chart The manifest chart descriptor.
		 * @return {Array<string>} The categories.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		chartCategories(chart) {
			if (chart?.groupBy === PROGRAMME_FIELD) {
				return this.activeProgrammes
			}
			if (chart?.xAxis) {
				return Object.keys(this.spentByPeriod).sort()
			}
			return []
		},
		/**
		 * Series for a declared chart. Each declared series names its
		 * `source` (Budget vs. the GL roll-ups) and its `field`; the
		 * `cumulative` flag turns the trend series into a running total,
		 * and `zeroFill` renders a period with no postings as 0 rather
		 * than dropping it (REQ-BBC-001).
		 *
		 * @param {object} chart The manifest chart descriptor.
		 * @return {Array<object>} ApexCharts series entries.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		chartSeries(chart) {
			const categories = this.chartCategories(chart)
			const declared = Array.isArray(chart?.series) ? chart.series : []
			return declared.map((series) => {
				const source = this.seriesSource(chart, series)
				let running = 0
				const data = categories.map((category) => {
					const raw = source[category]
					const value = raw === undefined ? (series.zeroFill ? 0 : null) : raw
					if (!series.cumulative) {
						return value
					}
					running += value ?? 0
					return running
				})
				return { name: series.label || series.field, data }
			})
		},
		/**
		 * Resolve the `{ category: value }` map backing one declared series.
		 *
		 * @param {object} chart The manifest chart descriptor.
		 * @param {object} series The manifest series descriptor.
		 * @return {object} The value map.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		seriesSource(chart, series) {
			if (series?.source === BUDGET_SCHEMA) {
				return this.budgetByProgramme
			}
			if (chart?.xAxis) {
				return this.spentByPeriod
			}
			return series?.field === 'netSpent' ? this.committedByProgramme : this.spentByProgramme
		},
		/**
		 * ApexCharts options for a declared chart — currently only the
		 * `orientation: "horizontal"` the budget-vs-actuals bar declares.
		 *
		 * @param {object} chart The manifest chart descriptor.
		 * @return {object} The options object.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		chartOptions(chart) {
			if (chart?.orientation === 'horizontal') {
				return { plotOptions: { bar: { horizontal: true } } }
			}
			return {}
		},
		/**
		 * Render one exception-table cell, formatting the money columns.
		 *
		 * @param {object} row The exception row.
		 * @param {object} column The declared column descriptor.
		 * @return {string} The cell text.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		exceptionCell(row, column) {
			const value = row[column.key]
			if (typeof value === 'number') {
				return formatMetric(value, 'currency', 'EUR')
			}
			return value ?? '—'
		},
		/**
		 * Open the Budget-to-Programme Linker for remediation
		 * (REQ-BBC-003's `linkRoute`).
		 *
		 * @param {object} row The exception row.
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		openRemediation(row) {
			if (!this.$router || !this.exceptions?.linkRoute) {
				return
			}
			this.$router.push({
				name: this.exceptions.linkRoute,
				query: { [PROGRAMME_FIELD]: row.programmaStructure },
			}).catch(() => {})
		},
	},
}
</script>

<style scoped>
.bbv-provincie-dashboard {
	width: 100%;
	min-height: 100%;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	padding-inline-start: 56px;
	box-sizing: border-box;
}

.bbv-provincie-dashboard__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
	flex-wrap: wrap;
}

.bbv-provincie-dashboard__title {
	margin: 0;
}

.bbv-provincie-dashboard__description {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
}

.bbv-provincie-dashboard__refresh,
.bbv-provincie-dashboard__exception-link {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-provincie-dashboard__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin: 1rem 0;
}

.bbv-provincie-dashboard__filter {
	display: inline-flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.bbv-provincie-dashboard__filter-control {
	min-width: 12rem;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 0.25rem 0.5rem;
}

.bbv-provincie-dashboard__kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.bbv-provincie-dashboard__kpi {
	border-radius: var(--border-radius-large, 8px);
}

.bbv-provincie-dashboard__kpi--green {
	box-shadow: inset 4px 0 0 0 var(--color-success);
}

.bbv-provincie-dashboard__kpi--yellow {
	box-shadow: inset 4px 0 0 0 var(--color-warning);
}

.bbv-provincie-dashboard__kpi--red {
	box-shadow: inset 4px 0 0 0 var(--color-error);
}

.bbv-provincie-dashboard__charts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.bbv-provincie-dashboard__chart {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 0.75rem;
	min-height: 20rem;
}

.bbv-provincie-dashboard__chart-title {
	margin: 0 0 0.5rem;
	font-size: 1rem;
}

.bbv-provincie-dashboard__exceptions {
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
	padding: 0.75rem;
}

.bbv-provincie-dashboard__exceptions-title {
	margin: 0 0 0.5rem;
	font-size: 1rem;
	color: var(--color-error);
}

.bbv-provincie-dashboard__exceptions-empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.bbv-provincie-dashboard__exceptions-table {
	width: 100%;
	border-collapse: collapse;
}

.bbv-provincie-dashboard__exceptions-table th,
.bbv-provincie-dashboard__exceptions-table td {
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.bbv-provincie-dashboard__sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.bbv-provincie-dashboard__error {
	margin-top: 1rem;
	color: var(--color-error);
}
</style>
