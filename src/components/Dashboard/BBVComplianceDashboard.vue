<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — top-level page (member 05 of
 bookkeeping-waterschappen-bbv-variant).

 Composes the four widgets into a single CnDashboardPage:

   ┌────────────────────────────────────────────────────────────────┐
   │  KPI cards          (4 stats blocks — full width)              │
   ├──────────────────────────────────┬─────────────────────────────┤
   │  Compliance pie chart            │  YTD trend line chart       │
   ├──────────────────────────────────┴─────────────────────────────┤
   │  Programme utilization table     (full width, sortable)        │
   └────────────────────────────────────────────────────────────────┘

 The page fetches `BBVProgramme` objects from the OR endpoint
 (`/apps/shillinq/api/openregister/objects/BBVProgramme`). The slice-02
 x-openregister-aggregations block materialises totalBudget, ytdSpend,
 utilization, utilizationPercentage and complianceStatus directly on
 each returned object, so every widget reads server-authoritative data —
 there is no client-side aggregation (ADR-031).

 When slice 04 lands its dedicated `GET /bbv-dashboard` envelope the
 fetcher will be swapped to that route (which additionally returns a
 monthly cumulative timeline for the trend chart) without touching the
 widget components.

 Registered in src/registry.js as a kind:"page" custom component so the
 manifest router can dispatch `customComponent: "BBVComplianceDashboard"`
 once slice 04 declares the route. ADR-036 / ADR-037.

 @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-05-dashboard-widgets/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-dashboard">
		<CnDashboardPage
			data-testid="bbv-compliance-dashboard"
			:title="t('shillinq', 'BBV Compliance Dashboard')"
			:description="t('shillinq', 'Fiscal-year overview of programme utilization and compliance status.')"
			:widgets="widgets"
			:layout="layout"
			:loading="loading"
			:cell-height="80"
			:grid-margin="16"
			:empty-label="t('shillinq', 'No widgets configured.')">
			<template #header-actions>
				<select
					v-model="fiscalYear"
					data-testid="bbv-dashboard-year"
					:aria-label="t('shillinq', 'Fiscal year')"
					@change="loadProgrammes">
					<option
						v-for="year in fiscalYearOptions"
						:key="year"
						:value="year">
						{{ year }}
					</option>
				</select>
				<button
					type="button"
					data-testid="bbv-dashboard-refresh"
					class="bbv-dashboard__refresh"
					@click="loadProgrammes">
					{{ t('shillinq', 'Refresh') }}
				</button>
			</template>

			<template #widget-bbv-kpis>
				<BBVKPICards :programmes="programmes" :loading="loading" />
			</template>

			<template #widget-bbv-pie>
				<BBVComplianceChart :programmes="programmes" />
			</template>

			<template #widget-bbv-trend>
				<BBVTrendChart :programmes="programmes" :timeline="timeline" />
			</template>

			<template #widget-bbv-table>
				<BBVProgrammeTable
					:programmes="programmes"
					:loading="loading"
					@row-click="onProgrammeClick" />
			</template>
		</CnDashboardPage>

		<p v-if="error" class="bbv-dashboard__error" data-testid="bbv-dashboard-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

import BBVKPICards from './BBVKPICards.vue'
import BBVComplianceChart from './BBVComplianceChart.vue'
import BBVTrendChart from './BBVTrendChart.vue'
import BBVProgrammeTable from './BBVProgrammeTable.vue'

export default {
	name: 'BBVComplianceDashboard',
	components: {
		CnDashboardPage,
		BBVKPICards,
		BBVComplianceChart,
		BBVTrendChart,
		BBVProgrammeTable,
	},
	data() {
		const currentYear = new Date().getFullYear()
		return {
			programmes: [],
			timeline: [],
			loading: true,
			error: '',
			fiscalYear: currentYear,
			fiscalYearOptions: [currentYear - 1, currentYear, currentYear + 1],
		}
	},
	computed: {
		widgets() {
			return [
				{ id: 'bbv-kpis', title: this.t('shillinq', 'Key compliance metrics'), type: 'custom' },
				{ id: 'bbv-pie', title: this.t('shillinq', 'Compliance status distribution'), type: 'custom' },
				{ id: 'bbv-trend', title: this.t('shillinq', 'YTD cumulative spend per programme'), type: 'custom' },
				{ id: 'bbv-table', title: this.t('shillinq', 'Programme utilization'), type: 'custom' },
			]
		},
		layout() {
			return [
				{ id: 'layout-kpis', widgetId: 'bbv-kpis', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 2, showTitle: false },
				{ id: 'layout-pie', widgetId: 'bbv-pie', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 'layout-trend', widgetId: 'bbv-trend', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
				{ id: 'layout-table', widgetId: 'bbv-table', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 5 },
			]
		},
	},
	async created() {
		await this.loadProgrammes()
	},
	methods: {
		t,
		async loadProgrammes() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/openregister/objects/BBVProgramme'),
					{
						params: {
							fiscalYear: this.fiscalYear,
							status: 'active',
						},
					},
				)
				const rows = response.data?.results || response.data || []
				this.programmes = Array.isArray(rows) ? rows : []
				this.timeline = Array.isArray(response.data?.timeline)
					? response.data.timeline
					: []
			} catch (e) {
				this.programmes = []
				this.timeline = []
				this.error = e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load BBV programmes')
			} finally {
				this.loading = false
			}
		},
		onProgrammeClick(row) {
			// The table component handles router.push itself; this hook is
			// kept so embedders (e.g. tests, parent shells) can intercept
			// the click without subclassing the table.
			this.$emit('programme-click', row)
		},
	},
}
</script>

<style scoped>
.bbv-dashboard {
	width: 100%;
	min-height: 100%;
}

.bbv-dashboard__refresh {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
	margin-left: 0.5rem;
}

.bbv-dashboard__refresh:hover {
	background: var(--color-background-hover);
}

.bbv-dashboard__error {
	margin: 1rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: var(--border-radius);
}
</style>
