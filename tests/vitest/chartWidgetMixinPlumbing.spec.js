/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The financial chart widgets' shared mixin: the months it charts (the
 * dashboard's date range, raw or as a ref, else the trailing twelve), the
 * shared fetch-once data, and the per-widget Refresh subscription.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import mixin, { TRAILING_MONTHS } from '../../src/components/dashboard/financial/chartWidgetMixin.js'
import { monthLabel } from '../../src/components/dashboard/financial/financialSeries.js'

const bus = vi.hoisted(() => ({ subscribe: vi.fn(), unsubscribe: vi.fn(), emit: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => bus)
const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

/**
 * A stand-in widget instance using the mixin.
 *
 * @param {object} [fields] Fields to set.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = { item: null, cnDashboardDateRange: ref(null), ...fields }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(mixin.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self), configurable: true })
	}
	return self
}

beforeEach(() => {
	bus.subscribe.mockReset()
	bus.unsubscribe.mockReset()
	http.get.mockReset()
	http.get.mockResolvedValue({ data: { results: [] } })
})

describe('the months a widget charts', () => {
	it('charts the dashboard range when it is a ref', () => {
		const self = instance({ cnDashboardDateRange: ref({ from: '2026-01-15', to: '2026-03-02' }) })
		expect(self.months).toEqual(['2026-01', '2026-02', '2026-03'])
		expect(self.monthLabels).toEqual(['2026-01', '2026-02', '2026-03'].map(monthLabel))
	})

	it('charts the dashboard range when it arrives unwrapped', () => {
		expect(instance({ cnDashboardDateRange: { from: '2025-12-01', to: '2026-01-31' } }).months).toEqual(['2025-12', '2026-01'])
	})

	it('falls back to the trailing twelve months without a full range', () => {
		for (const range of [ref(null), ref({ from: '2026-01-01' }), null]) {
			const months = instance({ cnDashboardDateRange: range }).months
			expect(months).toHaveLength(TRAILING_MONTHS)
			expect(months.every((m) => /^\d{4}-\d{2}$/.test(m))).toBe(true)
		}
		expect(TRAILING_MONTHS).toBe(12)
	})

	it('injects an empty range by default', () => {
		expect(mixin.inject.cnDashboardDateRange.default().value).toBeNull()
	})
})

describe('the shared data', () => {
	it('loads every financial schema once and builds the ledger series', async () => {
		const self = instance({ cnDashboardDateRange: ref({ from: '2026-01-01', to: '2026-02-28' }) })
		await self.reload()
		const first = http.get.mock.calls.length
		expect(first).toBe(9)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/GLLine', { params: { _limit: 2000 } })
		await self.load()
		expect(http.get).toHaveBeenCalledTimes(first)
		expect(self.loading).toBe(false)
		expect(self.financialData).toMatchObject({ accounts: [], transactions: [], lines: [] })
		expect(self.glSeries).not.toBeNull()
	})

	it('has no ledger series before the data is there', () => {
		const self = instance()
		Object.defineProperty(self, 'financialData', { get: () => null })
		expect(self.glSeries).toBeNull()
	})
})

describe('the refresh subscription', () => {
	it('loads on mount and reloads only for its own widget', () => {
		const self = instance({ item: { widgetId: 'w-1' } })
		self.load = vi.fn()
		self.reload = vi.fn()
		mixin.mounted.call(self)
		expect(self.load).toHaveBeenCalledTimes(1)
		expect(bus.subscribe).toHaveBeenCalledWith('cn:widget:refresh', self._onRefresh)

		self._onRefresh({ widgetId: 'w-2' })
		expect(self.reload).not.toHaveBeenCalled()
		self._onRefresh({ widgetId: 'w-1' })
		expect(self.reload).toHaveBeenCalledTimes(1)
	})

	it('unsubscribes the same handler on unmount', () => {
		const self = instance()
		self.load = vi.fn()
		mixin.mounted.call(self)
		mixin.beforeUnmount.call(self)
		expect(bus.unsubscribe).toHaveBeenCalledWith('cn:widget:refresh', self._onRefresh)
	})
})
