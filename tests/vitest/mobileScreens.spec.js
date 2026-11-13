/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The three Shillinq Mobile screens and what they share: the receipt and
 * invoice payloads, when a form can be saved, the remembered customer list,
 * the queue that falls back to memory, Issue offered only online on a synced
 * draft, and each screen saving into the queue and sending it when online.
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const errors = []
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => errors.push(m), showSuccess: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'ann' }) }))

const { memoryQueueStore, QUEUE_KINDS, QUEUE_STATES, enqueue } = await import('../../src/composables/useOfflineQueue.js')
const app = await import('../../src/views/mobile/mobileApp.js')
const { default: MobileHome } = await import('../../src/views/mobile/MobileHome.vue')
const { default: MobileReceipt } = await import('../../src/views/mobile/MobileReceipt.vue')
const { default: MobileInvoice } = await import('../../src/views/mobile/MobileInvoice.vue')

/**
 * A bare screen: its data, bound methods and computed getters.
 *
 * @param {object} component The screen.
 * @param {object} [state] Data overrides.
 * @return {object} The instance.
 */
function screen(component, state = {}) {
	const vm = { ...component.data.call({}), ...state, $router: { push: vi.fn() } }
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(component.computed ?? {})) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

/**
 * Point the shared queue at an in-memory store and a recording transport.
 *
 * @return {object} The store and transport.
 */
async function memoryQueue() {
	app.resetMobileQueue()
	const transport = { uploadImage: vi.fn(async () => 'Shillinq/Receipts/p.jpeg'), send: vi.fn(async () => ({ id: 'rec-1' })) }
	const queue = await app.mobileQueue({ open: async () => memoryQueueStore(), user: () => ({ uid: 'ann' }) })
	queue.transport = transport
	return queue
}

beforeEach(() => {
	errors.length = 0
})

afterEach(() => {
	app.resetMobileQueue()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('the shared helpers', () => {
	it('builds a euro receipt with a number the phone can make offline', () => {
		expect(app.buildReceiptPayload({ amount: '24.199', receiptDate: '2026-11-13', category: 'meals', description: '  Lunch ' }, 'AB12')).toEqual({
			receiptNumber: 'REC-M-20261113-AB12',
			amount: 24.2,
			currency: 'EUR',
			amountInBaseCurrency: 24.2,
			receiptDate: '2026-11-13',
			category: 'meals',
			description: 'Lunch',
		})
		expect(app.buildReceiptPayload({ amount: 0, receiptDate: '2026-11-13', category: 'other', description: ' ' }, 'X')).not.toHaveProperty('description')
	})

	it('saves a receipt only with a photo, a non-negative amount, a date and a known category', () => {
		const photo = new Blob(['x'])
		const form = { amount: '24.20', receiptDate: '2026-11-13', category: 'travel' }
		expect(app.receiptComplete(form, photo)).toBe(true)
		expect(app.receiptComplete(form, null)).toBe(false)
		expect(app.receiptComplete({ ...form, amount: '' }, photo)).toBe(false)
		expect(app.receiptComplete({ ...form, amount: '-1' }, photo)).toBe(false)
		expect(app.receiptComplete({ ...form, receiptDate: '13-11-2026' }, photo)).toBe(false)
		expect(app.receiptComplete({ ...form, category: 'gifts' }, photo)).toBe(false)
	})

	it('drafts an invoice as the desktop quick draft does, and needs a customer, date and priced line', () => {
		const form = { customerId: 'k1', invoiceDate: '2026-11-13', reference: 'Bruiloft', lines: [{ description: 'Kapsel', quantity: 1, unitPrice: 75, vatRate: 21 }, { description: '', quantity: 1, unitPrice: 0, vatRate: 21 }] }
		expect(app.buildInvoicePayload(form)).toEqual({
			customerId: 'k1',
			invoiceDate: '2026-11-13',
			dueDate: '',
			reference: 'Bruiloft',
			glAccount: '',
			lines: [{ description: 'Kapsel', quantity: 1, unitPrice: 75, vatRate: 21 }],
		})
		expect(app.invoiceComplete(form)).toBe(true)
		expect(app.invoiceComplete({ ...form, customerId: '' })).toBe(false)
		expect(app.invoiceComplete({ ...form, lines: [{ description: 'Gratis', quantity: 1, unitPrice: 0, vatRate: 0 }] })).toBe(false)
	})

	it('offers Issue only online, on a synced draft with a record and no number yet', () => {
		const draft = { kind: QUEUE_KINDS.INVOICE_DRAFT, state: QUEUE_STATES.SYNCED, recordId: 'inv-1' }
		expect(app.canIssue(draft, true)).toBe(true)
		expect(app.canIssue(draft, false)).toBe(false)
		expect(app.canIssue({ ...draft, state: QUEUE_STATES.QUEUED }, true)).toBe(false)
		expect(app.canIssue({ ...draft, kind: QUEUE_KINDS.RECEIPT }, true)).toBe(false)
		expect(app.canIssue({ ...draft, invoiceNumber: 'F-1' }, true)).toBe(false)
		expect(app.canIssue({ ...draft, recordId: '' }, true)).toBe(false)
	})

	it('issues through the issue transition and reads back the number', async () => {
		const http = { post: vi.fn(async () => ({})), get: vi.fn(async () => ({ data: { invoiceNumber: '2026-0042', lifecycleState: 'issued' } })) }
		expect(await app.issueInvoice('inv 1', http)).toEqual({ invoiceNumber: '2026-0042', lifecycleState: 'issued' })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/inv%201/transition', { action: 'issue', data: {} })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/ARInvoice/inv%201')
	})

	it('remembers the customers online and uses them offline', async () => {
		const saved = new Map()
		const storage = { getItem: (k) => saved.get(k) ?? null, setItem: (k, v) => saved.set(k, v) }
		const http = { get: vi.fn(async () => ({ data: { results: [{ customerId: 'k1', legalName: 'Bruid BV' }, { id: 'k2', tradeName: 'Kruidvat' }, {}] } })) }

		const online = await app.loadCustomers({ http, storage, online: true })
		expect(online).toEqual([{ value: 'k1', label: 'Bruid BV' }, { value: 'k2', label: 'Kruidvat' }])
		expect(await app.loadCustomers({ http, storage, online: false })).toEqual(online)
		expect(http.get).toHaveBeenCalledTimes(1)

		http.get.mockRejectedValueOnce(new Error('offline'))
		expect(await app.loadCustomers({ http, storage, online: true })).toEqual(online)
		expect(await app.loadCustomers({ http, storage: { getItem: () => '{broken' }, online: false })).toEqual([])
	})

	it('keeps the queue in memory when IndexedDB cannot be opened, and opens it once per load', async () => {
		const open = vi.fn(async () => { throw new Error('IndexedDB unavailable') })
		const queue = await app.mobileQueue({ open, user: () => null })
		expect(queue.persistent).toBe(false)
		expect(await app.mobileQueue({ open })).toBe(queue)
		expect(open).toHaveBeenCalledTimes(1)
	})

	it('writes today in the phone\'s own calendar', () => {
		expect(app.today(new Date(2026, 0, 5, 23, 30))).toBe('2026-01-05')
	})
})

describe('MobileHome', () => {
	it('names receipts, drafts and issued invoices, and their states', () => {
		const home = screen(MobileHome)
		expect(home.itemTitle({ kind: QUEUE_KINDS.RECEIPT, payload: { receiptDate: '2026-11-13', amount: 24.2 } })).toBe('Receipt 2026-11-13, EUR 24.20')
		expect(home.itemTitle({ kind: QUEUE_KINDS.INVOICE_DRAFT, payload: { invoiceDate: '2026-11-13' } })).toBe('Invoice draft 2026-11-13')
		expect(home.itemTitle({ kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {}, invoiceNumber: '2026-0042' })).toBe('Invoice 2026-0042')
		expect([QUEUE_STATES.QUEUED, QUEUE_STATES.SYNCING, QUEUE_STATES.SYNCED, QUEUE_STATES.FAILED, 'odd'].map((state) => home.stateLabel({ state })))
			.toEqual(['Queued', 'Sending', 'Synced', 'Not sent', 'odd'])
		expect(home.stateLabel({ state: QUEUE_STATES.SYNCED, invoiceNumber: 'F-1' })).toBe('Issued')
	})

	it('sends the queue when the connection returns and lists it newest first', async () => {
		const queue = await memoryQueue()
		const first = await enqueue(queue.store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {} }, () => '2026-11-13T09:00:00Z')
		const second = await enqueue(queue.store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {} }, () => '2026-11-13T09:01:00Z')
		const home = screen(MobileHome, { queue })

		await home.onConnectivity(false)
		expect([home.online, queue.transport.send.mock.calls.length]).toEqual([false, 0])
		expect(home.issuable({ kind: QUEUE_KINDS.INVOICE_DRAFT, state: QUEUE_STATES.SYNCED, recordId: 'x' })).toBe(false)

		await home.onConnectivity(true)
		expect(queue.transport.send).toHaveBeenCalledTimes(2)
		expect(home.items.map((item) => [item.clientId, item.state])).toEqual([[second.clientId, QUEUE_STATES.SYNCED], [first.clientId, QUEUE_STATES.SYNCED]])
	})

	it('issues a synced draft and keeps its number, or says why not', async () => {
		const queue = await memoryQueue()
		const item = { clientId: 'c1', kind: QUEUE_KINDS.INVOICE_DRAFT, payload: {}, state: QUEUE_STATES.SYNCED, recordId: 'inv-1', createdAt: '1' }
		await queue.store.put(item)
		const home = screen(MobileHome, { queue, online: true })
		const http = await import('@nextcloud/axios')
		vi.spyOn(http.default, 'post').mockResolvedValueOnce({})
		vi.spyOn(http.default, 'get').mockResolvedValueOnce({ data: { invoiceNumber: '2026-0042', lifecycleState: 'issued' } })

		await home.issue(item)
		expect(await queue.store.get('c1')).toMatchObject({ invoiceNumber: '2026-0042', lifecycleState: 'issued' })
		expect(home.issuing).toBeNull()

		http.default.post.mockRejectedValueOnce({ response: { data: { message: 'The period is closed.' } } })
		await home.issue(item)
		expect(errors).toEqual(['The period is closed.'])
	})
})

describe('MobileReceipt', () => {
	it('keeps the photo the camera took, with a preview', () => {
		vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:1'), revokeObjectURL: vi.fn() })
		const receipt = screen(MobileReceipt)
		const photo = new Blob(['x'], { type: 'image/jpeg' })
		receipt.onPhoto({ target: { files: [photo] } })
		expect([receipt.photo, receipt.preview]).toEqual([photo, 'blob:1'])
		receipt.onPhoto({ target: { files: [] } })
		expect([receipt.photo, receipt.preview]).toEqual([null, ''])
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1')
		expect(receipt.categoryOptions.map((option) => option.value)).toEqual(['travel', 'meals', 'supplies', 'accommodation', 'other'])
	})

	it('queues the receipt with its photo, sends it when online, and goes home', async () => {
		const queue = await memoryQueue()
		vi.stubGlobal('navigator', { onLine: true })
		const receipt = screen(MobileReceipt, { photo: new Blob(['x'], { type: 'image/jpeg' }), form: { amount: '24.20', receiptDate: '2026-11-13', category: 'travel', description: '' } })

		await receipt.save()
		const [item] = await queue.store.all()
		expect(item).toMatchObject({ kind: QUEUE_KINDS.RECEIPT, state: QUEUE_STATES.SYNCED, recordId: 'rec-1', payload: { amount: 24.2, photoUri: 'Shillinq/Receipts/p.jpeg' } })
		expect(receipt.$router.push).toHaveBeenCalledWith('/mobile')
	})

	it('only queues while offline, and saves nothing while incomplete', async () => {
		const queue = await memoryQueue()
		vi.stubGlobal('navigator', { onLine: false })
		const incomplete = screen(MobileReceipt, { photo: null })
		await incomplete.save()
		expect(await queue.store.all()).toEqual([])

		const receipt = screen(MobileReceipt, { photo: new Blob(['x']), form: { amount: '5', receiptDate: '2026-11-13', category: 'meals', description: '' } })
		await receipt.save()
		expect((await queue.store.all()).map((item) => item.state)).toEqual([QUEUE_STATES.QUEUED])
		expect(queue.transport.send).not.toHaveBeenCalled()
	})
})

describe('MobileInvoice', () => {
	it('adds and removes lines, keeping at least one', () => {
		const invoice = screen(MobileInvoice)
		invoice.addLine()
		expect(invoice.form.lines).toHaveLength(2)
		invoice.removeLine(0)
		invoice.removeLine(0)
		expect(invoice.form.lines).toHaveLength(1)
	})

	it('queues the draft as a quick-draft request and sends it when online', async () => {
		const queue = await memoryQueue()
		vi.stubGlobal('navigator', { onLine: true })
		const invoice = screen(MobileInvoice, { form: { customerId: 'k1', invoiceDate: '2026-11-13', reference: '', lines: [{ description: 'Kapsel', quantity: 1, unitPrice: 75, vatRate: 21 }] } })

		await invoice.save()
		const [item] = await queue.store.all()
		expect(item).toMatchObject({ kind: QUEUE_KINDS.INVOICE_DRAFT, state: QUEUE_STATES.SYNCED, payload: { customerId: 'k1', lines: [{ unitPrice: 75 }] } })
		expect(invoice.$router.push).toHaveBeenCalledWith('/mobile')

		await screen(MobileInvoice).save()
		expect(await queue.store.all()).toHaveLength(1)
	})
})
