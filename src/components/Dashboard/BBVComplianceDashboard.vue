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
 (`/apps/openregister/api/objects/shillinq/BBVProgramme`). The slice-02
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

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-dashboard">
		<CnDashboardPage
			data-testid="bbv-compliance-dashboard"
			:title="t('shillinq', 'BBV Compliance Dashboard')"
			:description="scopeDescription"
			:widgets="widgets"
			:layout="layout"
			:loading="loading"
			:cellHeight="80"
			:gridMargin="16"
			:emptyLabel="t('shillinq', 'No widgets configured.')"
			:refreshing="loading"
			@refresh="loadProgrammes">
			<!-- Refresh is NOT repeated here: CnActionsMenu already carries
			     it, and `@refresh` above routes that one item to
			     loadProgrammes. -->
			<template #header-actions>
				<span
					v-if="scope.fiscalYear"
					class="bbv-dashboard__fy"
					data-testid="bbv-dashboard-fy-label">
					{{ fyLabel }}
				</span>
				<select
					v-if="administrationOptions.length > 1"
					v-model="administrationId"
					data-testid="bbv-dashboard-administration"
					class="bbv-dashboard__administration"
					:aria-label="t('shillinq', 'Administration')"
					@change="onAdministrationChange">
					<option
						v-for="admin in administrationOptions"
						:key="admin.value"
						:value="admin.value">
						{{ admin.label }}
					</option>
				</select>
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
					@rowClick="onProgrammeClick" />
			</template>
		</CnDashboardPage>

		<p
			v-if="error"
			class="bbv-dashboard__error"
			data-testid="bbv-dashboard-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import BBVComplianceChart from './BBVComplianceChart.vue'
import BBVKPICards from './BBVKPICards.vue'
import BBVProgrammeTable from './BBVProgrammeTable.vue'
import BBVTrendChart from './BBVTrendChart.vue'

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
		return {
			programmes: [],
			timeline: [],
			mappings: [],
			loading: true,
			error: '',
			administrationId: '',
			administrationOptions: [],
			scope: {
				administrationId: null,
				fiscalYear: null,
				startDate: null,
				endDate: null,
			},
		}
	},

	computed: {
		widgets() {
			return [
				{
					id: 'bbv-kpis',
					title: this.t('shillinq', 'Key compliance metrics'),
					type: 'custom',
				},
				{
					id: 'bbv-pie',
					title: this.t('shillinq', 'Compliance status distribution'),
					type: 'custom',
				},
				{
					id: 'bbv-trend',
					title: this.t('shillinq', 'YTD cumulative spend per programme'),
					type: 'custom',
				},
				{
					id: 'bbv-table',
					title: this.t('shillinq', 'Programme utilization'),
					type: 'custom',
				},
			]
		},

		layout() {
			return [
				{
					id: 'layout-kpis',
					widgetId: 'bbv-kpis',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 2,
					showTitle: false,
				},
				{
					id: 'layout-pie',
					widgetId: 'bbv-pie',
					gridX: 0,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 'layout-trend',
					widgetId: 'bbv-trend',
					gridX: 6,
					gridY: 2,
					gridWidth: 6,
					gridHeight: 4,
				},
				{
					id: 'layout-table',
					widgetId: 'bbv-table',
					gridX: 0,
					gridY: 6,
					gridWidth: 12,
					gridHeight: 5,
				},
			]
		},

		fyLabel() {
			if (!this.scope.fiscalYear) {
				return ''
			}
			return this.t('shillinq', 'FY {year}', { year: this.scope.fiscalYear })
		},

		scopeDescription() {
			if (!this.scope.fiscalYear) {
				return this.t(
					'shillinq',
					'Fiscal-year overview of programme utilization and compliance status.',
				)
			}
			return this.t(
				'shillinq',
				'Fiscal-year {year} overview of programme utilization and compliance status.',
				{ year: this.scope.fiscalYear },
			)
		},
	},

	async created() {
		await this.loadAdministrationContext()
		await this.loadProgrammes()
	},

	methods: {
		t,
		async loadAdministrationContext() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const admins = response.data?.administrations || []
				this.administrationOptions = admins.map((a) => ({
					value: a.administrationId,
					label: a.name || a.administrationCode || a.administrationId,
				}))
				if (response.data?.activeAdministrationId) {
					this.administrationId = response.data.activeAdministrationId
				}
			} catch (e) {
				// Inline error; the dashboard still renders an empty envelope.
				this.error = this.t(
					'shillinq',
					'Failed to load administration context',
				)
			}
		},

		async onAdministrationChange() {
			// Server-side scope is derived from the session, but explicitly
			// passing administrationId lets a multi-admin user pivot the
			// view without a session-switch round-trip (REQ-BBVW-006).
			await this.loadProgrammes()
		},

		/**
		 * Load the BBV compliance envelope (widgets, programmes, mappings,
		 * counts, summary) for the active administration and render the
		 * dashboard from it.
		 *
		 * Errors surface inline via `this.error`; the dashboard still renders
		 * an empty envelope rather than a blank page.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		async loadProgrammes() {
			this.loading = true
			this.error = ''
			try {
				const params = {}
				if (this.administrationId) {
					params.administrationId = this.administrationId
				}
				const response = await axios.get(
					// `/api/` prefix is load-bearing — see appinfo/routes.php:
					// the un-prefixed path is the SPA PAGE route for this very
					// dashboard, and registering the JSON endpoint there made
					// the page itself unreachable in a browser.
					generateUrl('/apps/shillinq/api/bbv-dashboard'),
					{ params },
				)
				const data = response.data || {}
				this.programmes = Array.isArray(data.programmes)
					? data.programmes
					: []
				this.mappings = Array.isArray(data.mappings) ? data.mappings : []
				this.timeline = Array.isArray(data.timeline) ? data.timeline : []
				this.scope = data.scope || {
					administrationId: null,
					fiscalYear: null,
					startDate: null,
					endDate: null,
				}
			} catch (e) {
				this.programmes = []
				this.timeline = []
				this.mappings = []
				this.scope = {
					administrationId: null,
					fiscalYear: null,
					startDate: null,
					endDate: null,
				}
				this.error =
					e?.response?.data?.error
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

.bbv-dashboard__fy {
	display: inline-flex;
	align-items: center;
	padding: 0.25rem 0.5rem;
	margin-inline-end: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: 600;
	font-size: var(--default-font-size, 0.875rem);
}

.bbv-dashboard__administration {
	margin-inline-end: 0.5rem;
	max-width: 16rem;
}

.bbv-dashboard__error {
	margin: 1rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: var(--border-radius);
}
</style>
