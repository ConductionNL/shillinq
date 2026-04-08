// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

/**
 * Analytics store for KpiWidget and report state.
 *
 * @see openspec/changes/general/tasks.md#task-3.1
 */
const kpiPlugin = {
	name: 'kpi',
	state: () => ({
		kpiData: {},
		reportLoading: false,
	}),
	getters: {
		widgets: (state) => state.collections?.KpiWidget || [],
		reports: (state) => state.collections?.AnalyticsReport || [],
		getKpiData: (state) => state.kpiData,
		widgetLoading: (state) => state.loading?.KpiWidget || false,
	},
	actions: {
		async fetchWidgets() {
			return this.fetchCollection('KpiWidget')
		},

		async fetchReports() {
			return this.fetchCollection('AnalyticsReport')
		},

		async fetchKpiValue(metricKey) {
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/analytics/kpi/${metricKey}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.kpiData = { ...this.kpiData, [metricKey]: data }
					return data
				}
			} catch (error) {
				console.error(`Failed to fetch KPI ${metricKey}:`, error)
			}
			return null
		},

		async fetchAllKpiValues() {
			const keys = this.widgets.map((w) => w.metricKey)
			await Promise.all(keys.map((key) => this.fetchKpiValue(key)))
		},

		async runReport(reportType, parameters = {}) {
			this.reportLoading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/analytics/reports/${reportType}/run`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(parameters),
					},
				)
				if (response.ok) {
					return await response.json()
				}
			} catch (error) {
				console.error(`Failed to run report ${reportType}:`, error)
			} finally {
				this.reportLoading = false
			}
			return null
		},
	},
}

export const useAnalyticsStore = createObjectStore('KpiWidget', { plugins: [kpiPlugin] })
