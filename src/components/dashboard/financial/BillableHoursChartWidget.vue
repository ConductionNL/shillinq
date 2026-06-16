<!--
  BillableHoursChartWidget — stacked billable vs non-billable hours
  per month from UrenRegistratie, with a total / % toggle.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="billable-chart" data-testid="billable-chart">
		<div class="billable-chart__toolbar">
			<NcButton
				:type="mode === 'total' ? 'primary' : 'tertiary'"
				:aria-pressed="mode === 'total' ? 'true' : 'false'"
				data-testid="billable-toggle-total"
				@click="mode = 'total'">
				{{ t('shillinq', 'Hours') }}
			</NcButton>
			<NcButton
				:type="mode === 'pct' ? 'primary' : 'tertiary'"
				:aria-pressed="mode === 'pct' ? 'true' : 'false'"
				data-testid="billable-toggle-pct"
				@click="mode = 'pct'">
				%
			</NcButton>
		</div>
		<NcLoadingIcon v-if="loading" :size="32" class="billable-chart__loading" />
		<CnChartWidget
			v-else
			:key="mode"
			:type="mode === 'pct' ? 'line' : 'bar'"
			:series="series"
			:categories="monthLabels"
			:height="240"
			:options="options" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnChartWidget } from '@conduction/nextcloud-vue'
import chartWidgetMixin from './chartWidgetMixin.js'
import { billableSeries } from './financialSeries.js'

export default {
	name: 'BillableHoursChartWidget',

	components: { NcButton, NcLoadingIcon, CnChartWidget },

	mixins: [chartWidgetMixin],

	data() {
		return {
			/** Active view: stacked hour totals or billable share. */
			mode: 'total',
		}
	},

	computed: {
		hours() {
			if (!this.financialData) return null
			return billableSeries(this.financialData.hourEntries, this.months)
		},
		series() {
			if (!this.hours) return []
			if (this.mode === 'pct') {
				return [{ name: t('shillinq', 'Billable %'), data: this.hours.pct }]
			}
			return [
				{ name: t('shillinq', 'Billable'), data: this.hours.billable },
				{ name: t('shillinq', 'Non-billable'), data: this.hours.nonBillable },
			]
		},
		options() {
			if (this.mode === 'pct') {
				return {
					colors: ['var(--color-primary-element, #0082c9)'],
					stroke: { width: 3 },
					yaxis: {
						max: 100,
						labels: { formatter: (value) => (value === null ? '' : `${Math.round(value)}%`) },
					},
					tooltip: { y: { formatter: (value) => (value === null ? '—' : `${value}%`) } },
				}
			}
			return {
				chart: { stacked: true },
				colors: ['var(--color-success, #46ba61)', 'var(--color-text-maxcontrast, #767676)'],
				plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
				tooltip: { y: { formatter: (value) => t('shillinq', '{hours} hours', { hours: value ?? 0 }) } },
			}
		},
	},

	methods: { t },
}
</script>

<style scoped>
.billable-chart__toolbar {
	display: flex;
	justify-content: flex-end;
	gap: 4px;
}

.billable-chart__loading {
	margin: 24px auto;
}
</style>
