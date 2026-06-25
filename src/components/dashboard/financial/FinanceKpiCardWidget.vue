<!--
  FinanceKpiCardWidget — a single KPI card for the Financial overview
  dashboard. One instance is mounted per KPI slot (turnover, margin,
  debtors, creditors, billable, cash); each picks its definition from
  KPI_DEFS by the slot's widgetId. Each KPI is its own engine-grid
  card (icon circle + title + value + sub), pipelinq-style.

  Turnover / margin / billable are RANGE-DRIVEN: they aggregate over
  the dashboard's selected date range. Those tiles opt into the shared
  per-card date chip via `layout[].dateChip: true` (rendered by
  CnDashboardPage / CnWidgetWrapper — same shared range ref + persistence
  as the chart widgets), so this component only READS the range. Open
  debtors / creditors / cash position are point-in-time and ignore the
  range (no chip).

  Values reuse the shared, unit-tested computeKpis()/computeRangeKpis()/
  formatEur() layer, so the numbers match the charts and tables on the
  same page exactly.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div
		class="finance-kpi-card"
		:class="{ 'finance-kpi-card--clickable': !!kpiRoute }"
		:data-testid="`finance-kpi-${kpiKey}`"
		:tabindex="kpiRoute ? 0 : undefined"
		:role="kpiRoute ? 'link' : undefined"
		@click="navigateKpi"
		@keyup.enter="navigateKpi">
		<NcLoadingIcon v-if="loading" :size="28" class="finance-kpi-card__loading" />
		<template v-else>
			<div class="finance-kpi-card__icon" :class="`finance-kpi-card__icon--${def.variant}`">
				<component :is="iconComponent" :size="24" />
			</div>
			<div class="finance-kpi-card__content">
				<span class="finance-kpi-card__label">{{ label }}</span>
				<span class="finance-kpi-card__value" :class="`finance-kpi-card__value--${def.variant}`">{{ value }}</span>
				<span v-if="sub" class="finance-kpi-card__sub">{{ sub }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n, getCanonicalLocale } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { inject, ref } from 'vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue'
import AccountArrowLeft from 'vue-material-design-icons/AccountArrowLeft.vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Bank from 'vue-material-design-icons/Bank.vue'
import { useFinancialData } from './useFinancialData.js'
import { computeKpis, computeRangeKpis, formatEur, lastMonths, monthsInRange } from './financialSeries.js'

/** Trailing months used when no dashboard range is active yet. */
const FALLBACK_MONTHS = 12

/**
 * Per-KPI presentation. `value`/`sub` receive the relevant computed
 * bag — the range bag (computeRangeKpis) for `rangeDriven` cards, the
 * point-in-time bag (computeKpis) otherwise — and return display
 * strings. `icon` may be a function of the bag (sign-aware icons);
 * `variantOf` resolves a sign-aware colour variant. The English
 * label strings are the i18n keys.
 */
const KPI_DEFS = {
	turnover: {
		label: () => t('shillinq', 'Turnover'),
		icon: () => CashMultiple,
		variant: 'primary',
		rangeDriven: true,
		value: (b) => formatEur(b.turnover),
	},
	margin: {
		label: () => t('shillinq', 'Margin'),
		icon: (b) => (b.margin < 0 ? TrendingDown : TrendingUp),
		variant: 'success',
		variantOf: (b) => (b.margin < 0 ? 'error' : 'success'),
		rangeDriven: true,
		value: (b) => formatEur(b.margin),
		sub: (b) => (b.marginPct !== null ? t('shillinq', '{pct}% of turnover', { pct: b.marginPct }) : null),
	},
	debtors: {
		label: () => t('shillinq', 'Open debtors'),
		icon: () => AccountArrowLeft,
		variant: 'primary',
		value: (b) => formatEur(b.openArAmount),
		sub: (b) => n('shillinq', '%n invoice outstanding', '%n invoices outstanding', b.openArCount),
	},
	creditors: {
		label: () => t('shillinq', 'Open creditors'),
		icon: () => AccountArrowRight,
		variant: 'default',
		value: (b) => formatEur(b.openApAmount),
		sub: (b) => n('shillinq', '%n invoice outstanding', '%n invoices outstanding', b.openApCount),
	},
	billable: {
		label: () => t('shillinq', 'Billable'),
		icon: () => ClockOutline,
		variant: 'primary',
		rangeDriven: true,
		value: (b) => (b.billablePct !== null ? `${b.billablePct}%` : '—'),
		sub: (b) => {
			const hours = new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 0 })
			return t('shillinq', '{hours} billable hours', { hours: hours.format(b.billableHours || 0) })
		},
	},
	cash: {
		label: () => t('shillinq', 'Cash position'),
		icon: () => Bank,
		variant: 'primary',
		variantOf: (b) => (b.cashPosition < 0 ? 'error' : 'primary'),
		value: (b) => formatEur(b.cashPosition),
	},
}

export default {
	name: 'FinanceKpiCardWidget',

	components: { NcLoadingIcon },

	props: {
		/** Layout item from CnDashboardPage's widget slot scope. */
		item: { type: Object, default: null },
		/** Widget definition from CnDashboardPage's widget slot scope. */
		widget: { type: Object, default: null },
	},

	setup() {
		const { loading, data, load, reload } = useFinancialData()
		// Inject the writable range ref in setup() (NOT via options
		// inject, which Vue 2.7 hands down already-unwrapped — read-only).
		// As a setup-returned ref, `this.dateRange` auto-unwraps on read
		// and assigning `this.dateRange = value` writes through to its
		// `.value`, updating every range-driven widget (charts + sibling
		// KPI cards) and the engine's own chip reactively.
		const dateRange = inject('cnDashboardDateRange', ref(null))
		return { loading, financialData: data, load, reload, dateRange }
	},

	computed: {
		/** @return {string} The KPI key driving this card (turnover, margin, …). */
		kpiKey() {
			return this.item?.widgetId || this.widget?.id || ''
		},
		/** @return {object} Resolved KPI definition, falling back to turnover. */
		def() {
			const base = KPI_DEFS[this.kpiKey] || KPI_DEFS.turnover
			const bag = this.bag
			const variant = (bag && base.variantOf) ? base.variantOf(bag) : base.variant
			return { ...base, variant }
		},
		/** @return {object|null} The active dashboard range, or null. */
		range() {
			// `this.dateRange` is the setup-injected ref, auto-unwrapped to
			// its value on read.
			const r = this.dateRange
			return (r && r.from && r.to) ? r : null
		},
		/** @return {string[]} Month buckets for the active range (or fallback). */
		months() {
			if (this.range) {
				const ms = monthsInRange(this.range.from, this.range.to)
				if (ms.length > 0) return ms
			}
			return lastMonths(FALLBACK_MONTHS)
		},
		/** @return {object|null} Range-aggregated metrics. */
		rangeKpis() {
			if (!this.financialData) return null
			return computeRangeKpis(this.financialData, this.months)
		},
		/** @return {object|null} Point-in-time metrics. */
		kpis() {
			if (!this.financialData) return null
			return computeKpis(this.financialData)
		},
		/** @return {object|null} The bag this card reads from. */
		bag() {
			const base = KPI_DEFS[this.kpiKey] || KPI_DEFS.turnover
			return base.rangeDriven ? this.rangeKpis : this.kpis
		},
		iconComponent() {
			return this.def.icon(this.bag || {})
		},
		label() {
			return this.def.label()
		},
		value() {
			return this.bag ? this.def.value(this.bag) : '—'
		},
		sub() {
			return (this.bag && this.def.sub) ? this.def.sub(this.bag) : null
		},
		/**
		 * Route to navigate to when the card is clicked, or null if the KPI
		 * is a pure aggregate with no matching list page (margin, billable).
		 *
		 * - turnover  → AccountsReceivable (AR invoice list = revenue source)
		 * - debtors   → AccountsReceivable (open debtor invoices)
		 * - creditors → SupplierInvoices   (open creditor invoices)
		 * - cash      → CashflowDashboard  (cash position overview)
		 * - margin    → null (computed ratio; no single list)
		 * - billable  → null (utilisation percentage; no single list)
		 *
		 * @return {{ name: string }|null}
		 */
		kpiRoute() {
			const routes = {
				turnover: { name: 'AccountsReceivable' },
				debtors: { name: 'AccountsReceivable' },
				creditors: { name: 'SupplierInvoices' },
				cash: { name: 'CashflowDashboard' },
			}
			return routes[this.kpiKey] || null
		},
	},

	mounted() {
		this.load()
		this._onRefresh = (payload) => {
			if (payload?.widgetId === this.item?.widgetId) this.reload()
		}
		subscribe('cn:widget:refresh', this._onRefresh)
	},

	beforeDestroy() {
		unsubscribe('cn:widget:refresh', this._onRefresh)
	},

	methods: {
		t,
		/** Navigate to the list page associated with this KPI, if one exists. */
		navigateKpi() {
			if (this.kpiRoute) {
				this.$router.push(this.kpiRoute)
			}
		},
	},
}
</script>

<style scoped>
.finance-kpi-card {
	position: relative;
	display: flex;
	align-items: center;
	gap: 16px;
	box-sizing: border-box;
	height: 100%;
	padding: 16px 20px;
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius-large, 10px);
}

.finance-kpi-card--clickable {
	cursor: pointer;
}

.finance-kpi-card--clickable:hover,
.finance-kpi-card--clickable:focus-visible {
	background-color: var(--color-background-hover, #f5f5f5);
	outline: none;
}

.finance-kpi-card__loading {
	margin: auto;
}

.finance-kpi-card__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	width: 48px;
	height: 48px;
	border-radius: 50%;
}

.finance-kpi-card__icon--primary {
	color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
}

.finance-kpi-card__icon--success {
	color: var(--color-success, #46ba61);
	background-color: rgba(70, 186, 97, 0.1);
}

.finance-kpi-card__icon--error {
	color: var(--color-error, #e04224);
	background-color: rgba(224, 66, 36, 0.1);
}

.finance-kpi-card__icon--default {
	color: var(--color-text-maxcontrast, #767676);
	background-color: var(--color-background-hover, #f5f5f5);
}

.finance-kpi-card__content {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.finance-kpi-card__label {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
	line-height: 1.3;
}

.finance-kpi-card__value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.2;
	color: var(--color-main-text, #222);
}

.finance-kpi-card__value--primary {
	color: var(--color-primary-element, #0082c9);
}

.finance-kpi-card__value--success {
	color: var(--color-success, #46ba61);
}

.finance-kpi-card__value--error {
	color: var(--color-error, #e04224);
}

.finance-kpi-card__sub {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 12px;
	line-height: 1.3;
}
</style>
