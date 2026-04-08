<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.3
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Run Report')"
		@close="$emit('close')">
		<div class="analytics-report-run">
			<NcSelect
				:label="t('shillinq', 'Report Type')"
				:options="reportTypeOptions"
				:value="reportType"
				@input="reportType = $event" />
			<NcButton type="primary" :disabled="running" @click="run">
				{{ running ? t('shillinq', 'Running...') : t('shillinq', 'Run Report') }}
			</NcButton>
			<div v-if="result" class="analytics-report-run__result">
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
			reportType: 'debtors_ageing',
			reportTypeOptions: ['debtors_ageing', 'budget_vs_actual', 'cash_flow', 'custom'],
			running: false,
			result: null,
		}
	},
	methods: {
		async run() {
			this.running = true
			const store = useAnalyticsStore()
			this.result = await store.runReport(this.reportType)
			this.running = false
		},
	},
}
</script>

<style scoped>
.analytics-report-run {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.analytics-report-run__result pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow-x: auto;
	font-size: 13px;
}
</style>
