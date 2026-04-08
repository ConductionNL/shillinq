<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-4.1
-->
<template>
	<div class="analytics-dashboard">
		<header class="analytics-dashboard__header">
			<h2>{{ t('shillinq', 'Analytics Dashboard') }}</h2>
			<NcButton type="primary" @click="showAddWidget = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'Add Widget') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="analytics-dashboard__grid">
			<KpiWidgetCard
				v-for="widget in sortedWidgets"
				:key="widget.id"
				:widget="widget"
				:data="kpiData[widget.metricKey]" />
		</div>

		<NcDialog
			v-if="showAddWidget"
			:name="t('shillinq', 'Add KPI Widget')"
			@close="showAddWidget = false">
			<div class="analytics-dashboard__add-form">
				<NcTextField
					:label="t('shillinq', 'Title')"
					:value.sync="newWidget.title" />
				<NcSelect
					:label="t('shillinq', 'Metric Key')"
					:options="metricKeyOptions"
					:value="newWidget.metricKey"
					@input="newWidget.metricKey = $event" />
				<NcSelect
					:label="t('shillinq', 'Chart Type')"
					:options="chartTypeOptions"
					:value="newWidget.chartType"
					@input="newWidget.chartType = $event" />
				<NcSelect
					:label="t('shillinq', 'Compare With')"
					:options="compareWithOptions"
					:value="newWidget.compareWith"
					@input="newWidget.compareWith = $event" />
				<NcButton type="primary" @click="addWidget">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import KpiWidgetCard from './KpiWidgetCard.vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'

export default {
	name: 'AnalyticsDashboard',
	components: {
		KpiWidgetCard,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		PlusIcon,
	},
	data() {
		return {
			showAddWidget: false,
			newWidget: {
				title: '',
				metricKey: 'total_receivables',
				chartType: 'number',
				compareWith: 'previous_period',
			},
			metricKeyOptions: [
				'total_receivables',
				'overdue_invoices',
				'cash_position',
			],
			chartTypeOptions: ['number', 'line', 'bar', 'donut'],
			compareWithOptions: ['previous_period', 'previous_year', 'budget'],
		}
	},
	computed: {
		analyticsStore() {
			return useAnalyticsStore()
		},
		widgets() {
			return this.analyticsStore.widgets
		},
		kpiData() {
			return this.analyticsStore.kpiData
		},
		loading() {
			return this.analyticsStore.loading
		},
		sortedWidgets() {
			return [...this.widgets].sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
		},
	},
	async created() {
		await this.analyticsStore.fetchWidgets()
		await this.analyticsStore.fetchAllKpiValues()
	},
	methods: {
		async addWidget() {
			const objectStore = (await import('../../store/modules/object.js')).useObjectStore()
			try {
				const url = new URL(objectStore.baseUrl, window.location.origin)
				url.searchParams.set('schema', 'KpiWidget')
				await fetch(url.toString(), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.newWidget),
				})
				this.showAddWidget = false
				await this.analyticsStore.fetchWidgets()
				await this.analyticsStore.fetchAllKpiValues()
			} catch (error) {
				console.error('Failed to create widget:', error)
			}
		},
	},
}
</script>

<style scoped>
.analytics-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.analytics-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.analytics-dashboard__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.analytics-dashboard__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}

.analytics-dashboard__add-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}
</style>
