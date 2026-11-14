/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * initDB's upgrade: which object stores and indexes it creates, in order,
 * and that it leaves existing stores alone.
 */

import { afterEach, describe, expect, it } from 'vitest'
import { initDB } from '../../src/composables/useInventoryDb.js'

/**
 * A fake database that records what the upgrade creates.
 *
 * @param {string[]} existing The stores that already exist.
 * @return {{db: object, calls: Array<Array>}} The database and its calls.
 */
function fakeDb(existing) {
	const calls = []
	const db = {
		objectStoreNames: { contains: (name) => existing.includes(name) },
		createObjectStore(name, options) {
			calls.push(['store', name, options])
			return {
				createIndex(index, keyPath, indexOptions) {
					calls.push(['index', name, index, keyPath, indexOptions])
				},
			}
		},
	}
	return { db, calls }
}

/**
 * Open the database against a fake IndexedDB and run its upgrade.
 *
 * @param {string[]} existing The stores that already exist.
 * @return {Promise<{calls: Array<Array>, opened: Array}>} The upgrade calls and the open arguments.
 */
async function upgrade(existing) {
	const { db, calls } = fakeDb(existing)
	const opened = []
	globalThis.indexedDB = {
		open(name, version) {
			opened.push([name, version])
			const req = { result: db }
			queueMicrotask(() => {
				req.onupgradeneeded({ target: { result: db } })
				req.onsuccess()
			})
			return req
		},
	}
	await initDB()
	return { calls, opened }
}

describe('initDB upgrade', () => {
	afterEach(() => {
		delete globalThis.indexedDB
	})

	it('creates every store and index on a fresh database', async () => {
		const { calls, opened } = await upgrade([])
		expect(opened).toEqual([['shillinq-inventory-mobile-scanner', 1]])
		expect(calls).toEqual([
			['store', 'inventoryStock', { keyPath: 'skuLocation' }],
			['index', 'inventoryStock', 'sku', 'sku', { unique: false }],
			['index', 'inventoryStock', 'location', 'location', { unique: false }],
			['index', 'inventoryStock', 'lastModified', 'lastModified', { unique: false }],
			['store', 'inventoryItem', { keyPath: 'sku' }],
			['index', 'inventoryItem', 'name', 'name', { unique: false }],
			['store', 'location', { keyPath: 'code' }],
			['store', 'pendingOps', { keyPath: 'id' }],
			['index', 'pendingOps', 'synced', 'synced', { unique: false }],
			['index', 'pendingOps', 'transactionId', 'transactionId', { unique: true }],
		])
	})

	it('leaves existing stores alone', async () => {
		const { calls } = await upgrade(['inventoryStock', 'location'])
		expect(calls).toEqual([
			['store', 'inventoryItem', { keyPath: 'sku' }],
			['index', 'inventoryItem', 'name', 'name', { unique: false }],
			['store', 'pendingOps', { keyPath: 'id' }],
			['index', 'pendingOps', 'synced', 'synced', { unique: false }],
			['index', 'pendingOps', 'transactionId', 'transactionId', { unique: true }],
		])
		expect((await upgrade(['inventoryStock', 'inventoryItem', 'location', 'pendingOps'])).calls).toEqual([])
	})

	it('rejects without IndexedDB', async () => {
		await expect(initDB()).rejects.toThrow('IndexedDB unavailable')
	})
})
