/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The Shillinq Mobile offline queue (REQ-PMP-003): queueing, ordered sync,
 * image-first receipts, failures that do not stop the rest, a resend that
 * finds the record its client id made, the IndexedDB store, connectivity
 * events, and the transport's calls.
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	enqueue,
	isOnline,
	listQueue,
	memoryQueueStore,
	newClientId,
	nextcloudTransport,
	openQueueStore,
	QUEUE_KINDS,
	QUEUE_STATES,
	syncQueue,
	watchConnectivity,
} from '../../src/composables/useOfflineQueue.js'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

/**
 * A clock handing out increasing ISO moments.
 *
 * @return {Function} The clock.
 */
function clock() {
	let minute = 0
	return () => new Date(Date.UTC(2026, 10, 13, 9, minute++)).toISOString()
}

describe('queueing', () => {
	it('keeps an item queued under a fresh client id, with its photo', async () => {
		const store = memoryQueueStore()
		const image = new Blob(['jpeg'], { type: 'image/jpeg' })
		const item = await enqueue(store, { kind: QUEUE_KINDS.RECEIPT, payload: { amount: 24.2 }, image }, clock())

		expect(item.clientId).toMatch(UUID)
		expect(item.state).toBe(QUEUE_STATES.QUEUED)
		expect(await store.get(item.clientId)).toEqual(item)
	})

	it('refuses an unknown kind', async () => {
		await expect(enqueue(memoryQueueStore(), { kind: 'payment', payload: {} })).rejects.toThrow('Unknown queue kind: payment')
	})

	it('lists the items oldest first', async () => {
		const store = memoryQueueStore([
			{ clientId: 'b', createdAt: '2026-11-13T09:02:00.000Z' },
			{ clientId: 'a', createdAt: '2026-11-13T09:01:00.000Z' },
		])
		expect((await listQueue(store)).map((item) => item.clientId)).toEqual(['a', 'b'])
	})

	it('falls back to a random v4 id without crypto.randomUUID', () => {
		vi.stubGlobal('crypto', {})
		const ids = new Set([newClientId(), newClientId()])
		expect(ids.size).toBe(2)
		for (const id of ids) {
			expect(id).toMatch(UUID)
		}
	})
})

describe('syncing', () => {
	it('sends queued and failed items in order, the receipt photo first, and keeps the record ids', async () => {
		const now = clock()
		const store = memoryQueueStore()
		const receipt = await enqueue(store, { kind: QUEUE_KINDS.RECEIPT, payload: { amount: 24.2 }, image: new Blob(['x'], { type: 'image/jpeg' }) }, now)
		const draft = await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: { customerId: 'c1' } }, now)
		await store.put({ clientId: 'old', kind: QUEUE_KINDS.RECEIPT, payload: {}, state: QUEUE_STATES.SYNCED, createdAt: '2026-01-01' })
		const calls = []
		const transport = {
			uploadImage: vi.fn(async (item) => {
				calls.push(['upload', item.clientId])
				return `Shillinq/Receipts/${item.clientId}.jpeg`
			}),
			send: vi.fn(async (item) => {
				calls.push(['send', item.clientId, item.payload])
				return { id: `rec-${calls.length}` }
			}),
		}

		expect(await syncQueue(store, transport)).toEqual({ synced: 2, failed: 0 })

		expect(calls).toEqual([
			['upload', receipt.clientId],
			['send', receipt.clientId, { amount: 24.2, photoUri: `Shillinq/Receipts/${receipt.clientId}.jpeg` }],
			['send', draft.clientId, { customerId: 'c1' }],
		])
		const stored = await store.get(receipt.clientId)
		expect([stored.state, stored.recordId, stored.image]).toEqual([QUEUE_STATES.SYNCED, 'rec-2', undefined])
		expect((await store.get(draft.clientId)).recordId).toBe('rec-3')
	})

	it('marks a failed item with the reason and still sends the next one', async () => {
		const now = clock()
		const store = memoryQueueStore()
		const first = await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: { n: 1 } }, now)
		const second = await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: { n: 2 } }, now)
		const transport = {
			uploadImage: vi.fn(),
			send: vi.fn()
				.mockRejectedValueOnce({ response: { data: { message: 'This customer is not part of your administration.' } } })
				.mockResolvedValueOnce({ id: 'inv-2' }),
		}

		expect(await syncQueue(store, transport)).toEqual({ synced: 1, failed: 1 })
		expect(await store.get(first.clientId)).toMatchObject({ state: QUEUE_STATES.FAILED, error: 'This customer is not part of your administration.' })
		expect(await store.get(second.clientId)).toMatchObject({ state: QUEUE_STATES.SYNCED, recordId: 'inv-2' })

		transport.send.mockResolvedValueOnce({ id: 'inv-1' })
		expect(await syncQueue(store, transport)).toEqual({ synced: 1, failed: 0 })
		const retried = await store.get(first.clientId)
		expect([retried.state, retried.error, retried.recordId]).toEqual([QUEUE_STATES.SYNCED, undefined, 'inv-1'])
	})

	it('keeps an uploaded photo path when the send fails, and does not upload it again', async () => {
		const store = memoryQueueStore()
		const receipt = await enqueue(store, { kind: QUEUE_KINDS.RECEIPT, payload: {}, image: new Blob(['x']) })
		const transport = {
			uploadImage: vi.fn(async () => 'Shillinq/Receipts/r.jpg'),
			send: vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ id: 'rec-1' }),
		}

		await syncQueue(store, transport)
		expect(await store.get(receipt.clientId)).toMatchObject({ state: QUEUE_STATES.FAILED, error: 'offline', payload: { photoUri: 'Shillinq/Receipts/r.jpg' } })
		await syncQueue(store, transport)
		expect(transport.uploadImage).toHaveBeenCalledTimes(1)
		expect(transport.send).toHaveBeenLastCalledWith(expect.objectContaining({ payload: { photoUri: 'Shillinq/Receipts/r.jpg' } }))
	})

	it('does not start a second run on the same store while one runs', async () => {
		const store = memoryQueueStore()
		await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {} })
		let finish
		const transport = { uploadImage: vi.fn(), send: vi.fn(() => new Promise((resolve) => { finish = resolve })) }

		const first = syncQueue(store, transport)
		await vi.waitFor(() => expect(transport.send).toHaveBeenCalled())
		expect(await syncQueue(store, transport)).toEqual({ synced: 0, failed: 0 })
		finish({ id: 'inv-1' })
		expect(await first).toEqual({ synced: 1, failed: 0 })
	})

	it('falls back to a generic reason for a failure without a message', async () => {
		const store = memoryQueueStore()
		const item = await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {} })
		await syncQueue(store, { uploadImage: vi.fn(), send: vi.fn().mockRejectedValue({}) })
		expect((await store.get(item.clientId)).error).toBe('Sync failed')
	})
})

describe('connectivity', () => {
	it('reads the navigator, counting an unknown state as online', () => {
		expect(isOnline({ onLine: false })).toBe(false)
		expect(isOnline({ onLine: true })).toBe(true)
		expect(isOnline({})).toBe(true)
		expect(isOnline(undefined)).toBe(true)
	})

	it('reports online and offline events until stopped', () => {
		const target = new EventTarget()
		const seen = []
		const stop = watchConnectivity((online) => seen.push(online), target)

		target.dispatchEvent(new Event('offline'))
		target.dispatchEvent(new Event('online'))
		stop()
		target.dispatchEvent(new Event('online'))

		expect(seen).toEqual([false, true])
		expect(watchConnectivity(() => {}, null)()).toBeUndefined()
	})
})

describe('the IndexedDB store', () => {
	/**
	 * A minimal IndexedDB factory over a Map, answering requests on the next tick.
	 *
	 * @return {object} The factory and the created stores.
	 */
	function fakeIndexedDb() {
		const rows = new Map()
		const created = []
		const request = (result) => {
			const req = { result }
			setTimeout(() => req.onsuccess && req.onsuccess())
			return req
		}
		const objectStore = {
			getAll: () => request([...rows.values()]),
			get: (key) => request(rows.get(key)),
			put: (item) => {
				rows.set(item.clientId, item)
				return request(item.clientId)
			},
		}
		const db = {
			objectStoreNames: { contains: (name) => created.includes(name) },
			createObjectStore: (name, options) => created.push(name, options.keyPath),
			transaction: () => ({ objectStore: () => objectStore }),
		}
		return {
			created,
			open: () => {
				const req = { result: db }
				setTimeout(() => {
					req.onupgradeneeded({ target: { result: db } })
					req.onsuccess()
				})
				return req
			},
		}
	}

	it('creates the queue keyed by client id and stores items in it', async () => {
		const factory = fakeIndexedDb()
		const store = await openQueueStore(factory)
		expect(factory.created).toEqual(['queue', 'clientId'])

		const item = await enqueue(store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: { customerId: 'c1' } })
		expect(await store.get(item.clientId)).toEqual(item)
		expect(await store.all()).toEqual([item])
	})

	it('is refused without IndexedDB', async () => {
		await expect(openQueueStore(null)).rejects.toThrow('IndexedDB unavailable')
	})
})

describe('the Nextcloud transport', () => {
	it('sends an invoice draft to the quick-draft endpoint with its client id', async () => {
		const http = { post: vi.fn(async () => ({ data: { id: 'inv-1' } })) }
		const record = await nextcloudTransport({ userId: 'ann', http }).send({ kind: QUEUE_KINDS.INVOICE_DRAFT, clientId: 'c-1', payload: { customerId: 'k1' } })

		expect(record).toEqual({ id: 'inv-1' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/quick-drafts', { customerId: 'k1', clientId: 'c-1' })
	})

	it('answers a receipt whose client id is already stored with that receipt', async () => {
		const http = { get: vi.fn(async () => ({ data: { results: [{ id: 'rec-1' }] } })), post: vi.fn() }
		const record = await nextcloudTransport({ userId: 'ann', http }).send({ kind: QUEUE_KINDS.RECEIPT, clientId: 'c-1', payload: { amount: 1 } })

		expect(record).toEqual({ id: 'rec-1' })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Receipt', { params: { clientId: 'c-1', _limit: 1 } })
		expect(http.post).not.toHaveBeenCalled()
	})

	it('creates a new receipt in the active administration', async () => {
		const http = {
			get: vi.fn()
				.mockResolvedValueOnce({ data: { results: [] } })
				.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-1' } }),
			post: vi.fn(async () => ({ data: { id: 'rec-2' } })),
		}
		const record = await nextcloudTransport({ userId: 'ann', http }).send({ kind: QUEUE_KINDS.RECEIPT, clientId: 'c-2', payload: { amount: 24.2 } })

		expect(record).toEqual({ id: 'rec-2' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Receipt', { amount: 24.2, administrationId: 'adm-1', clientId: 'c-2' })
	})

	it('puts the photo in the receipts folder, creating the folders first', async () => {
		const http = { request: vi.fn().mockResolvedValueOnce({}).mockRejectedValueOnce({ response: { status: 405 } }), put: vi.fn(async () => ({})) }
		const image = new Blob(['x'], { type: 'image/png' })
		const path = await nextcloudTransport({ userId: 'ann b', http }).uploadImage({ clientId: 'c-3', image })

		expect(path).toBe('Shillinq/Receipts/c-3.png')
		expect(http.request.mock.calls.map(([call]) => call.url)).toEqual([
			'http://localhost/remote.php/dav/files/ann%20b/Shillinq',
			'http://localhost/remote.php/dav/files/ann%20b/Shillinq/Receipts',
		])
		expect(http.put).toHaveBeenCalledWith('http://localhost/remote.php/dav/files/ann%20b/Shillinq/Receipts/c-3.png', image, { headers: { 'Content-Type': 'image/png' } })
	})
})
