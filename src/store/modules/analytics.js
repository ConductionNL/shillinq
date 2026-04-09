// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @see openspec/changes/general/tasks.md#task-3.1
 */
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useAnalyticsStore = defineStore('analytics', {
	state: () => ({
		kpiWidgets: [],
		kpiData: {},
		reports: [],
		loading: false,
	}),

	actions: {
		/**
		 * Fetch all KPI widgets from OpenRegister.
		 *
		 * @see openspec/changes/general/tasks.md#task-3.1
		 */
		async fetchWidgets() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('KpiWidget', 'KpiWidget', 'shillinq')
				this.kpiWidgets = await objectStore.fetchObjects('KpiWidget')
			} catch (error) {
				console.error('Failed to fetch KPI widgets:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch KPI value for a given metric key.
		 *
		 * @param {string} metricKey The metric key
		 * @see openspec/changes/general/tasks.md#task-3.1
		 */
		async fetchKpiValue(metricKey) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/analytics/kpi/${metricKey}`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.kpiData = { ...this.kpiData, [metricKey]: data }
				}
			} catch (error) {
				console.error(`Failed to fetch KPI value for ${metricKey}:`, error)
			}
		},

		/**
		 * Fetch all analytics reports.
		 *
		 * @see openspec/changes/general/tasks.md#task-3.1
		 */
		async fetchReports() {
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('AnalyticsReport', 'AnalyticsReport', 'shillinq')
				this.reports = await objectStore.fetchObjects('AnalyticsReport')
			} catch (error) {
				console.error('Failed to fetch reports:', error)
			}
		},

		/**
		 * Run a report by type.
		 *
		 * @param {string} reportType The report type
		 * @param {object} parameters Optional parameters
		 * @see openspec/changes/general/tasks.md#task-3.1
		 */
		async runReport(reportType, parameters = {}) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/analytics/reports/${reportType}/run`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(parameters),
				})
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
