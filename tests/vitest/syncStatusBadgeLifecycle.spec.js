/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * SyncStatusBadge's lifecycle and tap: the clock ticks every 30 s so a badge
 * turns stale on its own, the ticker stops when the badge goes away, and a
 * tap syncs only while online. The options run on a plain instance (the suite
 * has no DOM environment), as in syncStatusBadge.golden.spec.js. Also the
 * flat (Begroot) branch of the chart's accessible cell text, which the series
 * spec leaves out.
 *
 * Only the scanner store is mocked; the clock is faked.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => null,
}))

const { default: SyncStatusBadge } = await import('../../src/components/inventory/SyncStatusBadge.vue')
const { cellText } = await import('../../src/components/budget-charts/budgetChartSeries.js')

const NOW = Date.parse('2026-06-01T12:00:00Z')

/**
 * A plain badge instance over a store, with its computed values as getters.
 *
 * @param {object} fields Store fields over an idle, online, synced default.
 * @return {object} The instance.
 */
function badge(fields = {}) {
	const store = {
		syncState: 'idle',
		pendingCount: 0,
		lastSyncedAt: '2026-06-01T11:59:30Z',
		isOnline: true,
		triggerSyncNow: vi.fn().mockResolvedValue(undefined),
		...fields,
	}
	const vm = { ...SyncStatusBadge.data(), store, t: (app, text) => text }
	for (const [name, fn] of Object.entries(SyncStatusBadge.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const name of ['isStale', 'badgeClass']) {
		Object.defineProperty(vm, name, { get: () => SyncStatusBadge.computed[name].call(vm) })
	}
	return vm
}

describe('SyncStatusBadge lifecycle', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(NOW)
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('turns stale on its own as the 30-second ticker advances the clock', () => {
		const vm = badge()
		SyncStatusBadge.mounted.call(vm)

		expect(vm.badgeClass).toBe('sync-status-badge__button--green')

		vi.advanceTimersByTime(29_999)
		expect(vm.now).toBe(NOW)

		vi.advanceTimersByTime(90_001)
		expect(vm.now).toBe(NOW + 120_000)
		expect(vm.badgeClass).toBe('sync-status-badge__button--yellow')

		SyncStatusBadge.beforeUnmount.call(vm)
	})

	it('stops the ticker when the badge goes away, once', () => {
		const vm = badge()
		SyncStatusBadge.mounted.call(vm)
		expect(vi.getTimerCount()).toBe(1)

		SyncStatusBadge.beforeUnmount.call(vm)
		expect(vm.ticker).toBeNull()
		expect(vi.getTimerCount()).toBe(0)

		SyncStatusBadge.beforeUnmount.call(vm)
		expect(vm.ticker).toBeNull()
	})

	it('syncs on a tap while online', async () => {
		const vm = badge()

		await vm.handleClick()

		expect(vm.store.triggerSyncNow).toHaveBeenCalledTimes(1)
	})

	it('does nothing on a tap while offline', async () => {
		const vm = badge({ isOnline: false, syncState: 'offline' })

		await vm.handleClick()

		expect(vm.store.triggerSyncNow).not.toHaveBeenCalled()
		expect(vm.badgeClass).toBe('sync-status-badge__button--red')
	})
})

describe('cellText for a flat (Begroot) series', () => {
	const t = (app, key) => key

	it('formats the euro value as cents, rounded', () => {
		const text = cellText(t, null, 12.345)

		expect(text).toBe(cellText(t, { kind: 'actual', amount: 1235 }, null))
		expect(text).toContain('12,35')
	})

	it('shows an em dash for a missing value, never "€0"', () => {
		expect(cellText(t, null, null)).toBe('—')
		expect(cellText(t, null, undefined)).toBe('—')
	})

	it('shows a zero budget as a real amount', () => {
		expect(cellText(t, null, 0)).toContain('0,00')
	})
})
