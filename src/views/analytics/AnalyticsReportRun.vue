<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Run Report')"
		@close="$emit('close')">
		<div class="report-run">
			<label>{{ t('shillinq', 'Report Type') }}</label>
			<NcSelect
				v-model="reportType"
				:options="reportTypeOptions" />

			<label>{{ t('shillinq', 'Parameters (JSON)') }}</label>
			<textarea
				v-model="parametersJson"
				class="report-run__params"
				rows="4" />

			<NcButton
				type="primary"
				:disabled="running"
				@click="run">
				{{ running ? t('shillinq', 'Running...') : t('shillinq', 'Run Report') }}
			</NcButton>

			<div
				v-if="result"
				class="report-run__result">
				<h4>{{ t('shillinq', 'Results') }}</h4>
				<pre>{{ JSON.stringify(result, null, 2) }}</pre>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'

export default {
	name: 'AnalyticsReportRun',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},
	data() {
		return {
			analyticsStore: useAnalyticsStore(),
			reportType: 'debtors_ageing',
			reportTypeOptions: ['debtors_ageing', 'budget_vs_actual', 'cash_flow', 'custom'],
			parametersJson: '{}',
			running: false,
			result: null,
		}
	},
	methods: {
		async run() {
			this.running = true
			try {
				const params = JSON.parse(this.parametersJson)
				this.result = await this.analyticsStore.runReport(this.reportType, params)
			} catch (error) {
				console.error('Failed to run report:', error)
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.report-run {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.report-run label {
	font-weight: bold;
}

.report-run__params {
	font-family: monospace;
	resize: vertical;
}

.report-run__result pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow-x: auto;
}
</style>
