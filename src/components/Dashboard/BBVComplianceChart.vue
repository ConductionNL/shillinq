<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — compliance distribution pie chart
 (member 05 of bookkeeping-waterschappen-bbv-variant).

 Renders the four-bucket compliance status distribution
 (unconfigured / on-track / at-risk / non-compliant) as a single pie
 chart via @conduction/nextcloud-vue CnChartWidget (ApexCharts under the
 hood). The buckets are pre-decided by the slice-02 aggregation
 (complianceStatus computedField); this widget only counts and renders
 — never recomputes thresholds (ADR-031).

 Colour palette matches the design.md status legend:
   on-track    → success-green  (🟢)
   at-risk     → warning-amber  (🟡)
   non-compliant → error-red    (🔴)
   unconfigured → neutral-grey  (⚪)

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-compliance-chart" data-testid="bbv-compliance-chart">
		<CnChartWidget
			type="pie"
			:series="series"
			:labels="labels"
			:colors="colors"
			:height="300"
			:legend="true"
			:unavailableLabel="t('shillinq', 'Chart library not available')" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'BBVComplianceChart',
	components: { CnChartWidget },
	props: {
		programmes: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		buckets() {
			const counts = {
				'on-track': 0,
				'at-risk': 0,
				'non-compliant': 0,
				unconfigured: 0,
			}
			for (const programme of this.programmes) {
				const status = programme.complianceStatus || 'unconfigured'
				if (counts[status] !== undefined) {
					counts[status] += 1
				}
			}
			return counts
		},

		series() {
			return [
				this.buckets['on-track'],
				this.buckets['at-risk'],
				this.buckets['non-compliant'],
				this.buckets.unconfigured,
			]
		},

		labels() {
			return [
				this.t('shillinq', 'On-track'),
				this.t('shillinq', 'At-risk'),
				this.t('shillinq', 'Non-compliant'),
				this.t('shillinq', 'Unconfigured'),
			]
		},

		colors() {
			// Match the status badge palette declared in design.md.
			return ['#46ba61', '#e9a300', '#e9322d', '#8f8f8f']
		},
	},

	methods: { t },
}
</script>

<style scoped>
.bbv-compliance-chart {
	width: 100%;
	min-height: 300px;
}
</style>
