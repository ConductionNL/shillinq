// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared plumbing for the financial dashboard chart widgets: slot
// props from CnDashboardPage, the fetch-once data layer, the
// trailing-12-months window and the per-widget Refresh-bus hookup.

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { ref } from 'vue'
import {
	lastMonths,
	monthLabel,
	monthlyFinancialSeries,
	monthsInRange,
} from './financialSeries.js'
import { useFinancialData } from './useFinancialData.js'

export const TRAILING_MONTHS = 12

export default {
	props: {
		/** Layout item from CnDashboardPage's widget slot scope. */
		item: { type: Object, default: null },
		/** Widget definition from CnDashboardPage's widget slot scope. */
		widget: { type: Object, default: null },
	},

	inject: {
		// Provided by CnDashboardPage when dateRange.enabled is true.
		// May arrive as a raw ref or already unwrapped — both shapes are
		// handled in the `months` computed below.
		cnDashboardDateRange: { default: () => ref(null) },
	},

	// ⚠️ This used to be a `setup()` on the MIXIN returning
	// `{ loading, financialData, load, reload }`.
	//
	// Vue 3 does NOT merge `setup` from a mixin — `setupComponent` reads
	// `Component.setup` off the component itself and mixin options are merged
	// by `resolveMergedOptions`, which has no `setup` strategy. So the whole
	// block was silently dropped: `this.load` became undefined and the
	// `mounted()` hook below died with `TypeError: this.load is not a
	// function`. No build error, no lint error — the widget just never
	// fetched.
	//
	// `useFinancialData()` is module-scoped (its refs and the in-flight
	// promise are module singletons, and it neither injects nor registers a
	// lifecycle hook), so it can be called from a computed/method as often as
	// we like and always returns the same state. That makes the mixin pure
	// Options API, which mixins do support.

	computed: {
		/** @return {boolean} Whether the shared fetch is in flight. */
		loading() {
			return useFinancialData().loading.value
		},
		/** @return {object|null} The shared dashboard payload. */
		financialData() {
			return useFinancialData().data.value
		},
		/** @return {string[]} Month keys for the current date range, ascending. */
		months() {
			// Unwrap ref (Vue 2.7 options-API inject may give either shape).
			const injected = this.cnDashboardDateRange
			const range =
				injected && typeof injected === 'object' && 'value' in injected
					? injected.value
					: injected
			if (range && range.from && range.to) {
				const ms = monthsInRange(range.from, range.to)
				if (ms.length > 0) return ms
			}
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
			return monthlyFinancialSeries({
				accounts,
				transactions,
				lines,
				months: this.months,
			})
		},
	},

	methods: {
		/**
		 * Trigger the shared fetch-once load (no-op if already in flight).
		 *
		 * @return {Promise<object>} The shared in-flight promise.
		 */
		load() {
			return useFinancialData().load()
		},
		/**
		 * Drop the shared cache and refetch into the same refs.
		 *
		 * @return {Promise<object>} The new in-flight promise.
		 */
		reload() {
			return useFinancialData().reload()
		},
	},

	mounted() {
		this.load()
		this._onRefresh = (payload) => {
			if (payload?.widgetId === this.item?.widgetId) this.reload()
		}
		subscribe('cn:widget:refresh', this._onRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:widget:refresh', this._onRefresh)
	},
}
