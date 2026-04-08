// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Analytics store for KpiWidget and report state.
 *
 * @see openspec/changes/general/tasks.md#task-3.1
 */
export const useAnalyticsStore = defineStore('analytics', {
	state: () => ({
		widgets: [],
		reports: [],
		kpiData: {},
		loading: false,
		reportLoading: false,
	}),

	getters: {
		getWidgets: (state) => state.widgets,
		getReports: (state) => state.reports,
		getKpiData: (state) => state.kpiData,
	},

	actions: {
		async fetchWidgets() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.widgets = await objectStore.fetchObjects('KpiWidget')
				return this.widgets
			} catch (error) {
				console.error('Failed to fetch KPI widgets:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		async fetchKpiValue(metricKey) {
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/analytics/kpi/${metricKey}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.kpiData[metricKey] = data
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

		async fetchReports() {
			this.reportLoading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.reports = await objectStore.fetchObjects('AnalyticsReport')
				return this.reports
			} catch (error) {
				console.error('Failed to fetch analytics reports:', error)
			} finally {
				this.reportLoading = false
			}
			return []
		},

		async runReport(reportType, parameters = {}) {
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
			}
			return null
		},
	},
})
