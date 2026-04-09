<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-4.1
-->
<template>
	<div class="analytics-dashboard">
		<div class="analytics-dashboard__header">
			<h2>{{ t('shillinq', 'Analytics Dashboard') }}</h2>
			<NcButton @click="showAddWidget = true">
				{{ t('shillinq', 'Add Widget') }}
			</NcButton>
		</div>

		<div class="analytics-dashboard__grid">
			<KpiWidgetCard
				v-for="widget in sortedWidgets"
				:key="widget.id"
				:widget="widget"
				:data="analyticsStore.kpiData[widget.metricKey]"
				@dragstart.native="onDragStart(widget, $event)"
				@dragover.native.prevent
				@drop.native="onDrop(widget, $event)" />
		</div>

		<NcDialog
			v-if="showAddWidget"
			:name="t('shillinq', 'Add KPI Widget')"
			@close="showAddWidget = false">
			<div class="widget-form">
				<label>{{ t('shillinq', 'Metric Key') }}</label>
				<NcSelect
					v-model="newWidget.metricKey"
					:options="metricKeyOptions" />
				<label>{{ t('shillinq', 'Chart Type') }}</label>
				<NcSelect
					v-model="newWidget.chartType"
					:options="chartTypeOptions" />
				<label>{{ t('shillinq', 'Compare With') }}</label>
				<NcSelect
					v-model="newWidget.compareWith"
					:options="compareWithOptions" />
				<NcButton
					type="primary"
					@click="addWidget">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { useAnalyticsStore } from '../../store/modules/analytics.js'
import KpiWidgetCard from './KpiWidgetCard.vue'

export default {
	name: 'AnalyticsDashboard',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		KpiWidgetCard,
	},
	data() {
		return {
			analyticsStore: useAnalyticsStore(),
			showAddWidget: false,
			draggedWidget: null,
			newWidget: {
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
		sortedWidgets() {
			return [...this.analyticsStore.kpiWidgets].sort(
				(a, b) => (a.sortOrder || 0) - (b.sortOrder || 0),
			)
		},
	},
	mounted() {
		this.analyticsStore.fetchWidgets()
		this.fetchAllKpiValues()
	},
	methods: {
		async fetchAllKpiValues() {
			const keys = ['total_receivables', 'overdue_invoices', 'cash_position']
			for (const key of keys) {
				await this.analyticsStore.fetchKpiValue(key)
			}
		},
		async addWidget() {
			const objectStore = (await import('../../store/modules/object.js')).useObjectStore()
			objectStore.registerObjectType('KpiWidget', 'KpiWidget', 'shillinq')
			this.showAddWidget = false
		},
		onDragStart(widget, event) {
			this.draggedWidget = widget
			event.dataTransfer.effectAllowed = 'move'
		},
		onDrop(targetWidget) {
			if (this.draggedWidget && this.draggedWidget.id !== targetWidget.id) {
				const draggedOrder = this.draggedWidget.sortOrder || 0
				this.draggedWidget.sortOrder = targetWidget.sortOrder || 0
				targetWidget.sortOrder = draggedOrder
			}
			this.draggedWidget = null
		},
	},
}
</script>

<style scoped>
.analytics-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.analytics-dashboard__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}

.widget-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.widget-form label {
	font-weight: bold;
}
</style>
