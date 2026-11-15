/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * GoodsReceiptNoteForm: the administrations and open purchase orders it
 * offers, the lines it loads per chosen order, when it may be saved, and the
 * save itself (photos, note, received lines, then the detail page). The
 * template is rendered on the server renderer to check what each state shows.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import Form from '../../src/components/goods-receipt-note/GoodsReceiptNoteForm.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const GRN = '/index.php/apps/shillinq/api/goods-receipt-notes'

/**
 * A stand-in instance with data, computed and methods.
 *
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = {
		t: (_app, text) => text,
		$router: { push: vi.fn() },
		...Form.data(),
		...fields,
	}
	for (const [name, fn] of Object.entries(Form.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Form.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

/**
 * A stub that renders its label and default slot.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return {
		name,
		props: ['label', 'inputLabel', 'disabled'],
		render() {
			return h(`x-${name}`, { label: this.label || this.inputLabel, 'data-disabled': this.disabled ? 'yes' : 'no' }, this.$slots.default?.())
		},
	}
}

/**
 * Render the form in a given state.
 *
 * @param {object} state Data to start from.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	http.get.mockResolvedValue({ data: {} })
	const component = {
		...Form,
		components: { NcButton: stub('NcButton'), NcTextField: stub('NcTextField'), NcInputField: stub('NcInputField'), NcSelect: stub('NcSelect') },
		data: () => ({ ...Form.data(), ...state }),
	}
	const app = createSSRApp(component)
	app.config.globalProperties.t = (_app, text) => text
	return renderToString(app)
}

/**
 * Answer GETs by url.
 *
 * @param {object} answers Response data, or an Error, by url.
 */
function answer(answers) {
	http.get.mockImplementation((url, options) => {
		const key = options?.params?.filter?.poId ? `${url}?${options.params.filter.poId}` : url
		const value = answers[key]
		return value instanceof Error ? Promise.reject(value) : Promise.resolve({ data: value })
	})
}

const LINE = (id, received = 0) => ({ quantityReceived: received, quantityAccepted: 0, quantityRejected: 0, rejectionReason: '', batchReference: '' })

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('what the form offers', () => {
	it('loads the administrations and the open purchase orders on create', async () => {
		answer({
			'/index.php/apps/shillinq/api/administrations/context': {
				activeAdministrationId: 'a1',
				administrations: [
					{ administrationId: 'a1', name: 'Hoofdkantoor' },
					{ administrationId: 'a2', administrationCode: 'FIL' },
					{ administrationId: 'a3' },
				],
			},
			[`${OBJECTS}/PurchaseOrder`]: {
				results: [
					{ id: 'po-1', poNumber: 'PO-001', lifecycleState: 'approved' },
					{ id: 'po-2', lifecycleState: 'draft' },
					{ id: 'po-3', lifecycleState: 'cancelled' },
					{ poNumber: 'PO-004' },
				],
			},
		})
		const self = instance()
		await Form.created.call(self)
		expect(self.administrationId).toBe('a1')
		expect(self.administrationOptions).toEqual([
			{ value: 'a1', label: 'Hoofdkantoor' },
			{ value: 'a2', label: 'FIL' },
			{ value: 'a3', label: 'a3' },
		])
		expect(self.poOptions).toEqual([
			{ value: 'po-1', label: 'PO-001' },
			{ value: 'PO-004', label: 'PO-004' },
		])
		expect(Object.keys(self.openPurchaseOrders)).toEqual(['po-1', 'PO-004'])
	})

	it('keeps the administration unset without an active one', async () => {
		answer({ '/index.php/apps/shillinq/api/administrations/context': {} })
		const self = instance()
		await self.loadAdministrationContext()
		expect(self.administrationId).toBe('')
		expect(self.administrationOptions).toEqual([])
	})

	it('says when the administrations cannot be read, and offers no orders when those cannot', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const self = instance({ poOptions: [{ value: 'x' }], openPurchaseOrders: { x: {} } })
		await self.loadAdministrationContext()
		await self.loadOpenPurchaseOrders()
		expect(self.error).toBe('Failed to load administration context')
		expect(self.poOptions).toEqual([])
		expect(self.openPurchaseOrders).toEqual({})
	})

	it('offers the six rejection reasons', () => {
		expect(instance().rejectionReasonOptions.map((o) => o.value)).toEqual([
			'schade', 'verkeerd_product', 'expired', 'niet_besteld', 'short_shipped', 'other',
		])
	})
})

describe('lines per chosen purchase order', () => {
	it('loads the lines of every chosen order and keeps entered quantities', async () => {
		answer({
			[`${OBJECTS}/PurchaseOrderLine?po-1`]: { results: [{ id: 'l1' }, { id: 'l2' }] },
			[`${OBJECTS}/PurchaseOrderLine?po-2`]: new Error('gone'),
			[`${OBJECTS}/PurchaseOrderLine?po-3`]: [{ id: 'l3' }],
		})
		const kept = LINE('l1', 4)
		const self = instance({ selectedPoIds: ['po-1', 'po-2', 'po-3'], lineState: { l1: kept, stale: LINE('stale') } })
		await self.refreshLines()
		expect(self.availableLines.map((l) => l.id)).toEqual(['l1', 'l2', 'l3'])
		expect(self.lineState.l1).toBe(kept)
		expect(self.lineState.l2).toEqual(LINE('l2'))
		expect(self.lineState.stale).toBeUndefined()
	})

	it('clears the lines when no order is chosen', async () => {
		const self = instance({ availableLines: [{ id: 'l1' }], lineState: { l1: LINE('l1') } })
		await self.refreshLines()
		expect(self.availableLines).toEqual([])
		expect(self.lineState).toEqual({})
		expect(http.get).not.toHaveBeenCalled()
	})

	it('reloads the lines when the chosen orders change', () => {
		const self = instance()
		self.refreshLines = vi.fn()
		Form.watch.selectedPoIds.handler.call(self)
		expect(self.refreshLines).toHaveBeenCalledTimes(1)
		expect(Form.watch.selectedPoIds.deep).toBe(true)
	})
})

describe('when the form may be saved', () => {
	it('needs an administration, an order and a received quantity', () => {
		const ready = { administrationId: 'a1', selectedPoIds: ['po-1'], lineState: { l1: LINE('l1', 1) } }
		expect(instance(ready).canSubmit).toBe(true)
		expect(instance({ ...ready, administrationId: '' }).canSubmit).toBe(false)
		expect(instance({ ...ready, selectedPoIds: [] }).canSubmit).toBe(false)
		expect(instance({ ...ready, lineState: { l1: LINE('l1', 0) } }).canSubmit).toBe(false)
	})

	it('refuses to save an incomplete form and says what is missing', async () => {
		const self = instance()
		await self.onSubmit()
		expect(self.error).toBe('Pick at least one PO and one line with a received quantity')
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('saving', () => {
	it('uploads the photos, creates the note, posts the received lines and opens the note', async () => {
		vi.stubGlobal('FormData', class {
			constructor() {
				this.entries = []
			}

			append(key, value) {
				this.entries.push([key, value])
			}
		})
		http.post.mockImplementation((url) => {
			if (url.endsWith('/docudesk/upload')) return Promise.resolve({ data: { fileId: 77 } })
			if (url === GRN) return Promise.resolve({ data: { '@self': { id: 'grn-9' } } })
			return Promise.resolve({ data: {} })
		})
		const self = instance({
			administrationId: 'a1',
			selectedPoIds: ['po-1'],
			carrier: 'PostNL',
			deliveryNoteReference: 'PB-1',
			availableLines: [{ id: 'l1' }, { id: 'l2' }, { id: 'l3' }],
			lineState: {
				l1: { ...LINE('l1', 5), quantityRejected: 1, rejectionReason: 'schade', batchReference: 'B7' },
				l2: LINE('l2', 0),
			},
		})
		self.onPhotoSelected({ target: { files: [{ name: 'dock.jpg', size: 10 }] } })
		await self.onSubmit()

		const calls = http.post.mock.calls
		expect(calls[0][0]).toBe('/index.php/apps/shillinq/api/docudesk/upload')
		expect(calls[0][1].entries).toEqual([['file', { name: 'dock.jpg', size: 10 }], ['administrationId', 'a1']])
		expect(calls[1]).toEqual([GRN, {
			administrationId: 'a1', poIds: ['po-1'], carrier: 'PostNL', deliveryNoteReference: 'PB-1', photos: ['77'],
		}])
		expect(calls[2]).toEqual([`${GRN}/grn-9/lines`, {
			administrationId: 'a1', poLineId: 'l1', quantityReceived: 5, quantityAccepted: 5,
			quantityRejected: 1, rejectionReason: 'schade', batchReference: 'B7',
		}])
		expect(calls).toHaveLength(3)
		expect(self.$router.push).toHaveBeenCalledWith({ name: 'GoodsReceiptNoteDetail', params: { id: 'grn-9' } })
		expect(self.submitting).toBe(false)
		expect(self.error).toBe('')
		vi.unstubAllGlobals()
	})

	it('skips a photo that cannot be uploaded and one without an id', async () => {
		vi.stubGlobal('FormData', class {
			append() {}
		})
		http.post.mockRejectedValueOnce(new Error('too big')).mockResolvedValueOnce({ data: {} }).mockResolvedValueOnce({ data: { id: 5 } })
		const self = instance({ photos: [{ file: {} }, { file: {} }, { file: {} }] })
		await expect(self.uploadPhotosToDocudesk()).resolves.toEqual(['5'])
		vi.unstubAllGlobals()
	})

	it('uploads nothing without photos', async () => {
		await expect(instance().uploadPhotosToDocudesk()).resolves.toEqual([])
		expect(http.post).not.toHaveBeenCalled()
	})

	it('refuses a created note without an id, and shows the server error', async () => {
		const ready = { administrationId: 'a1', selectedPoIds: ['po-1'], lineState: { l1: LINE('l1', 1) } }
		http.post.mockResolvedValueOnce({ data: {} })
		const self = instance(ready)
		await self.onSubmit()
		expect(self.error).toBe('Failed to save goods receipt')
		expect(self.$router.push).not.toHaveBeenCalled()

		http.post.mockRejectedValueOnce({ response: { data: { error: 'PO closed' } } })
		await self.onSubmit()
		expect(self.error).toBe('PO closed')
		expect(self.submitting).toBe(false)
	})

	it('takes the note number when the response has no id', async () => {
		http.post.mockResolvedValue({ data: { grnNumber: 'GRN-2026-1' } })
		await expect(instance().createGoodsReceiptNote([])).resolves.toBe('GRN-2026-1')
	})

	it('lists picked photos by name', () => {
		const self = instance()
		self.onPhotoSelected({ target: { files: [{ name: 'a.jpg', size: 1 }, { name: 'b.jpg', size: 2 }] } })
		self.onPhotoSelected({ target: {} })
		expect(self.photos.map((p) => [p.id, p.name])).toEqual([['a.jpg-1', 'a.jpg'], ['b.jpg-2', 'b.jpg']])
		expect(self.formatQty('2.5')).toBe('2.500')
		expect(self.formatQty(undefined)).toBe('0.000')
	})
})

describe('what the form shows', () => {
	it('asks for an order and shows no photos when empty, with save disabled', async () => {
		const html = await render({})
		expect(html).toContain('Pick at least one purchase order to load lines.')
		expect(html).toContain('No photos attached yet.')
		expect(html).toMatch(/<x-NcButton[^>]*data-disabled="yes"[^>]*>\s*Save goods receipt/)
		expect(html).not.toContain('grn-form__error')
	})

	it('shows each line with its ordered quantity and a reason field only when something is rejected', async () => {
		const html = await render({
			availableLines: [
				{ id: 'l1', description: 'Pallet bricks', quantityOrdered: 12, unitOfMeasure: 'pcs' },
				{ id: 'l2', productOrServiceCode: 'SKU-2', quantityOrdered: 1 },
				{ id: 'l3' },
			],
			lineState: { l1: { ...LINE('l1', 12), quantityRejected: 2 }, l2: LINE('l2'), l3: LINE('l3') },
			photos: [{ id: 'p', name: 'dock.jpg' }],
			error: 'PO closed',
			administrationId: 'a1',
			selectedPoIds: ['po-1'],
		})
		expect(html).toContain('Pallet bricks')
		expect(html).toContain('12.000')
		expect(html).toContain('pcs')
		expect(html).toContain('SKU-2')
		expect(html).toContain('data-testid="grn-form-line-l3"')
		expect(html.match(/label="Rejection reason"/g)).toHaveLength(1)
		expect(html).toContain('dock.jpg')
		expect(html).toContain('PO closed')
		expect(html).toMatch(/<x-NcButton[^>]*data-disabled="no"[^>]*>\s*Save goods receipt/)
	})

	it('says it is saving while it saves', async () => {
		expect(await render({ submitting: true })).toContain('Saving…')
	})
})
