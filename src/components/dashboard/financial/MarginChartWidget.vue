<!--
  MarginChartWidget — revenue/cost columns plus margin line per
  month, with a € / % toggle. The percentage view shows margin as a
  share of revenue.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="margin-chart" data-testid="margin-chart">
		<div class="margin-chart__toolbar">
			<NcButton
				:type="mode === 'value' ? 'primary' : 'tertiary'"
				:aria-pressed="mode === 'value' ? 'true' : 'false'"
				data-testid="margin-toggle-value"
				@click="mode = 'value'">
				€
			</NcButton>
			<NcButton
				:type="mode === 'pct' ? 'primary' : 'tertiary'"
				:aria-pressed="mode === 'pct' ? 'true' : 'false'"
				data-testid="margin-toggle-pct"
				@click="mode = 'pct'">
				%
			</NcButton>
		</div>
		<NcLoadingIcon v-if="loading" :size="32" class="margin-chart__loading" />
		<CnChartWidget
			v-else
			:key="mode"
			type="line"
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
import { formatEur } from './financialSeries.js'

export default {
	name: 'MarginChartWidget',

	components: { NcButton, NcLoadingIcon, CnChartWidget },

	mixins: [chartWidgetMixin],

	data() {
		return {
			/** Active view: absolute euros or margin percentage. */
			mode: 'value',
		}
	},

	computed: {
		series() {
			if (!this.glSeries) return []
			if (this.mode === 'pct') {
				return [{
					name: t('shillinq', 'Margin %'),
					type: 'line',
					data: this.glSeries.marginPct,
				}]
			}
			return [
				{ name: t('shillinq', 'Revenue'), type: 'column', data: this.glSeries.revenue },
				{ name: t('shillinq', 'Costs'), type: 'column', data: this.glSeries.costs },
				{ name: t('shillinq', 'Margin'), type: 'line', data: this.glSeries.margin },
			]
		},
		options() {
			if (this.mode === 'pct') {
				return {
					colors: ['var(--color-primary-element, #0082c9)'],
					stroke: { width: 3 },
					yaxis: { labels: { formatter: (value) => (value === null ? '' : `${Math.round(value)}%`) } },
					tooltip: { y: { formatter: (value) => (value === null ? '—' : `${value}%`) } },
				}
			}
			return {
				colors: [
					'var(--color-primary-element, #0082c9)',
					'var(--color-warning, #e9a300)',
					'var(--color-success, #46ba61)',
				],
				stroke: { width: [0, 0, 3] },
				plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
				yaxis: { labels: { formatter: (value) => formatEur(value) } },
				tooltip: { y: { formatter: (value) => formatEur(value, 2) } },
			}
		},
	},
}
</script>

<style scoped>
.margin-chart__toolbar {
	display: flex;
	justify-content: flex-end;
	gap: 4px;
}

.margin-chart__loading {
	margin: 24px auto;
}
</style>
