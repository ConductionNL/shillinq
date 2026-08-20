/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Inventory IndexedDB local cache (T1.1, T1.2, REQ-DATA-001).
 *
 * Wraps the native IndexedDB API for the warehouse PWA's offline cache.
 * No `idb` dependency — keeps the bundle small and avoids tying the spec
 * implementation to a specific helper version. The store schema mirrors
 * REQ-DATA-001 verbatim:
 *
 *   inventoryStock: { sku, location, quantity, lastModified, status }
 *     keyPath: 'skuLocation' (composite: sku + '|' + location)
 *     index:   'sku', 'location', 'lastModified'
 *   inventoryItem:  { sku, name, category, unitPrice, currency }
 *     keyPath: 'sku'
 *   location:       { code, name, warehouse, organization }
 *     keyPath: 'code'
 *   pendingOps:     { id, type, sku, location, toLocation?, oldQty, newQty,
 *                     quantity, timestamp, transactionId, synced }
 *     keyPath: 'id'
 *     index:   'synced', 'transactionId'
 *
 * The helpers exposed here are intentionally small + composable so the
 * Vue store (inventoryMobileScanner.js) and sync scheduler can layer
 * higher-level semantics on top.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

const DB_NAME = 'shillinq-inventory-mobile-scanner'
const DB_VERSION = 1

const STORE_STOCK = 'inventoryStock'
const STORE_ITEM = 'inventoryItem'
const STORE_LOCATION = 'location'
const STORE_PENDING = 'pendingOps'

const NEGATIVE_QUANTITY = 'Quantity must be non-negative'

/**
 * Compose the (sku, location) composite key used by the inventoryStock store.
 *
 * @param {string} sku The SKU value.
 * @param {string} location The location code.
 * @return {string} Stable composite key.
 */
export function composeStockKey(sku, location) {
	return `${sku || ''}|${location || ''}`
}

/**
 * Open (or upgrade) the inventory IndexedDB database.
 *
 * In test / SSR environments `window.indexedDB` is undefined; the helper
 * returns a rejecting promise so callers can no-op cleanly.
 *
 * @return {Promise<IDBDatabase>} The opened database.
 */
export function initDB() {
	return new Promise((resolve, reject) => {
		if (typeof indexedDB === 'undefined' || indexedDB === null) {
			reject(new Error('IndexedDB unavailable'))
			return
		}

		const req = indexedDB.open(DB_NAME, DB_VERSION)

		req.onupgradeneeded = (event) => {
			const db = event.target.result
			if (!db.objectStoreNames.contains(STORE_STOCK)) {
				const stock = db.createObjectStore(STORE_STOCK, {
					keyPath: 'skuLocation',
				})
				stock.createIndex('sku', 'sku', { unique: false })
				stock.createIndex('location', 'location', { unique: false })
				stock.createIndex('lastModified', 'lastModified', { unique: false })
			}
			if (!db.objectStoreNames.contains(STORE_ITEM)) {
				const item = db.createObjectStore(STORE_ITEM, { keyPath: 'sku' })
				item.createIndex('name', 'name', { unique: false })
			}
			if (!db.objectStoreNames.contains(STORE_LOCATION)) {
				db.createObjectStore(STORE_LOCATION, { keyPath: 'code' })
			}
			if (!db.objectStoreNames.contains(STORE_PENDING)) {
				const pending = db.createObjectStore(STORE_PENDING, {
					keyPath: 'id',
				})
				pending.createIndex('synced', 'synced', { unique: false })
				pending.createIndex('transactionId', 'transactionId', {
					unique: true,
				})
			}
		}

		req.onsuccess = () => resolve(req.result)
		req.onerror = () =>
			reject(req.error || new Error('Failed to open IndexedDB'))
	})
}

/**
 * Wrap an IDBRequest in a Promise.
 *
 * @param {IDBRequest} req The request to wrap.
 * @return {Promise<*>} Resolves with `req.result`.
 */
function promisify(req) {
	return new Promise((resolve, reject) => {
		req.onsuccess = () => resolve(req.result)
		req.onerror = () => reject(req.error)
	})
}

/**
 * Open a transaction on the requested store and return both the store and
 * the txn complete-promise so callers can await durability.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} storeName Target store name.
 * @param {string} mode 'readonly' or 'readwrite'.
 * @return {{store: IDBObjectStore, done: Promise<void>}} Transaction handle.
 */
function txn(db, storeName, mode) {
	const t = db.transaction(storeName, mode)
	const store = t.objectStore(storeName)
	const done = new Promise((resolve, reject) => {
		t.oncomplete = () => resolve()
		t.onerror = () => reject(t.error)
		t.onabort = () => reject(t.error || new Error('Transaction aborted'))
	})
	return { store, done }
}

/**
 * Read all rows of a given object store.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} storeName The store to scan.
 * @return {Promise<Array<object>>} The rows.
 */
export async function readAll(db, storeName) {
	const { store } = txn(db, storeName, 'readonly')
	return promisify(store.getAll())
}

/**
 * Upsert an InventoryStock row into the local cache.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {{sku:string, location:string, quantity:number, lastModified:string, status?:string}} row
 *   The row to upsert.
 * @return {Promise<void>} Resolves once the txn commits.
 */
export async function upsertStock(db, row) {
	if (!Number.isFinite(row.quantity) || row.quantity < 0) {
		throw new Error(NEGATIVE_QUANTITY)
	}
	const { store, done } = txn(db, STORE_STOCK, 'readwrite')
	store.put({
		skuLocation: composeStockKey(row.sku, row.location),
		sku: row.sku,
		location: row.location,
		quantity: row.quantity,
		lastModified: row.lastModified,
		status: row.status || 'active',
	})
	await done
}

/**
 * Apply a signed delta to a stock row, creating it if missing. Refuses to
 * drive the stored quantity below zero per REQ-DATA-001.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} sku The SKU.
 * @param {string} location The location code.
 * @param {number} delta Signed delta to apply.
 * @param {string} timestamp ISO timestamp to stamp on lastModified.
 * @return {Promise<number>} The resulting quantity.
 */
export async function applyDelta(db, sku, location, delta, timestamp) {
	const { store, done } = txn(db, STORE_STOCK, 'readwrite')
	const key = composeStockKey(sku, location)
	const existing = await promisify(store.get(key))
	const previous = existing ? Number(existing.quantity) || 0 : 0
	const next = previous + Number(delta)
	if (next < 0) {
		throw new Error(NEGATIVE_QUANTITY)
	}
	store.put({
		skuLocation: key,
		sku,
		location,
		quantity: next,
		lastModified: timestamp,
		status: (existing && existing.status) || 'active',
	})
	await done
	return next
}

/**
 * Read the current stock quantity for a (sku, location) pair.
 * Returns 0 when the row does not exist.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} sku The SKU.
 * @param {string} location The location code.
 * @return {Promise<number>} The stored quantity, or 0.
 */
export async function readStockQuantity(db, sku, location) {
	const { store } = txn(db, STORE_STOCK, 'readonly')
	const row = await promisify(store.get(composeStockKey(sku, location)))
	if (!row) {
		return 0
	}
	return Number(row.quantity) || 0
}

/**
 * Insert a row into the pendingOps store.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {object} op Pending op shape (id, transactionId, type, sku, ...).
 * @return {Promise<void>} Resolves once committed.
 */
export async function insertPendingOp(db, op) {
	const { store, done } = txn(db, STORE_PENDING, 'readwrite')
	store.put(op)
	await done
}

/**
 * Return all unsynced pendingOps in insertion order.
 *
 * @param {IDBDatabase} db The open DB.
 * @return {Promise<Array<object>>} Unsynced ops.
 */
export async function readUnsyncedOps(db) {
	const { store } = txn(db, STORE_PENDING, 'readonly')
	const rows = await promisify(store.getAll())
	return rows.filter((r) => r && r.synced !== true)
}

/**
 * Mark a pendingOp as synced (status flag flip + ackedAt stamp).
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} id The pendingOp id.
 * @param {string} ackedAt Server-supplied ack timestamp.
 * @return {Promise<void>} Resolves once committed.
 */
export async function markOpSynced(db, id, ackedAt) {
	const { store, done } = txn(db, STORE_PENDING, 'readwrite')
	const row = await promisify(store.get(id))
	if (!row) {
		await done
		return
	}
	row.synced = true
	row.ackedAt = ackedAt
	store.put(row)
	await done
}

/**
 * Delete a pendingOp by id. Used after the client has surfaced the ACK
 * to free local storage (REQ-DATA-002 quota management).
 *
 * @param {IDBDatabase} db The open DB.
 * @param {string} id The pendingOp id.
 * @return {Promise<void>} Resolves once committed.
 */
export async function deletePendingOp(db, id) {
	const { store, done } = txn(db, STORE_PENDING, 'readwrite')
	store.delete(id)
	await done
}

/**
 * Replace the inventoryItem cache contents with the supplied list.
 * Used on initial sync to populate the autocomplete catalogue.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {Array<object>} items InventoryItem rows.
 * @return {Promise<void>} Resolves once committed.
 */
export async function replaceItems(db, items) {
	const { store, done } = txn(db, STORE_ITEM, 'readwrite')
	store.clear()
	items.forEach((item) => store.put(item))
	await done
}

/**
 * Replace the location cache contents with the supplied list.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {Array<object>} locations Location rows.
 * @return {Promise<void>} Resolves once committed.
 */
export async function replaceLocations(db, locations) {
	const { store, done } = txn(db, STORE_LOCATION, 'readwrite')
	store.clear()
	locations.forEach((loc) => store.put(loc))
	await done
}

/**
 * Merge a single server-delivered stock row into the local cache using
 * Last-Write-Wins by `lastModified` timestamp (REQ-OFFLINE-003).
 *
 * If the local row is strictly newer (later lastModified), it is kept and
 * the server delta is dropped. Otherwise the server row overwrites.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {{sku:string, location:string, quantity:number, lastModified:string, status?:string}} row
 *   Server-supplied delta.
 * @return {Promise<'kept-local'|'applied-server'>} The decision taken.
 */
export async function mergeServerDelta(db, row) {
	const { store, done } = txn(db, STORE_STOCK, 'readwrite')
	const key = composeStockKey(row.sku, row.location)
	const existing = await promisify(store.get(key))

	let decision = 'applied-server'
	if (
		existing
		&& isStrictlyLater(existing.lastModified, row.lastModified) === true
	) {
		decision = 'kept-local'
	} else {
		store.put({
			skuLocation: key,
			sku: row.sku,
			location: row.location,
			quantity: Number(row.quantity) || 0,
			lastModified: row.lastModified,
			status: row.status || 'active',
		})
	}

	await done
	return decision
}

/**
 * Compare two ISO 8601 timestamps. Returns true when `a` is strictly later
 * than `b`. Defensive against missing / unparseable strings (returns false).
 *
 * @param {string} a The first timestamp.
 * @param {string} b The second timestamp.
 * @return {boolean} True when `a` > `b`.
 */
export function isStrictlyLater(a, b) {
	if (!a || !b) {
		return false
	}
	const ea = Date.parse(a)
	const eb = Date.parse(b)
	if (Number.isNaN(ea) || Number.isNaN(eb)) {
		return false
	}
	return ea > eb
}

/**
 * Auto-clear inventoryCount-style pendingOps older than the supplied window.
 * Used by REQ-DATA-002 quota management to free space when usage is high.
 *
 * @param {IDBDatabase} db The open DB.
 * @param {number} olderThanMs Maximum age in milliseconds for retention.
 * @return {Promise<number>} The number of rows cleared.
 */
export async function purgeOldPendingOps(db, olderThanMs) {
	const cutoff = Date.now() - olderThanMs
	const { store, done } = txn(db, STORE_PENDING, 'readwrite')
	const rows = await promisify(store.getAll())
	let cleared = 0
	rows.forEach((row) => {
		if (row && row.synced === true && row.timestamp) {
			const ts = Date.parse(row.timestamp)
			if (!Number.isNaN(ts) && ts < cutoff) {
				store.delete(row.id)
				cleared += 1
			}
		}
	})
	await done
	return cleared
}

export const STORES = Object.freeze({
	STOCK: STORE_STOCK,
	ITEM: STORE_ITEM,
	LOCATION: STORE_LOCATION,
	PENDING: STORE_PENDING,
})
