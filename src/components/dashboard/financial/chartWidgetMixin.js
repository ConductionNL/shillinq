// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared plumbing for the financial dashboard chart widgets: slot
// props from CnDashboardPage, the fetch-once data layer, the
// trailing-12-months window and the per-widget Refresh-bus hookup.

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useFinancialData } from './useFinancialData.js'
import { lastMonths, monthLabel, monthlyFinancialSeries } from './financialSeries.js'

export const TRAILING_MONTHS = 12

export default {
	props: {
		/** Layout item from CnDashboardPage's widget slot scope. */
		item: { type: Object, default: null },
		/** Widget definition from CnDashboardPage's widget slot scope. */
		widget: { type: Object, default: null },
	},

	setup() {
		const { loading, data, load, reload } = useFinancialData()
		return { loading, financialData: data, load, reload }
	},

	computed: {
		/** @return {string[]} Trailing month keys, ascending. */
		months() {
			return lastMonths(TRAILING_MONTHS)
		},
		/** @return {string[]} Localised x-axis labels. */
		monthLabels() {
			return this.months.map(monthLabel)
		},
		/** @return {object|null} Shared GL-derived monthly series. */
		glSeries() {
			if (!this.financialData) return null
			const { accounts, transactions, lines } = this.financialData
			return monthlyFinancialSeries({ accounts, transactions, lines, months: this.months })
		},
	},

	mounted() {
		this.load()
		this._onRefresh = (payload) => {
			if (payload?.widgetId === this.item?.widgetId) this.reload()
		}
		subscribe('cn:widget:refresh', this._onRefresh)
	},

	beforeDestroy() {
		unsubscribe('cn:widget:refresh', this._onRefresh)
	},
}
