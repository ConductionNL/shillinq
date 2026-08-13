<!--
  CashflowChartWidget — realized monthly in/out columns + net line
  from posted GL lines on liquid-asset accounts, with the 13-week
  CashflowWeek forecast appended as dimmed projection columns.

  @spec openspec/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="cashflow-chart" data-testid="cashflow-chart">
		<NcLoadingIcon v-if="loading" :size="32" class="cashflow-chart__loading" />
		<CnChartWidget
			v-else
			type="line"
			:series="series"
			:categories="categories"
			:height="240"
			:options="options" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { CnChartWidget } from '@conduction/nextcloud-vue'
import chartWidgetMixin from './chartWidgetMixin.js'
import { forecastByMonth, formatEur, monthLabel } from './financialSeries.js'

export default {
	name: 'CashflowChartWidget',

	components: { NcLoadingIcon, CnChartWidget },

	mixins: [chartWidgetMixin],

	computed: {
		forecast() {
			if (!this.financialData)
				return { months: [], cashIn: [], cashOut: [], cashNet: [] }
			const lastRealized = this.months[this.months.length - 1]
			return forecastByMonth(this.financialData.cashflowWeeks, lastRealized)
		},
		categories() {
			return [...this.monthLabels, ...this.forecast.months.map(monthLabel)]
		},
		series() {
			if (!this.glSeries) return []
			const realizedCount = this.months.length
			const forecastCount = this.forecast.months.length
			const padRealized = (data) => [
				...data,
				...Array(forecastCount).fill(null),
			]
			const padForecast = (data) => [
				...Array(realizedCount).fill(null),
				...data,
			]
			const series = [
				{
					name: t('shillinq', 'Money in'),
					type: 'column',
					data: padRealized(this.glSeries.cashIn),
				},
				{
					name: t('shillinq', 'Money out'),
					type: 'column',
					data: padRealized(this.glSeries.cashOut),
				},
				{
					name: t('shillinq', 'Net'),
					type: 'line',
					data: [...this.glSeries.cashNet, ...this.forecast.cashNet],
				},
			]
			if (forecastCount > 0) {
				series.splice(
					2,
					0,
					{
						name: t('shillinq', 'Forecast in'),
						type: 'column',
						data: padForecast(this.forecast.cashIn),
					},
					{
						name: t('shillinq', 'Forecast out'),
						type: 'column',
						data: padForecast(this.forecast.cashOut),
					},
				)
			}
			return series
		},
		options() {
			const colors = ['#46ba61', '#e04224']
			if (this.forecast.months.length > 0)
				colors.push('#46ba6155', '#e0422455')
			colors.push('var(--color-primary-element, #0082c9)')
			return {
				colors,
				stroke: {
					width: this.series.map((s) => (s.type === 'line' ? 3 : 0)),
				},
				plotOptions: { bar: { columnWidth: '60%', borderRadius: 2 } },
				yaxis: { labels: { formatter: (value) => formatEur(value) } },
				tooltip: {
					y: {
						formatter: (value) =>
							value === null ? '—' : formatEur(value, 2),
					},
				},
			}
		},
	},
}
</script>

<style scoped>
.cashflow-chart__loading {
	margin: 24px auto;
}
</style>
