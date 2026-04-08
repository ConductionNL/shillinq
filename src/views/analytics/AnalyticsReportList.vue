<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<div class="analytics-report-list">
		<header class="analytics-report-list__header">
			<h2>{{ t('shillinq', 'Analytics Reports') }}</h2>
		</header>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'AnalyticsReport'"
			@bulk-action="handleBulkAction" />

		<NcLoadingIcon v-if="loading" />

		<table v-else class="analytics-report-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleAll">
					</th>
					<th>{{ t('shillinq', 'Title') }}</th>
					<th>{{ t('shillinq', 'Report Type') }}</th>
					<th>{{ t('shillinq', 'Last Run') }}</th>
					<th>{{ t('shillinq', 'Schedule') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="report in reports"
					:key="report.id"
					class="analytics-report-list__row"
					@click="$router.push({ name: 'AnalyticsReportDetail', params: { reportId: report.id } })">
					<td @click.stop>
						<input
							type="checkbox"
							:checked="selectedIds.includes(report.id)"
							@change="toggleSelect(report.id)">
					</td>
					<td>{{ report.title }}</td>
					<td>{{ report.reportType }}</td>
					<td>{{ report.lastRunAt || t('shillinq', 'Never') }}</td>
					<td>{{ report.scheduledCron || t('shillinq', 'Manual') }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import BulkActionBar from '../../components/BulkActionBar.vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'

export default {
	name: 'AnalyticsReportList',
	components: {
		BulkActionBar,
		NcLoadingIcon,
	},
	data() {
		return {
			selectedIds: [],
		}
	},
	computed: {
		analyticsStore() {
			return useAnalyticsStore()
		},
		reports() {
			return this.analyticsStore.reports
		},
		loading() {
			return this.analyticsStore.reportLoading
		},
		allSelected() {
			return this.reports.length > 0 && this.selectedIds.length === this.reports.length
		},
	},
	created() {
		this.analyticsStore.fetchReports()
	},
	methods: {
		toggleSelect(id) {
			const idx = this.selectedIds.indexOf(id)
			if (idx >= 0) {
				this.selectedIds.splice(idx, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		toggleAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.reports.map((r) => r.id)
			}
		},
		handleBulkAction() {
			this.selectedIds = []
			this.analyticsStore.fetchReports()
		},
	},
}
</script>

<style scoped>
.analytics-report-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.analytics-report-list__header {
	margin-bottom: 16px;
}

.analytics-report-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.analytics-report-list__table {
	width: 100%;
	border-collapse: collapse;
}

.analytics-report-list__table th,
.analytics-report-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.analytics-report-list__row {
	cursor: pointer;
}

.analytics-report-list__row:hover {
	background: var(--color-background-hover);
}
</style>
