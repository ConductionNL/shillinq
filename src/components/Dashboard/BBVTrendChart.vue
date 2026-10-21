<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — YTD cumulative spend trend chart
 (member 05 of bookkeeping-waterschappen-bbv-variant).

 Renders one line per BBV programme over the 12 fiscal months,
 plotting cumulative YTD spend (cents → euros). The cumulative series
 is sourced from the monthly ytdTimeline rows the parent dashboard
 fetches against the GL transactions (slice 04 route returns this
 envelope). An empty timeline shows an empty state: no series is
 drawn from figures that were not measured per month.

 ADR-031: this widget does NOT compute spend totals; it only plots what
 the slice-02 aggregation (ytdSpend on BBVProgramme) materialised. The
 monthly-bucket derivation is a pass-through transformation of the
 timeline payload — no per-account allocation logic runs here.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-trend-chart" data-testid="bbv-trend-chart">
		<p
			v-if="series.length === 0"
			class="bbv-trend-chart__empty"
			data-testid="bbv-trend-chart-empty">
			{{ t('shillinq', 'No monthly spend has been recorded for these programmes yet.') }}
		</p>
		<CnChartWidget
			v-else
			type="line"
			:series="series"
			:categories="categories"
			:height="320"
			:legend="true"
			:options="chartOptions"
			:unavailableLabel="t('shillinq', 'Chart library not available')" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

const MONTH_KEYS = [
	'Jan',
	'Feb',
	'Mar',
	'Apr',
	'May',
	'Jun',
	'Jul',
	'Aug',
	'Sep',
	'Oct',
	'Nov',
	'Dec',
]

export default {
	name: 'BBVTrendChart',
	components: { CnChartWidget },
	props: {
		programmes: {
			type: Array,
			default: () => [],
		},

		/**
		 * Monthly cumulative timeline rows from the slice-04 dashboard
		 * route. Each row carries:
		 *   { programmeCode: string, month: number 1..12, cumulativeSpendCents: integer }
		 * When empty, the widget shows an empty state.
		 */
		timeline: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		categories() {
			return MONTH_KEYS.map((key) => this.t('shillinq', key))
		},

		series() {
			if (this.timeline.length === 0) {
				return []
			}
			return this.seriesFromTimeline()
		},

		chartOptions() {
			return {
				stroke: { width: 2, curve: 'straight' },
				dataLabels: { enabled: false },
				yaxis: {
					labels: {
						formatter: (val) => this.formatEuro(val),
					},
				},

				tooltip: {
					y: {
						formatter: (val) => this.formatEuro(val),
					},
				},
			}
		},
	},

	methods: {
		t,
		seriesFromTimeline() {
			const grouped = new Map()
			for (const row of this.timeline) {
				const code = row.programmeCode || row.code || ''
				if (!code) {
					continue
				}
				if (!grouped.has(code)) {
					grouped.set(code, new Array(12).fill(0))
				}
				const monthIndex = Math.max(
					0,
					Math.min(11, Number(row.month || 1) - 1),
				)
				const cents = Number(row.cumulativeSpendCents || 0)
				grouped.get(code)[monthIndex] = Number.isFinite(cents)
					? cents / 100
					: 0
			}
			const out = []
			for (const programme of this.programmes) {
				const code = programme.programmeCode
				if (!code) {
					continue
				}
				const data = grouped.get(code) || new Array(12).fill(0)
				out.push({
					name: programme.programmeName
						? `${code} ${programme.programmeName}`
						: code,
					data,
				})
			}
			return out
		},

		formatEuro(cents) {
			if (
				cents === null
				|| cents === undefined
				|| !Number.isFinite(Number(cents))
			) {
				return ''
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(Number(cents))
		},
	},
}
</script>

<style scoped>
.bbv-trend-chart__empty {
	color: var(--color-text-maxcontrast);
}

.bbv-trend-chart {
	width: 100%;
	min-height: 320px;
}
</style>
