/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for how the budget BBV mapping index loads and moves: the
 * fiscal scope (which presets the year filter, and never blocks the page),
 * the mapping collection with the store's error, paging, the debounced
 * search, row navigation, the live subscription and its release, and the
 * cell formatters. Methods and computed run against a fake instance; only
 * HTTP, the store and the router are replaced.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetBBVMappingIndex from '../../src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue'

vi.mock('../../src/store/modules/budgetBBVMappingStore.js', () => ({ useBudgetBBVMappingStore: () => ({}) }))

const t = (_app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) : text)

/**
 * A fake index instance over a store double.
 *
 * @param {object} store The store double.
 * @return {object} The instance.
 */
function index(store = {}) {
	const vm = {
		store,
		t,
		$router: { push: vi.fn() },
		watchers: [],
		$watch(getter, callback) {
			const entry = { getter, callback, stopped: false }
			this.watchers.push(entry)
			return () => {
				entry.stopped = true
			}
		},
	}
	Object.assign(vm, BudgetBBVMappingIndex.data.call(vm))
	for (const [name, fn] of Object.entries(BudgetBBVMappingIndex.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, getter] of Object.entries(BudgetBBVMappingIndex.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	return vm
}

describe('BudgetBBVMappingIndex scope', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the fiscal scope and presets the year filter from it', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { scope: { administrationId: 'adm-1', fiscalYear: 2026, startDate: '2026-01-01', endDate: '2026-12-31' } } })
		const vm = index()

		await vm.loadScope()

		expect(get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/budget-mappings')
		expect(vm.scope).toEqual({ administrationId: 'adm-1', fiscalYear: 2026, startDate: '2026-01-01', endDate: '2026-12-31' })
		expect(vm.fiscalYearFilter).toBe(2026)
		expect(vm.fyLabel).toBe('FY 2026')
	})

	it('leaves a year filter the user already chose', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { scope: { fiscalYear: 2026 } } })
		const vm = index()
		vm.fiscalYearFilter = '2024'

		await vm.loadScope()

		expect(vm.fiscalYearFilter).toBe('2024')
		expect(vm.scope.administrationId).toBeNull()
	})

	it('falls back to an empty scope when the read fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('403'))
		const vm = index()
		vm.scope = { administrationId: 'x', fiscalYear: 2020, startDate: 'a', endDate: 'b' }

		await vm.loadScope()

		expect(vm.scope).toEqual({ administrationId: null, fiscalYear: null, startDate: null, endDate: null })
		expect(vm.fyLabel).toBe('')
	})
})

describe('BudgetBBVMappingIndex collection', () => {
	it('loads up to 200 mappings and the store\'s pagination', async () => {
		const store = {
			fetchCollection: vi.fn().mockResolvedValue({ results: [{ id: 1 }] }),
			pagination: { budgetBBVMapping: { page: 1, limit: 200 } },
		}
		const vm = index(store)

		await vm.loadMappings()

		expect(store.fetchCollection).toHaveBeenCalledWith('budgetBBVMapping', { _limit: 200 })
		expect(vm.objects).toEqual([{ id: 1 }])
		expect(vm.pagination).toEqual({ page: 1, limit: 200 })
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
	})

	it('accepts a bare array, and treats anything else as no rows', async () => {
		const store = { fetchCollection: vi.fn().mockResolvedValueOnce([{ id: 2 }]).mockResolvedValueOnce({ total: 3 }) }
		const vm = index(store)

		await vm.loadMappings()
		expect(vm.objects).toEqual([{ id: 2 }])
		expect(vm.pagination).toBeNull()

		await vm.loadMappings()
		expect(vm.objects).toEqual([])
	})

	it('shows the store\'s error, or its own sentence when the error has no message', async () => {
		const store = { fetchCollection: vi.fn().mockResolvedValue([]), errors: { budgetBBVMapping: { message: 'Schema missing' } } }
		const vm = index(store)

		await vm.loadMappings()
		expect(vm.error).toBe('Schema missing')

		store.errors.budgetBBVMapping = {}
		await vm.loadMappings()
		expect(vm.error).toBe('Failed to load budget mappings')
	})

	it('empties the list and shows the server\'s reason when the fetch throws', async () => {
		const store = { fetchCollection: vi.fn().mockRejectedValue({ response: { data: { error: 'No access' } } }) }
		const vm = index(store)
		vm.objects = [{ id: 9 }]

		await vm.loadMappings()

		expect(vm.objects).toEqual([])
		expect(vm.error).toBe('No access')
		expect(vm.loading).toBe(false)
	})

	it('pages with the current page size, and keeps the rows when a page fails', async () => {
		const store = {
			fetchCollection: vi.fn().mockResolvedValueOnce({ results: [{ id: 3 }] }).mockRejectedValueOnce(new Error('x')),
			pagination: { budgetBBVMapping: { page: 2, limit: 50 } },
		}
		const vm = index(store)
		vm.pagination = { limit: 50 }

		await vm.onPageChange(2)
		expect(store.fetchCollection).toHaveBeenLastCalledWith('budgetBBVMapping', { _page: 2, _limit: 50 })
		expect(vm.objects).toEqual([{ id: 3 }])
		expect(vm.pagination).toEqual({ page: 2, limit: 50 })

		await vm.onPageChange(3)
		expect(vm.objects).toEqual([{ id: 3 }])
		expect(vm.error).toBe('Failed to load budget mappings')
		expect(vm.loading).toBe(false)
	})

	it('pages 200 at a time before any pagination is known', async () => {
		const store = { fetchCollection: vi.fn().mockResolvedValue([]) }
		const vm = index(store)

		await vm.onPageChange(1)

		expect(store.fetchCollection).toHaveBeenCalledWith('budgetBBVMapping', { _page: 1, _limit: 200 })
	})
})

describe('BudgetBBVMappingIndex search and navigation', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('applies the search term once typing pauses for 250 ms', () => {
		const vm = index()
		vm.searchTerm = '40'
		vm.onSearchInput()
		vi.advanceTimersByTime(200)
		vm.searchTerm = '400'
		vm.onSearchInput()
		vi.advanceTimersByTime(200)
		expect(vm.searchTermApplied).toBe('')

		vi.advanceTimersByTime(50)
		expect(vm.searchTermApplied).toBe('400')
		expect(vm.searchDebounce).toBeNull()
	})

	it('opens a new mapping, a clicked row, and ignores a row without an id', () => {
		const vm = index()

		vm.onAdd()
		vm.onRowClick({ id: 42 })
		vm.onRowClick({})
		vm.onRowClick(null)

		expect(vm.$router.push.mock.calls).toEqual([
			[{ name: 'BudgetBBVMappingDetail', params: { id: 'new' } }],
			[{ name: 'BudgetBBVMappingDetail', params: { id: '42' } }],
		])
	})
})

describe('BudgetBBVMappingIndex live updates', () => {
	it('subscribes once and mirrors the store\'s fresh collection into the table', async () => {
		const store = {
			subscribe: vi.fn().mockResolvedValue('h-1'),
			unsubscribe: vi.fn(),
			getCollection: vi.fn().mockReturnValue([{ id: 5 }]),
			pagination: { budgetBBVMapping: { page: 1 } },
		}
		const vm = index(store)

		await vm.syncLiveSubscription()
		await vm.syncLiveSubscription()

		expect(store.subscribe).toHaveBeenCalledTimes(1)
		expect(store.subscribe).toHaveBeenCalledWith('budgetBBVMapping')
		expect(vm.liveHandle).toBe('h-1')
		const [watcher] = vm.watchers
		expect(watcher.getter()).toEqual([{ id: 5 }])
		watcher.callback([{ id: 5 }])
		expect(vm.objects).toEqual([{ id: 5 }])
		expect(vm.pagination).toEqual({ page: 1 })
		watcher.callback(null)
		expect(vm.objects).toEqual([{ id: 5 }])

		vm.releaseLiveSubscription()
		expect(store.unsubscribe).toHaveBeenCalledWith('h-1')
		expect(watcher.stopped).toBe(true)
		expect(vm.liveHandle).toBeNull()
	})

	it('drops a subscription that resolves after the page was left', async () => {
		let resolve
		const store = { subscribe: vi.fn(() => new Promise((r) => { resolve = r })), unsubscribe: vi.fn() }
		const vm = index(store)

		const pending = vm.syncLiveSubscription()
		vm.releaseLiveSubscription()
		resolve('h-late')
		await pending

		expect(store.unsubscribe).toHaveBeenCalledWith('h-late')
		expect(vm.liveHandle).toBeNull()
		expect(vm.watchers).toEqual([])
	})

	it('keeps working without live updates when the subscription fails or the store has none', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const store = { subscribe: vi.fn().mockRejectedValue(new Error('no socket')) }
		const vm = index(store)

		await vm.syncLiveSubscription()

		expect(vm.liveHandle).toBeNull()
		expect(vm.livePending).toBe(false)
		expect(warn).toHaveBeenCalledWith('[BudgetBBVMappingIndex] live subscription failed:', 'no socket')

		const plain = index({})
		await plain.syncLiveSubscription()
		plain.releaseLiveSubscription()
		expect(plain.livePending).toBe(false)
		warn.mockRestore()
	})
})

describe('BudgetBBVMappingIndex cells', () => {
	it('parses an allocation bucket, and refuses a malformed one', () => {
		const vm = index()

		expect(vm.parseAllocationBucket('0-50')).toEqual({ min: 0, max: 50 })
		expect(vm.parseAllocationBucket('')).toBeNull()
		expect(vm.parseAllocationBucket('10-20-30')).toBeNull()
		expect(vm.parseAllocationBucket('a-b')).toBeNull()
	})

	it('keys and labels the lifecycle state', () => {
		const vm = index()

		expect(vm.statusKey({ lifecycleState: 'Active' })).toBe('active')
		expect(vm.statusKey({ lifecycleState: 'on hold!' })).toBe('on-hold-')
		expect(vm.statusKey({})).toBe('unknown')
		expect(vm.statusLabel({ lifecycleState: 'EXPIRED' })).toBe('Expired')
		expect(vm.statusLabel({ lifecycleState: 'pending' })).toBe('pending')
		expect(vm.statusLabel({})).toBe('—')
	})

	it('formats a percentage and a date, with a dash for nothing', () => {
		const vm = index()

		expect(vm.formatPercentage(40)).toBe('40 %')
		expect(vm.formatPercentage('75.5')).toBe('75.5 %')
		expect(vm.formatPercentage(0)).toBe('0 %')
		expect(vm.formatPercentage(null)).toBe('—')
		expect(vm.formatPercentage('')).toBe('—')
		expect(vm.formatPercentage('n/a')).toBe('—')
		expect(vm.formatDate('')).toBe('—')
		expect(vm.formatDate('2026-03-01')).toBe(new Date('2026-03-01').toLocaleDateString())
	})

	it('offers next year and the five years before this one, newest first', () => {
		const now = new Date().getFullYear()
		expect(index().fiscalYearOptions).toEqual([now + 1, now, now - 1, now - 2, now - 3, now - 4, now - 5])
	})

	it('names the six columns with sortable labels', () => {
		const vm = index()
		expect(Object.keys(vm.columnOverrides)).toEqual(vm.visibleColumns)
		expect(vm.columnOverrides.allocationPercentage).toEqual({ label: 'Allocation %', sortable: true })
	})
})
