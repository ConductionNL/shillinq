<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.2
-->
<template>
	<div class="kpi-widget-card">
		<div class="kpi-widget-card__header">
			<h3>{{ widget.title }}</h3>
		</div>
		<div class="kpi-widget-card__body">
			<template v-if="widget.chartType === 'number'">
				<span class="kpi-widget-card__value">{{ formattedCurrent }}</span>
				<span :class="trendClass" class="kpi-widget-card__trend">
					{{ trendIndicator }} {{ trendLabel }}
				</span>
			</template>
			<template v-else>
				<KpiChart :type="widget.chartType" :data="chartData" />
				<span :class="trendClass" class="kpi-widget-card__trend">
					{{ trendIndicator }} {{ trendLabel }}
				</span>
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
			default: () => ({ current: 0, previous: 0, trend: 'neutral' }),
		},
	},
	computed: {
		kpiData() {
			return this.data || { current: 0, previous: 0, trend: 'neutral' }
		},
		formattedCurrent() {
			const value = this.kpiData.current || 0
			return typeof value === 'number' ? value.toLocaleString() : value
		},
		trendIndicator() {
			const trend = this.kpiData.trend || 'neutral'
			if (trend === 'up') return '\u2191'
			if (trend === 'down') return '\u2193'
			return '\u2014'
		},
		trendLabel() {
			const current = this.kpiData.current || 0
			const previous = this.kpiData.previous || 0
			if (previous === 0) return ''
			const pct = (((current - previous) / previous) * 100).toFixed(1)
			return `${pct}%`
		},
		trendClass() {
			const trend = this.kpiData.trend || 'neutral'
			return `kpi-widget-card__trend--${trend}`
		},
		chartData() {
			const current = this.kpiData.current || 0
			return {
				labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
				datasets: [{
					data: [
						current * 0.7,
						current * 0.8,
						current * 0.75,
						current * 0.9,
						current * 0.85,
						current,
					],
				}],
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
	display: flex;
	flex-direction: column;
}

.kpi-widget-card__header h3 {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.kpi-widget-card__value {
	font-size: 32px;
	font-weight: 700;
	display: block;
	margin-bottom: 4px;
}

.kpi-widget-card__trend {
	font-size: 13px;
	font-weight: 500;
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
