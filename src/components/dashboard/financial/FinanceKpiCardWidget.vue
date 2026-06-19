<!--
  FinanceKpiCardWidget — a single KPI card for the Financial overview
  dashboard. One instance is mounted per KPI slot (turnover, margin,
  debtors, creditors, billable, cash); each picks its definition from
  KPI_DEFS by the slot's widgetId. Replaces the former single
  six-tile FinanceKpisWidget so the dashboard engine grid-places each
  KPI as its own card (pipelinq-style), with no inner-scroll clipping.

  Values reuse the shared, unit-tested computeKpis()/formatEur() layer,
  so the numbers match the charts and tables on the same page exactly.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="finance-kpi-card" :data-testid="`finance-kpi-${kpiKey}`">
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
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import TrendingDown from 'vue-material-design-icons/TrendingDown.vue'
import AccountArrowLeft from 'vue-material-design-icons/AccountArrowLeft.vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Bank from 'vue-material-design-icons/Bank.vue'
import { useFinancialData } from './useFinancialData.js'
import { computeKpis, formatEur } from './financialSeries.js'

/**
 * Per-KPI presentation. `value`/`sub` receive the computed KPI bag and
 * return display strings; `variant` and `icon` drive the card colours.
 * `icon` may be a function of the KPI bag (sign-aware icons). The
 * English label strings are the i18n keys — kept identical to the old
 * FinanceKpisWidget so existing translations keep resolving.
 */
const KPI_DEFS = {
	turnover: {
		label: () => t('shillinq', 'Turnover (YTD)'),
		icon: () => CashMultiple,
		variant: 'primary',
		value: (k) => formatEur(k.turnoverYtd),
	},
	margin: {
		label: () => t('shillinq', 'Margin (YTD)'),
		icon: (k) => (k.marginYtd < 0 ? TrendingDown : TrendingUp),
		variant: 'success',
		variantOf: (k) => (k.marginYtd < 0 ? 'error' : 'success'),
		value: (k) => formatEur(k.marginYtd),
		sub: (k) => (k.marginPctYtd !== null ? t('shillinq', '{pct}% of turnover', { pct: k.marginPctYtd }) : null),
	},
	debtors: {
		label: () => t('shillinq', 'Open debtors'),
		icon: () => AccountArrowLeft,
		variant: 'primary',
		value: (k) => formatEur(k.openArAmount),
		sub: (k) => n('shillinq', '%n invoice outstanding', '%n invoices outstanding', k.openArCount),
	},
	creditors: {
		label: () => t('shillinq', 'Open creditors'),
		icon: () => AccountArrowRight,
		variant: 'default',
		value: (k) => formatEur(k.openApAmount),
		sub: (k) => n('shillinq', '%n invoice outstanding', '%n invoices outstanding', k.openApCount),
	},
	billable: {
		label: () => t('shillinq', 'Billable this month'),
		icon: () => ClockOutline,
		variant: 'primary',
		value: (k) => (k.billablePct !== null ? `${k.billablePct}%` : '—'),
		sub: (k) => {
			const hours = new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 0 })
			return t('shillinq', '{hours} billable hours', { hours: hours.format(k.billableHours || 0) })
		},
	},
	cash: {
		label: () => t('shillinq', 'Cash position'),
		icon: () => Bank,
		variant: 'primary',
		variantOf: (k) => (k.cashPosition < 0 ? 'error' : 'primary'),
		value: (k) => formatEur(k.cashPosition),
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
		return { loading, financialData: data, load, reload }
	},

	computed: {
		/** @return {string} The KPI key driving this card (turnover, margin, …). */
		kpiKey() {
			return this.item?.widgetId || this.widget?.id || ''
		},
		/** @return {object} Resolved KPI definition, falling back to turnover. */
		def() {
			const base = KPI_DEFS[this.kpiKey] || KPI_DEFS.turnover
			// Resolve a sign-aware variant once the data is in.
			const variant = (this.kpis && base.variantOf) ? base.variantOf(this.kpis) : base.variant
			return { ...base, variant }
		},
		/** @return {object|null} Computed KPI bag, or null while loading. */
		kpis() {
			if (!this.financialData) return null
			return computeKpis(this.financialData)
		},
		iconComponent() {
			return this.def.icon(this.kpis || {})
		},
		label() {
			return this.def.label()
		},
		value() {
			return this.kpis ? this.def.value(this.kpis) : '—'
		},
		sub() {
			return (this.kpis && this.def.sub) ? this.def.sub(this.kpis) : null
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

	methods: { t },
}
</script>

<style scoped>
.finance-kpi-card {
	display: flex;
	align-items: center;
	gap: 16px;
	height: 100%;
	padding: 8px 4px;
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
