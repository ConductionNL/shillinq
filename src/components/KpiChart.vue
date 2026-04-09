<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-4.2
-->
<template>
	<div class="kpi-chart">
		<canvas ref="chartCanvas" />
	</div>
</template>

<script>
export default {
	name: 'KpiChart',
	props: {
		chartType: {
			type: String,
			required: true,
			validator: (val) => ['line', 'bar', 'donut'].includes(val),
		},
		data: {
			type: Object,
			default: () => ({ labels: [], values: [] }),
		},
	},
	mounted() {
		this.renderChart()
	},
	watch: {
		data: {
			handler() {
				this.renderChart()
			},
			deep: true,
		},
	},
	methods: {
		renderChart() {
			const canvas = this.$refs.chartCanvas
			if (!canvas) {
				return
			}

			const ctx = canvas.getContext('2d')
			const width = canvas.parentElement.clientWidth || 250
			const height = 120

			canvas.width = width
			canvas.height = height

			ctx.clearRect(0, 0, width, height)

			const values = this.data.values || []
			if (values.length === 0) {
				return
			}

			const maxVal = Math.max(...values, 1)
			const padding = 10

			if (this.chartType === 'line' || this.chartType === 'bar') {
				const stepX = (width - padding * 2) / Math.max(values.length - 1, 1)

				if (this.chartType === 'bar') {
					const barWidth = stepX * 0.6
					ctx.fillStyle = 'var(--color-primary-element, #0082c9)'
					values.forEach((val, i) => {
						const barHeight = (val / maxVal) * (height - padding * 2)
						const x = padding + i * stepX - barWidth / 2
						const y = height - padding - barHeight
						ctx.fillRect(x, y, barWidth, barHeight)
					})
				} else {
					ctx.strokeStyle = 'var(--color-primary-element, #0082c9)'
					ctx.lineWidth = 2
					ctx.beginPath()
					values.forEach((val, i) => {
						const x = padding + i * stepX
						const y = height - padding - (val / maxVal) * (height - padding * 2)
						if (i === 0) {
							ctx.moveTo(x, y)
						} else {
							ctx.lineTo(x, y)
						}
					})
					ctx.stroke()
				}
			}
		},
	},
}
</script>

<style scoped>
.kpi-chart {
	width: 100%;
	height: 120px;
}

.kpi-chart canvas {
	width: 100%;
	height: 100%;
}
</style>
