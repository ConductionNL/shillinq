<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<div class="analytics-report-list">
		<div class="analytics-report-list__header">
			<h2>{{ t('shillinq', 'Analytics Reports') }}</h2>
		</div>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'AnalyticsReport'"
			@bulk-action="onBulkAction" />

		<table class="analytics-report-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleSelectAll">
					</th>
					<th>{{ t('shillinq', 'Title') }}</th>
					<th>{{ t('shillinq', 'Report Type') }}</th>
					<th>{{ t('shillinq', 'Last Run') }}</th>
					<th>{{ t('shillinq', 'Schedule') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="report in analyticsStore.reports"
					:key="report.id"
					@click="openReport(report)">
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
import { useAnalyticsStore } from '../../store/modules/analytics.js'
import BulkActionBar from '../../components/BulkActionBar.vue'

export default {
	name: 'AnalyticsReportList',
	components: {
		BulkActionBar,
	},
	data() {
		return {
			analyticsStore: useAnalyticsStore(),
			selectedIds: [],
		}
	},
	computed: {
		allSelected() {
			return this.analyticsStore.reports.length > 0
				&& this.selectedIds.length === this.analyticsStore.reports.length
		},
	},
	mounted() {
		this.analyticsStore.fetchReports()
	},
	methods: {
		openReport(report) {
			this.$router.push({ name: 'AnalyticsReportDetail', params: { reportId: report.id } })
		},
		toggleSelectAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.analyticsStore.reports.map((r) => r.id)
			}
		},
		toggleSelect(id) {
			const index = this.selectedIds.indexOf(id)
			if (index >= 0) {
				this.selectedIds.splice(index, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		onBulkAction() {
			this.selectedIds = []
			this.analyticsStore.fetchReports()
		},
	},
}
</script>

<style scoped>
.analytics-report-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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

.analytics-report-list__table tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}
</style>
