/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The offline inventory store over an in-memory IndexedDB double: stock
 * upserts and deltas that refuse a negative quantity, the pending-operation
 * queue, catalogue replacement, last-write-wins merging and the purge of old
 * synced operations.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	applyDelta,
	composeStockKey,
	deletePendingOp,
	initDB,
	insertPendingOp,
	isStrictlyLater,
	markOpSynced,
	mergeServerDelta,
	purgeOldPendingOps,
	readAll,
	readStockQuantity,
	readUnsyncedOps,
	replaceItems,
	replaceLocations,
	STORES,
	upsertStock,
} from '../../src/composables/useInventoryDb.js'

/**
 * An in-memory database with IndexedDB's request / transaction callbacks.
 *
 * Each request answers on a later macrotask; a transaction completes once no
 * request of it is outstanding after that tick, so work queued from an
 * awaited request still belongs to it.
 *
 * @param {object} keyPaths Store name to key path.
 * @return {object} The database, with `data` to inspect.
 */
function memoryDb(keyPaths) {
	const data = Object.fromEntries(Object.keys(keyPaths).map((name) => [name, new Map()]))
	const db = {
		data,
		transaction(name) {
			const map = data[name]
			let pending = 0
			let finished = false
			const t = {}
			const settle = () => setTimeout(() => {
				if (pending === 0 && !finished) {
					finished = true
					t.oncomplete && t.oncomplete()
				}
			}, 0)
			const request = (fn) => {
				pending += 1
				const req = {}
				setTimeout(() => {
					req.result = fn()
					pending -= 1
					req.onsuccess && req.onsuccess()
					settle()
				}, 0)
				return req
			}
			t.objectStore = () => ({
				get: (key) => request(() => (map.has(key) ? structuredClone(map.get(key)) : undefined)),
				getAll: () => request(() => [...map.values()].map((v) => structuredClone(v))),
				put: (value) => request(() => map.set(value[keyPaths[name]], structuredClone(value))),
				delete: (key) => request(() => map.delete(key)),
				clear: () => request(() => map.clear()),
			})
			settle()
			return t
		},
	}
	return db
}

function db () {
  return memoryDb({
	[STORES.STOCK]: 'skuLocation',
	[STORES.ITEM]: 'sku',
	[STORES.LOCATION]: 'code',
	[STORES.PENDING]: 'id',
})
}

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('composeStockKey', () => {
	it('joins sku and location, treating missing parts as empty', () => {
		expect(composeStockKey('SKU-1', 'A1')).toBe('SKU-1|A1')
		expect(composeStockKey(null, undefined)).toBe('|')
	})
})

describe('initDB', () => {
	it('rejects with the open error', async () => {
		vi.stubGlobal('indexedDB', {
			open() {
				const req = { error: new Error('quota') }
				queueMicrotask(() => req.onerror())
				return req
			},
		})
		await expect(initDB()).rejects.toThrow('quota')
	})

	it('rejects with a generic error when the open fails silently', async () => {
		vi.stubGlobal('indexedDB', {
			open() {
				const req = { error: null }
				queueMicrotask(() => req.onerror())
				return req
			},
		})
		await expect(initDB()).rejects.toThrow('Failed to open IndexedDB')
	})
})

describe('stock', () => {
	it('upserts a row under its composite key, active by default', async () => {
		const store = db()
		await upsertStock(store, { sku: 'S', location: 'L', quantity: 3, lastModified: 't1' })

		expect(await readAll(store, STORES.STOCK)).toEqual([
			{ skuLocation: 'S|L', sku: 'S', location: 'L', quantity: 3, lastModified: 't1', status: 'active' },
		])
		expect(await readStockQuantity(store, 'S', 'L')).toBe(3)
		expect(await readStockQuantity(store, 'S', 'other')).toBe(0)
	})

	it('refuses a negative or non-numeric quantity', async () => {
		await expect(upsertStock(db(), { sku: 'S', location: 'L', quantity: -1 })).rejects.toThrow('Quantity must be non-negative')
		await expect(upsertStock(db(), { sku: 'S', location: 'L', quantity: NaN })).rejects.toThrow('Quantity must be non-negative')
	})

	it('applies a delta on top of the stored quantity and keeps the status', async () => {
		const store = db()
		await upsertStock(store, { sku: 'S', location: 'L', quantity: 5, lastModified: 't1', status: 'blocked' })

		expect(await applyDelta(store, 'S', 'L', -2, 't2')).toBe(3)
		expect(store.data[STORES.STOCK].get('S|L')).toEqual(
			{ skuLocation: 'S|L', sku: 'S', location: 'L', quantity: 3, lastModified: 't2', status: 'blocked' },
		)
	})

	it('starts a new row from zero', async () => {
		const store = db()
		expect(await applyDelta(store, 'S', 'L', '4', 't1')).toBe(4)
		expect(store.data[STORES.STOCK].get('S|L').status).toBe('active')
	})

	it('refuses a delta that would go below zero', async () => {
		const store = db()
		await expect(applyDelta(store, 'S', 'L', -1, 't1')).rejects.toThrow('Quantity must be non-negative')
		expect(store.data[STORES.STOCK].size).toBe(0)
	})
})

describe('pending operations', () => {
	it('queues operations and reads back only the unsynced ones', async () => {
		const store = db()
		await insertPendingOp(store, { id: 'a', synced: false })
		await insertPendingOp(store, { id: 'b', synced: true })
		await insertPendingOp(store, { id: 'c' })

		expect((await readUnsyncedOps(store)).map((op) => op.id)).toEqual(['a', 'c'])
	})

	it('marks an operation synced with its ack time', async () => {
		const store = db()
		await insertPendingOp(store, { id: 'a', synced: false })

		await markOpSynced(store, 'a', 't9')

		expect(store.data[STORES.PENDING].get('a')).toEqual({ id: 'a', synced: true, ackedAt: 't9' })
	})

	it('ignores marking an unknown operation', async () => {
		const store = db()
		await markOpSynced(store, 'missing', 't9')
		expect(store.data[STORES.PENDING].size).toBe(0)
	})

	it('deletes an operation', async () => {
		const store = db()
		await insertPendingOp(store, { id: 'a' })
		await deletePendingOp(store, 'a')
		expect(store.data[STORES.PENDING].size).toBe(0)
	})

	it('purges synced operations older than the cutoff only', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-11-10T12:00:00Z'))
		const store = db()
		await insertPendingOp(store, { id: 'old', synced: true, timestamp: '2026-11-01T00:00:00Z' })
		await insertPendingOp(store, { id: 'fresh', synced: true, timestamp: '2026-11-10T11:00:00Z' })
		await insertPendingOp(store, { id: 'unsynced', synced: false, timestamp: '2026-11-01T00:00:00Z' })
		await insertPendingOp(store, { id: 'undated', synced: true })
		await insertPendingOp(store, { id: 'garbled', synced: true, timestamp: 'yesterday' })

		expect(await purgeOldPendingOps(store, 24 * 3600 * 1000)).toBe(1)
		expect([...store.data[STORES.PENDING].keys()]).toEqual(['fresh', 'unsynced', 'undated', 'garbled'])
	})
})

describe('catalogues', () => {
	it('replaces the items', async () => {
		const store = db()
		await replaceItems(store, [{ sku: 'old' }])
		await replaceItems(store, [{ sku: 'A', name: 'Bolt' }, { sku: 'B', name: 'Nut' }])
		expect(await readAll(store, STORES.ITEM)).toEqual([{ sku: 'A', name: 'Bolt' }, { sku: 'B', name: 'Nut' }])
	})

	it('replaces the locations', async () => {
		const store = db()
		await replaceLocations(store, [{ code: 'X' }])
		await replaceLocations(store, [{ code: 'A1', name: 'Shelf' }])
		expect(await readAll(store, STORES.LOCATION)).toEqual([{ code: 'A1', name: 'Shelf' }])
	})
})

describe('mergeServerDelta', () => {
	it('applies a server row when there is no local one', async () => {
		const store = db()
		expect(await mergeServerDelta(store, { sku: 'S', location: 'L', quantity: '7', lastModified: '2026-11-01T00:00:00Z' })).toBe('applied-server')
		expect(store.data[STORES.STOCK].get('S|L')).toEqual(
			{ skuLocation: 'S|L', sku: 'S', location: 'L', quantity: 7, lastModified: '2026-11-01T00:00:00Z', status: 'active' },
		)
	})

	it('keeps a strictly later local row', async () => {
		const store = db()
		await upsertStock(store, { sku: 'S', location: 'L', quantity: 2, lastModified: '2026-11-02T00:00:00Z' })

		expect(await mergeServerDelta(store, { sku: 'S', location: 'L', quantity: 9, lastModified: '2026-11-01T00:00:00Z' })).toBe('kept-local')
		expect(store.data[STORES.STOCK].get('S|L').quantity).toBe(2)
	})

	it('applies the server row on a tie, with a non-numeric quantity as zero', async () => {
		const store = db()
		await upsertStock(store, { sku: 'S', location: 'L', quantity: 2, lastModified: '2026-11-01T00:00:00Z' })

		expect(await mergeServerDelta(store, { sku: 'S', location: 'L', quantity: 'n/a', lastModified: '2026-11-01T00:00:00Z', status: 'archived' })).toBe('applied-server')
		expect(store.data[STORES.STOCK].get('S|L')).toMatchObject({ quantity: 0, status: 'archived' })
	})
})

describe('isStrictlyLater', () => {
	it('compares parsed timestamps and is false for missing or garbled ones', () => {
		expect(isStrictlyLater('2026-11-02', '2026-11-01')).toBe(true)
		expect(isStrictlyLater('2026-11-01', '2026-11-01')).toBe(false)
		expect(isStrictlyLater(null, '2026-11-01')).toBe(false)
		expect(isStrictlyLater('soon', '2026-11-01')).toBe(false)
	})
})
