<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.2
-->
<template>
	<div class="kpi-chart">
		<canvas ref="canvas" />
	</div>
</template>

<script>
export default {
	name: 'KpiChart',
	props: {
		type: {
			type: String,
			default: 'line',
			validator: (v) => ['line', 'bar', 'donut'].includes(v),
		},
		data: {
			type: Object,
			default: () => ({
				labels: [],
				datasets: [{ data: [] }],
			}),
		},
	},
	mounted() {
		this.renderChart()
	},
	watch: {
		data: {
			deep: true,
			handler() {
				this.renderChart()
			},
		},
	},
	methods: {
		renderChart() {
			// Use Chart.js if available via Nextcloud's bundled instance.
			if (typeof Chart === 'undefined') {
				return
			}

			if (this._chart) {
				this._chart.destroy()
			}

			const ctx = this.$refs.canvas.getContext('2d')
			const chartType = this.type === 'donut' ? 'doughnut' : this.type

			this._chart = new Chart(ctx, {
				type: chartType,
				data: {
					labels: this.data.labels || [],
					datasets: (this.data.datasets || []).map((ds) => ({
						...ds,
						borderColor: 'var(--color-primary)',
						backgroundColor: this.type === 'line'
							? 'rgba(0, 130, 201, 0.1)'
							: 'var(--color-primary-element-light)',
						fill: this.type === 'line',
					})),
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
					},
					scales: chartType === 'doughnut'
						? {}
						: {
							y: { beginAtZero: true },
						},
				},
			})
		},
	},
	beforeDestroy() {
		if (this._chart) {
			this._chart.destroy()
		}
	},
}
</script>

<style scoped>
.kpi-chart {
	height: 120px;
	width: 100%;
}

.kpi-chart canvas {
	width: 100% !important;
	height: 100% !important;
}
</style>
