<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<div class="analytics-report-detail">
		<div class="analytics-report-detail__header">
			<h2>{{ report.title || t('shillinq', 'Report Detail') }}</h2>
			<NcButton
				type="primary"
				@click="runReport">
				{{ t('shillinq', 'Run Now') }}
			</NcButton>
		</div>

		<div class="analytics-report-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Report Type') }}</dt>
				<dd>{{ report.reportType }}</dd>
				<dt>{{ t('shillinq', 'Description') }}</dt>
				<dd>{{ report.description || t('shillinq', 'No description') }}</dd>
				<dt>{{ t('shillinq', 'Last Run') }}</dt>
				<dd>{{ report.lastRunAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Schedule') }}</dt>
				<dd>{{ report.scheduledCron || t('shillinq', 'Manual') }}</dd>
			</dl>
		</div>

		<div
			v-if="snapshotData"
			class="analytics-report-detail__snapshot">
			<h3>{{ t('shillinq', 'Report Results') }}</h3>
			<pre>{{ JSON.stringify(snapshotData, null, 2) }}</pre>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'

export default {
	name: 'AnalyticsReportDetail',
	components: {
		NcButton,
	},
	data() {
		return {
			analyticsStore: useAnalyticsStore(),
			snapshotData: null,
		}
	},
	computed: {
		report() {
			const reportId = this.$route.params.reportId
			return this.analyticsStore.reports.find((r) => r.id === reportId) || {}
		},
	},
	mounted() {
		if (this.analyticsStore.reports.length === 0) {
			this.analyticsStore.fetchReports()
		}
	},
	methods: {
		async runReport() {
			const result = await this.analyticsStore.runReport(this.report.reportType)
			if (result) {
				this.snapshotData = result
			}
		},
	},
}
</script>

<style scoped>
.analytics-report-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.analytics-report-detail__properties dl {
	display: grid;
	grid-template-columns: 150px 1fr;
	gap: 8px;
}

.analytics-report-detail__properties dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.analytics-report-detail__snapshot pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow-x: auto;
}
</style>
