/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Shillinq Mobile offline queue (REQ-PMP-003).
 *
 * Receipts and invoice drafts made on the phone are kept in IndexedDB as
 * queue items and sent, in the order they were made, on start and whenever
 * the connection returns:
 *
 *   { clientId, kind: 'receipt' | 'invoice-draft', payload, image?,
 *     state: 'queued' | 'syncing' | 'synced' | 'failed', error?, recordId?,
 *     createdAt }
 *
 * The client id is the item's key and travels with the record, so a sync
 * that is cut off after the server saved is answered with the existing
 * record the second time instead of saving it twice. A receipt uploads its
 * photo first and is created with the photo's path.
 *
 * Built like useInventoryDb.js: plain IndexedDB with no helper dependency.
 * The sync itself takes the store and a transport as arguments, so it runs
 * against an in-memory store where IndexedDB is missing and in tests.
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */

import axios from '@nextcloud/axios'
import { generateRemoteUrl, generateUrl } from '@nextcloud/router'

const DB_NAME = 'shillinq-mobile'
const DB_VERSION = 1
const STORE_QUEUE = 'queue'

export const QUEUE_KINDS = Object.freeze({
	RECEIPT: 'receipt',
	INVOICE_DRAFT: 'invoice-draft',
})

export const QUEUE_STATES = Object.freeze({
	QUEUED: 'queued',
	SYNCING: 'syncing',
	SYNCED: 'synced',
	FAILED: 'failed',
})

/** The folder in the user's files that receives the receipt photos. */
export const RECEIPT_PHOTO_FOLDER = 'Shillinq/Receipts'

/**
 * A new client id: a random UUID.
 *
 * @return {string} The id.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function newClientId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}

	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r = Math.floor(Math.random() * 16)
		return (c === 'x' ? r : ((r % 4) + 8)).toString(16)
	})
}

/**
 * Settle an IndexedDB request.
 *
 * @param {IDBRequest} req The request.
 * @return {Promise<*>} Its result.
 */
function promisify(req) {
	return new Promise((resolve, reject) => {
		req.onsuccess = () => resolve(req.result)
		req.onerror = () => reject(req.error)
	})
}

/**
 * Open the queue in IndexedDB.
 *
 * @param {IDBFactory} [factory] The IndexedDB factory; the browser's by default.
 * @return {Promise<object>} A store with all(), get(clientId) and put(item).
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function openQueueStore(factory = globalThis.indexedDB) {
	return new Promise((resolve, reject) => {
		if (!factory) {
			reject(new Error('IndexedDB unavailable'))
			return
		}

		const req = factory.open(DB_NAME, DB_VERSION)
		req.onupgradeneeded = (event) => {
			const db = event.target.result
			if (!db.objectStoreNames.contains(STORE_QUEUE)) {
				db.createObjectStore(STORE_QUEUE, { keyPath: 'clientId' })
			}
		}
		req.onsuccess = () => resolve(idbStore(req.result))
		req.onerror = () => reject(req.error || new Error('Failed to open IndexedDB'))
	})
}

/**
 * The queue over an open database.
 *
 * @param {IDBDatabase} db The database.
 * @return {object} The store.
 */
function idbStore(db) {
	const store = (mode) => db.transaction(STORE_QUEUE, mode).objectStore(STORE_QUEUE)
	return {
		all: () => promisify(store('readonly').getAll()),
		get: (clientId) => promisify(store('readonly').get(clientId)),
		put: (item) => promisify(store('readwrite').put(item)),
	}
}

/**
 * A queue kept in memory, for a browser without IndexedDB (the app then
 * works online only) and for tests.
 *
 * @param {Array<object>} [items] Items to start with.
 * @return {object} The store.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function memoryQueueStore(items = []) {
	const rows = new Map(items.map((item) => [item.clientId, { ...item }]))
	return {
		all: async () => [...rows.values()].map((item) => ({ ...item })),
		get: async (clientId) => (rows.has(clientId) ? { ...rows.get(clientId) } : undefined),
		put: async (item) => {
			rows.set(item.clientId, { ...item })
		},
	}
}

/**
 * Queue a receipt or an invoice draft.
 *
 * @param {object} store The queue.
 * @param {object} entry The entry.
 * @param {string} entry.kind One of QUEUE_KINDS.
 * @param {object} entry.payload The record's fields.
 * @param {Blob} [entry.image] A receipt's photo.
 * @param {Function} [now] The clock, for ordering.
 * @return {Promise<object>} The queued item.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export async function enqueue(store, { kind, payload, image }, now = () => new Date().toISOString()) {
	if (!Object.values(QUEUE_KINDS).includes(kind)) {
		throw new Error(`Unknown queue kind: ${kind}`)
	}

	const item = {
		clientId: newClientId(),
		kind,
		payload: { ...payload },
		state: QUEUE_STATES.QUEUED,
		createdAt: now(),
		...(image ? { image } : {}),
	}
	await store.put(item)
	return item
}

/**
 * The queued items, oldest first.
 *
 * @param {object} store The queue.
 * @return {Promise<Array<object>>} The items.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export async function listQueue(store) {
	const items = await store.all()
	return items.sort((a, b) => String(a.createdAt).localeCompare(String(b.createdAt)))
}

const running = new WeakSet()

/**
 * Send every queued or failed item, oldest first, one at a time. A receipt
 * with a photo uploads the photo first and is created with its path. Each
 * item is marked synced with the record's id, or failed with the reason;
 * a failure does not stop the items after it. A second call while one runs
 * on the same store does nothing.
 *
 * @param {object} store The queue.
 * @param {object} transport { uploadImage(item): path, send(item): record }.
 * @return {Promise<{synced: number, failed: number}>} The counts of this run.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export async function syncQueue(store, transport) {
	const counts = { synced: 0, failed: 0 }
	if (running.has(store)) {
		return counts
	}

	running.add(store)
	try {
		for (const item of await listQueue(store)) {
			if (item.state !== QUEUE_STATES.QUEUED && item.state !== QUEUE_STATES.FAILED) {
				continue
			}
			const result = await syncItem(store, transport, item)
			counts[result.state === QUEUE_STATES.SYNCED ? 'synced' : 'failed']++
		}
	} finally {
		running.delete(store)
	}

	return counts
}

/**
 * Send one item and record the outcome on it.
 *
 * @param {object} store The queue.
 * @param {object} transport The transport.
 * @param {object} item The item.
 * @return {Promise<object>} The item as stored after the attempt.
 */
async function syncItem(store, transport, item) {
	let current = { ...item, state: QUEUE_STATES.SYNCING }
	delete current.error
	await store.put(current)

	try {
		if (current.kind === QUEUE_KINDS.RECEIPT && current.image && !current.payload.photoUri) {
			const photoUri = await transport.uploadImage(current)
			current = { ...current, payload: { ...current.payload, photoUri } }
			await store.put(current)
		}

		const record = await transport.send(current)
		const done = { ...current, state: QUEUE_STATES.SYNCED, recordId: String(record?.id ?? '') }
		delete done.image
		await store.put(done)
		return done
	} catch (e) {
		const failed = {
			...current,
			state: QUEUE_STATES.FAILED,
			error: e?.response?.data?.message || e?.message || 'Sync failed',
		}
		await store.put(failed)
		return failed
	}
}

/**
 * Whether the browser says it is online.
 *
 * @param {object} [nav] The navigator.
 * @return {boolean} True when online, or when the browser cannot tell.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
 */
export function isOnline(nav = globalThis.navigator) {
	return !nav || nav.onLine !== false
}

/**
 * Call back on each connectivity change.
 *
 * @param {Function} onChange Called with true when online, false when offline.
 * @param {EventTarget} [target] The window.
 * @return {Function} Stops listening.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function watchConnectivity(onChange, target = globalThis.window) {
	if (!target || typeof target.addEventListener !== 'function') {
		return () => {}
	}

	const online = () => onChange(true)
	const offline = () => onChange(false)
	target.addEventListener('online', online)
	target.addEventListener('offline', offline)
	return () => {
		target.removeEventListener('online', online)
		target.removeEventListener('offline', offline)
	}
}

/**
 * The transport to Nextcloud: receipt photos go to the user's files, a
 * receipt is created in the register unless one with its client id exists,
 * and an invoice draft goes through the quick-draft endpoint, which answers
 * a repeated client id with the existing draft.
 *
 * @param {object} options The options.
 * @param {string} options.userId The signed-in user, for the photo path.
 * @param {object} [options.http] The HTTP client; @nextcloud/axios by default.
 * @return {object} The transport.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-a-receipt-is-captured-with-the-camera-req-pmp-002
 */
export function nextcloudTransport({ userId, http = axios }) {
	const receiptsUrl = generateUrl('/apps/openregister/api/objects/shillinq/Receipt')

	return {
		async uploadImage(item) {
			const files = generateRemoteUrl(`dav/files/${encodeURIComponent(userId)}`)
			let folder = ''
			for (const part of RECEIPT_PHOTO_FOLDER.split('/')) {
				folder = folder ? `${folder}/${part}` : part
				await http.request({ method: 'MKCOL', url: `${files}/${folder}` }).catch(() => null)
			}
			const extension = (item.image.type || '').split('/')[1] || 'jpg'
			const path = `${RECEIPT_PHOTO_FOLDER}/${item.clientId}.${extension}`
			await http.put(`${files}/${path}`, item.image, { headers: { 'Content-Type': item.image.type || 'image/jpeg' } })
			return path
		},

		async send(item) {
			if (item.kind === QUEUE_KINDS.INVOICE_DRAFT) {
				const response = await http.post(
					generateUrl('/apps/shillinq/api/ar-invoices/quick-drafts'),
					{ ...item.payload, clientId: item.clientId },
				)
				return response.data
			}

			const existing = await http.get(receiptsUrl, { params: { clientId: item.clientId, _limit: 1 } })
			const found = (existing.data?.results ?? [])[0]
			if (found) {
				return found
			}

			const context = await http.get(generateUrl('/apps/shillinq/api/administrations/context'))
			const response = await http.post(receiptsUrl, {
				...item.payload,
				administrationId: context.data?.activeAdministrationId ?? '',
				clientId: item.clientId,
			})
			return response.data
		},
	}
}
