<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — YTD cumulative spend trend chart
 (member 05 of bookkeeping-waterschappen-bbv-variant).

 Renders one line per BBV programme over the 12 fiscal months,
 plotting cumulative YTD spend (cents → euros). The cumulative series
 is sourced from the monthly ytdTimeline rows the parent dashboard
 fetches against the GL transactions (slice 04 route returns this
 envelope); when the parent supplies an empty timeline the widget falls
 back to the single-point current-YTD value carried on each BBVProgramme
 row, so the chart still renders before the timeline endpoint lands.

 ADR-031: this widget does NOT compute spend totals; it only plots what
 the slice-02 aggregation (ytdSpend on BBVProgramme) materialised. The
 monthly-bucket derivation is a pass-through transformation of the
 timeline payload — no per-account allocation logic runs here.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-trend-chart" data-testid="bbv-trend-chart">
		<CnChartWidget
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

/**
 * The timeline rows as twelve monthly euro values per programme code; a later
 * row for the same month wins.
 *
 * @param {Array<object>} rows The timeline rows.
 * @return {Map<string, Array<number>>}
 */
function timelineByProgramme(rows) {
	const grouped = new Map()
	for (const row of rows) {
		const code = timelineCode(row)
		if (!code) {
			continue
		}
		if (!grouped.has(code)) {
			grouped.set(code, new Array(12).fill(0))
		}
		grouped.get(code)[timelineMonthIndex(row)] = centsToEuro(row.cumulativeSpendCents)
	}
	return grouped
}

/**
 * The programme code of a timeline row; empty when it has none.
 *
 * @param {object} row The timeline row.
 * @return {string}
 */
function timelineCode(row) {
	return row.programmeCode || row.code || ''
}

/**
 * The zero-based month of a timeline row, clamped to January..December.
 *
 * @param {object} row The timeline row.
 * @return {number}
 */
function timelineMonthIndex(row) {
	return Math.max(0, Math.min(11, Number(row.month || 1) - 1))
}

/**
 * Cents as euros; zero when absent or not a finite number.
 *
 * @param {number|string|null|undefined} value The amount in cents.
 * @return {number}
 */
function centsToEuro(value) {
	const cents = Number(value || 0)
	return Number.isFinite(cents) ? cents / 100 : 0
}

/**
 * The legend name of a programme: its code, with its name when it has one.
 *
 * @param {object} programme The programme.
 * @return {string}
 */
function seriesName(programme) {
	return programme.programmeName
		? `${programme.programmeCode} — ${programme.programmeName}`
		: programme.programmeCode
}

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
		 * When omitted, the widget falls back to a single-point series
		 * derived from each programme's ytdSpend.
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
			if (this.timeline.length > 0) {
				return this.seriesFromTimeline()
			}
			return this.seriesFromCurrentYtd()
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
			const grouped = timelineByProgramme(this.timeline)
			return this.programmes
				.filter((programme) => programme.programmeCode)
				.map((programme) => ({
					name: seriesName(programme),
					data: grouped.get(programme.programmeCode) || new Array(12).fill(0),
				}))
		},

		seriesFromCurrentYtd() {
			// Fallback used before the timeline route is wired (slice 04).
			// Each programme contributes one flat line representing the
			// current YTD spend as a single end-of-most-recent-month value.
			const out = []
			const today = new Date()
			const monthIndex = Math.max(0, Math.min(11, today.getMonth()))
			for (const programme of this.programmes) {
				const cents = Number(programme.ytdSpend || 0)
				const value = Number.isFinite(cents) ? cents / 100 : 0
				const data = new Array(12).fill(null)
				for (let i = 0; i <= monthIndex; i += 1) {
					data[i] = value * ((i + 1) / (monthIndex + 1))
				}
				out.push({ name: seriesName(programme), data })
			}
			return out
		},

		formatEuro(cents) {
			if (
				cents === null
				|| cents === undefined
				|| !Number.isFinite(Number(cents))
			) {
				return '—'
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
.bbv-trend-chart {
	width: 100%;
	min-height: 320px;
}
</style>
