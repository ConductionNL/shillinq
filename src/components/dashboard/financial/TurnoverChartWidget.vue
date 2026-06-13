<!--
  TurnoverChartWidget — turnover per month (trailing 12 months) as
  a bar chart from posted GL revenue lines.

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="turnover-chart" data-testid="turnover-chart">
		<NcLoadingIcon v-if="loading" :size="32" class="turnover-chart__loading" />
		<CnChartWidget
			v-else
			type="bar"
			:series="series"
			:categories="monthLabels"
			:height="260"
			:legend="false"
			:options="options" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { CnChartWidget } from '@conduction/nextcloud-vue'
import chartWidgetMixin from './chartWidgetMixin.js'
import { formatEur } from './financialSeries.js'

export default {
	name: 'TurnoverChartWidget',

	components: { NcLoadingIcon, CnChartWidget },

	mixins: [chartWidgetMixin],

	computed: {
		series() {
			return [{
				name: t('shillinq', 'Turnover'),
				data: this.glSeries ? this.glSeries.revenue : [],
			}]
		},
		options() {
			return {
				yaxis: { labels: { formatter: (value) => formatEur(value) } },
				tooltip: { y: { formatter: (value) => formatEur(value, 2) } },
			}
		},
	},
}
</script>

<style scoped>
.turnover-chart__loading {
	margin: 24px auto;
}
</style>
