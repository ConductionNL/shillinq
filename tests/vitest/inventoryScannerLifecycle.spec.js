/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The scanner store's lifecycle: bootstrap opens the database, caches the
 * locations and starts the sync scheduler once; teardown stops it; the
 * manual sync, the conflict dismissal and the purge act on what is there.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const io = vi.hoisted(() => ({
	db: { name: 'inventory' },
	locations: [{ id: 'A1' }, { id: 'B2' }],
	locationsFail: null,
	pending: [],
	calls: [],
	scheduler: null,
	schedulerOptions: null,
}))

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	initDB: async () => {
		io.calls.push('initDB')
		return io.db
	},
	replaceLocations: async (db, locations) => io.calls.push(['replaceLocations', db, locations]),
	readUnsyncedOps: async () => io.pending,
	purgeOldPendingOps: async (db, windowMs) => {
		io.calls.push(['purge', db, windowMs])
		return 3
	},
	applyDelta: async () => 0,
	insertPendingOp: async () => undefined,
	readStockQuantity: async () => 0,
}))
vi.mock('../../src/composables/useInventorySync.js', () => ({
	fetchLocations: async () => {
		if (io.locationsFail) {
			throw io.locationsFail
		}
		return io.locations
	},
	createSyncScheduler: (db, options) => {
		io.schedulerOptions = options
		io.scheduler = {
			start: vi.fn(),
			stop: vi.fn(),
			triggerNow: vi.fn(async () => undefined),
		}
		io.calls.push(['createSyncScheduler', db])
		return io.scheduler
	},
	newTransactionId: () => 'tx-1',
}))

const { useInventoryMobileScannerStore } = await import('../../src/store/modules/inventoryMobileScanner.js')

describe('inventory scanner lifecycle', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		io.calls = []
		io.pending = []
		io.locationsFail = null
		io.scheduler = null
	})

	it('opens the database, caches the locations and starts the scheduler', async () => {
		io.pending = [{ id: 'op-1' }, { id: 'op-2' }]
		const store = useInventoryMobileScannerStore()

		await store.bootstrap()

		expect(io.calls).toEqual(['initDB', ['replaceLocations', io.db, io.locations], ['createSyncScheduler', io.db]])
		expect(store.db).toEqual(io.db)
		expect(store.locations).toEqual(io.locations)
		expect(io.scheduler.start).toHaveBeenCalledTimes(1)
		expect(store.ready).toBe(true)
		expect(store.initializing).toBe(false)
		expect(store.pendingCount).toBe(2)
	})

	it('wires the scheduler to the store', async () => {
		const store = useInventoryMobileScannerStore()
		await store.bootstrap()

		io.schedulerOptions.setLastSyncedAt('2026-11-03T10:00:00Z')
		expect(io.schedulerOptions.getLastSyncedAt()).toBe('2026-11-03T10:00:00Z')
		await io.schedulerOptions.onStatus({ state: 'synced' })
		expect(store.syncState).toBe('synced')
	})

	it('bootstraps only once', async () => {
		const store = useInventoryMobileScannerStore()
		await Promise.all([store.bootstrap(), store.bootstrap()])
		await store.bootstrap()

		expect(io.calls.filter((c) => c === 'initDB')).toHaveLength(1)
	})

	it('keeps going without locations and records why', async () => {
		io.locationsFail = new Error('offline')
		const store = useInventoryMobileScannerStore()

		await store.bootstrap()

		expect(store.lastError).toBe('offline')
		expect(store.locations).toEqual([])
		expect(store.ready).toBe(true)

		setActivePinia(createPinia())
		io.locationsFail = 'plain failure'
		const other = useInventoryMobileScannerStore()
		await other.bootstrap()
		expect(other.lastError).toBe('plain failure')
	})

	it('stops the scheduler on teardown, and tolerates having none', async () => {
		const store = useInventoryMobileScannerStore()
		store.teardown()
		expect(store.ready).toBe(false)

		await store.bootstrap()
		const scheduler = io.scheduler
		store.teardown()

		expect(scheduler.stop).toHaveBeenCalledTimes(1)
		expect(store.scheduler).toBe(null)
		expect(store.ready).toBe(false)
	})

	it('syncs on demand only with a scheduler', async () => {
		const store = useInventoryMobileScannerStore()
		await expect(store.triggerSyncNow()).resolves.toBeUndefined()

		await store.bootstrap()
		await store.triggerSyncNow()

		expect(io.scheduler.triggerNow).toHaveBeenCalledTimes(1)
	})

	it('purges old operations only with a database', async () => {
		const store = useInventoryMobileScannerStore()
		expect(await store.purgeOld()).toBe(0)

		await store.bootstrap()
		expect(await store.purgeOld()).toBe(3)
		expect(io.calls.at(-1)).toEqual(['purge', io.db, 7 * 24 * 60 * 60 * 1000])
	})

	it('dismisses one conflict by its index', () => {
		const store = useInventoryMobileScannerStore()
		store.conflicts = [{ applied: 1 }, { applied: 2 }, { applied: 3 }]

		store.dismissConflict(1)

		expect(store.conflicts).toEqual([{ applied: 1 }, { applied: 3 }])
	})

	it('reads as online unless the browser says it is offline', () => {
		const store = useInventoryMobileScannerStore()
		const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
		try {
			Object.defineProperty(globalThis, 'navigator', { value: { onLine: false }, configurable: true })
			expect(store.isOnline).toBe(false)
			Object.defineProperty(globalThis, 'navigator', { value: { onLine: true }, configurable: true })
			setActivePinia(createPinia())
			expect(useInventoryMobileScannerStore().isOnline).toBe(true)
		} finally {
			if (original) {
				Object.defineProperty(globalThis, 'navigator', original)
			}
		}
	})
})
