<!--
  FinanceKpisWidget — six-tile KPI strip for the Financial overview
  dashboard: turnover YTD, margin YTD (€ + %), open debiteuren,
  open crediteuren, billable share this month, cash position.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="finance-kpis" data-testid="finance-kpis">
		<NcLoadingIcon v-if="loading" :size="32" class="finance-kpis__loading" />
		<div v-else class="finance-kpis__grid">
			<div
				v-for="tile in tiles"
				:key="tile.key"
				class="finance-kpis__tile"
				:data-testid="`finance-kpi-${tile.key}`">
				<span class="finance-kpis__label">{{ tile.label }}</span>
				<span class="finance-kpis__value" :class="tile.valueClass">{{ tile.value }}</span>
				<span v-if="tile.sub" class="finance-kpis__sub">{{ tile.sub }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { translate as t, translatePlural as n, getCanonicalLocale } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useFinancialData } from './useFinancialData.js'
import { computeKpis, formatEur } from './financialSeries.js'

export default {
	name: 'FinanceKpisWidget',

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
		kpis() {
			if (!this.financialData) return null
			return computeKpis(this.financialData)
		},
		tiles() {
			const kpis = this.kpis
			if (!kpis) return []
			const hours = new Intl.NumberFormat(getCanonicalLocale(), { maximumFractionDigits: 0 })
			return [
				{
					key: 'turnover',
					label: t('shillinq', 'Turnover (YTD)'),
					value: formatEur(kpis.turnoverYtd),
				},
				{
					key: 'margin',
					label: t('shillinq', 'Margin (YTD)'),
					value: formatEur(kpis.marginYtd),
					valueClass: kpis.marginYtd < 0 ? 'finance-kpis__value--negative' : 'finance-kpis__value--positive',
					sub: kpis.marginPctYtd !== null ? t('shillinq', '{pct}% of turnover', { pct: kpis.marginPctYtd }) : null,
				},
				{
					key: 'debtors',
					label: t('shillinq', 'Open debtors'),
					value: formatEur(kpis.openArAmount),
					sub: n('shillinq', '%n invoice outstanding', '%n invoices outstanding', kpis.openArCount),
				},
				{
					key: 'creditors',
					label: t('shillinq', 'Open creditors'),
					value: formatEur(kpis.openApAmount),
					sub: n('shillinq', '%n invoice outstanding', '%n invoices outstanding', kpis.openApCount),
				},
				{
					key: 'billable',
					label: t('shillinq', 'Billable this month'),
					value: kpis.billablePct !== null ? `${kpis.billablePct}%` : '—',
					sub: t('shillinq', '{hours} billable hours', { hours: hours.format(kpis.billableHours || 0) }),
				},
				{
					key: 'cash',
					label: t('shillinq', 'Cash position'),
					value: formatEur(kpis.cashPosition),
					valueClass: kpis.cashPosition < 0 ? 'finance-kpis__value--negative' : '',
				},
			]
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
.finance-kpis__grid {
	display: grid;
	grid-template-columns: repeat(6, 1fr);
	gap: 12px;
}

@media (max-width: 1400px) {
	.finance-kpis__grid {
		grid-template-columns: repeat(3, 1fr);
	}
}

@media (max-width: 700px) {
	.finance-kpis__grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

.finance-kpis__tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 10px);
	background-color: var(--color-background-hover, #f5f5f5);
}

.finance-kpis__label {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 13px;
}

.finance-kpis__value {
	font-size: 22px;
	font-weight: 600;
}

.finance-kpis__value--positive {
	color: var(--color-success, #46ba61);
}

.finance-kpis__value--negative {
	color: var(--color-error, #e04224);
}

.finance-kpis__sub {
	color: var(--color-text-maxcontrast, #767676);
	font-size: 12px;
}

.finance-kpis__loading {
	margin: 24px auto;
}
</style>
