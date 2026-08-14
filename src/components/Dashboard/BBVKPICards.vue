<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — KPI cards widget (member 05 of
 bookkeeping-waterschappen-bbv-variant).

 Renders the four headline counts the finance officer reads first when
 opening the dashboard (REQ-BBVW-003 / REQ-BBVW-005):

   - Total active programmes
   - On-track count (utilization ≤ 75%)
   - At-risk count (75% < utilization ≤ 90%)
   - Non-compliant count (utilization > 90%)

 The counts are bucketed in-component from the BBVProgramme rows passed
 in by the parent dashboard — the rows themselves carry the
 server-computed complianceStatus from the slice-02 aggregation, so this
 widget never decides which bucket a programme falls into.

 ADR-031: no derived data is recomputed client-side beyond bucket
 counting. ADR-036 / ADR-037: uses CnStatsBlock from @conduction/nextcloud-vue.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-kpi-cards" data-testid="bbv-kpi-cards">
		<CnStatsBlock
			data-testid="bbv-kpi-total"
			:title="t('shillinq', 'Total programmes')"
			:count="totalCount"
			:countLabel="t('shillinq', 'active')"
			variant="default"
			:loading="loading" />
		<CnStatsBlock
			data-testid="bbv-kpi-on-track"
			:title="t('shillinq', 'On-track')"
			:count="onTrackCount"
			:countLabel="t('shillinq', 'utilization ≤ 75%')"
			variant="success"
			:loading="loading" />
		<CnStatsBlock
			data-testid="bbv-kpi-at-risk"
			:title="t('shillinq', 'At-risk')"
			:count="atRiskCount"
			:countLabel="t('shillinq', '75–90% utilization')"
			variant="warning"
			:loading="loading" />
		<CnStatsBlock
			data-testid="bbv-kpi-non-compliant"
			:title="t('shillinq', 'Non-compliant')"
			:count="nonCompliantCount"
			:countLabel="t('shillinq', '> 90% utilization')"
			variant="error"
			:loading="loading" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'BBVKPICards',
	components: { CnStatsBlock },
	props: {
		programmes: {
			type: Array,
			default: () => [],
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		totalCount() {
			return this.programmes.length
		},

		onTrackCount() {
			return this.programmes.filter((p) => p.complianceStatus === 'on-track')
				.length
		},

		atRiskCount() {
			return this.programmes.filter((p) => p.complianceStatus === 'at-risk')
				.length
		},

		nonCompliantCount() {
			return this.programmes.filter(
				(p) => p.complianceStatus === 'non-compliant',
			).length
		},
	},

	methods: { t },
}
</script>

<style scoped>
.bbv-kpi-cards {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 1rem;
	width: 100%;
}

@media (max-width: 900px) {
	.bbv-kpi-cards {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (max-width: 500px) {
	.bbv-kpi-cards {
		grid-template-columns: 1fr;
	}
}
</style>
