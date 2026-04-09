<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-4.2
-->
<template>
	<div
		class="kpi-widget-card"
		draggable="true">
		<div class="kpi-widget-card__header">
			<h3>{{ widget.title }}</h3>
		</div>
		<div class="kpi-widget-card__body">
			<template v-if="widget.chartType === 'number'">
				<div class="kpi-widget-card__value">
					{{ formattedValue }}
				</div>
				<div
					class="kpi-widget-card__trend"
					:class="trendClass">
					{{ trendIndicator }}
				</div>
			</template>
			<template v-else>
				<KpiChart
					:chart-type="widget.chartType"
					:data="chartData" />
				<div
					class="kpi-widget-card__trend"
					:class="trendClass">
					{{ trendIndicator }}
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import KpiChart from '../../components/KpiChart.vue'

export default {
	name: 'KpiWidgetCard',
	components: {
		KpiChart,
	},
	props: {
		widget: {
			type: Object,
			required: true,
		},
		data: {
			type: Object,
			default: () => ({}),
		},
	},
	computed: {
		formattedValue() {
			const current = this.data?.current ?? 0
			if (Number.isInteger(current)) {
				return current.toLocaleString()
			}
			return current.toLocaleString(undefined, {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
		},
		trend() {
			return this.data?.trend ?? 'neutral'
		},
		trendIndicator() {
			const indicators = { up: '\u2191', down: '\u2193', neutral: '\u2014' }
			return indicators[this.trend] || '\u2014'
		},
		trendClass() {
			return {
				'kpi-widget-card__trend--up': this.trend === 'up',
				'kpi-widget-card__trend--down': this.trend === 'down',
				'kpi-widget-card__trend--neutral': this.trend === 'neutral',
			}
		},
		chartData() {
			return {
				labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
				values: [0, 0, 0, 0, 0, this.data?.current ?? 0],
			}
		},
	},
}
</script>

<style scoped>
.kpi-widget-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	cursor: grab;
}

.kpi-widget-card__header h3 {
	margin: 0 0 8px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.kpi-widget-card__value {
	font-size: 32px;
	font-weight: bold;
	color: var(--color-main-text);
}

.kpi-widget-card__trend {
	font-size: 18px;
	font-weight: bold;
	margin-top: 4px;
}

.kpi-widget-card__trend--up {
	color: var(--color-success);
}

.kpi-widget-card__trend--down {
	color: var(--color-error);
}

.kpi-widget-card__trend--neutral {
	color: var(--color-text-maxcontrast);
}
</style>
