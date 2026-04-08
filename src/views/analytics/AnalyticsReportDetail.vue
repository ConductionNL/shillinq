<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<div class="analytics-report-detail">
		<header class="analytics-report-detail__header">
			<h2>{{ report.title || t('shillinq', 'Report') }}</h2>
			<NcButton type="primary" @click="runNow">
				<template #icon>
					<PlayIcon :size="20" />
				</template>
				{{ t('shillinq', 'Run Now') }}
			</NcButton>
		</header>

		<div class="analytics-report-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Report Type') }}</dt>
				<dd>{{ report.reportType }}</dd>
				<dt>{{ t('shillinq', 'Description') }}</dt>
				<dd>{{ report.description || '—' }}</dd>
				<dt>{{ t('shillinq', 'Last Run') }}</dt>
				<dd>{{ report.lastRunAt || t('shillinq', 'Never') }}</dd>
				<dt>{{ t('shillinq', 'Schedule') }}</dt>
				<dd>{{ report.scheduledCron || t('shillinq', 'Manual') }}</dd>
			</dl>
		</div>

		<div v-if="snapshotData" class="analytics-report-detail__snapshot">
			<h3>{{ t('shillinq', 'Report Results') }}</h3>
			<pre>{{ JSON.stringify(snapshotData, null, 2) }}</pre>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'

export default {
	name: 'AnalyticsReportDetail',
	components: {
		NcButton,
		PlayIcon,
	},
	data() {
		return {
			snapshotData: null,
		}
	},
	computed: {
		analyticsStore() {
			return useAnalyticsStore()
		},
		report() {
			const id = this.$route.params.reportId
			return this.analyticsStore.reports.find((r) => r.id === id) || {}
		},
	},
	async created() {
		if (this.analyticsStore.reports.length === 0) {
			await this.analyticsStore.fetchReports()
		}
		if (this.report.snapshotData) {
			try {
				this.snapshotData = JSON.parse(this.report.snapshotData)
			} catch {
				this.snapshotData = this.report.snapshotData
			}
		}
	},
	methods: {
		async runNow() {
			const result = await this.analyticsStore.runReport(this.report.reportType)
			if (result) {
				this.snapshotData = result.snapshotData
			}
		},
	},
}
</script>

<style scoped>
.analytics-report-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.analytics-report-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.analytics-report-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.analytics-report-detail__properties dl {
	display: grid;
	grid-template-columns: 160px 1fr;
	gap: 8px 16px;
}

.analytics-report-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.analytics-report-detail__snapshot {
	margin-top: 24px;
}

.analytics-report-detail__snapshot pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow-x: auto;
	font-size: 13px;
}
</style>
