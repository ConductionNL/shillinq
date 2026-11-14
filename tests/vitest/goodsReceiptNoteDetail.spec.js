/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * GoodsReceiptNoteDetail: what it reads to show one goods receipt note (the
 * note, its lines, the purchase orders it names, the three-way matches), the
 * quality-check and accept transitions, and its display helpers.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import Detail from '../../src/components/goods-receipt-note/GoodsReceiptNoteDetail.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'

/**
 * A stand-in instance with the component's data, methods and computed.
 *
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = { id: 'grn-1', t: (_app, text) => text, ...Detail.data(), ...fields }
	for (const [name, fn] of Object.entries(Detail.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Detail.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

/**
 * Answer GETs by url.
 *
 * @param {object} answers Response data, or an Error to throw, by url.
 */
function answer(answers) {
	http.get.mockImplementation((url) => {
		const value = answers[url]
		if (value instanceof Error) {
			return Promise.reject(value)
		}
		return Promise.resolve({ data: value })
	})
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('loading a goods receipt note', () => {
	it('starts loading with nothing read', () => {
		expect(Detail.data()).toEqual({
			grn: null, lines: [], matches: [], purchaseOrders: {}, loading: true,
			error: '', transitioning: false, transitionError: '',
		})
	})

	it('reads the note, its lines, its purchase orders and its matches', async () => {
		answer({
			[`${OBJECTS}/GoodsReceiptNote/grn-1`]: { id: 'grn-1', statusCode: 'received', poIds: ['po-1', 'po-2'] },
			[`${OBJECTS}/GoodsReceiptLine`]: { results: [{ id: 'l1' }] },
			[`${OBJECTS}/PurchaseOrder/po-1`]: { id: 'po-1', poNumber: 'PO-001' },
			[`${OBJECTS}/PurchaseOrder/po-2`]: new Error('gone'),
			[`${OBJECTS}/ThreeWayMatch`]: [{ id: 'm1' }],
		})
		const self = instance()
		await Detail.created.call(self)

		expect(http.get).toHaveBeenCalledWith(`${OBJECTS}/GoodsReceiptLine`, { params: { filter: { grnId: 'grn-1' } } })
		expect(http.get).toHaveBeenCalledWith(`${OBJECTS}/ThreeWayMatch`, { params: { filter: { grnIds: 'grn-1' } } })
		expect(self.grn.id).toBe('grn-1')
		expect(self.lines).toEqual([{ id: 'l1' }])
		expect(self.purchaseOrders).toEqual({ 'po-1': { id: 'po-1', poNumber: 'PO-001' }, 'po-2': null })
		expect(self.matches).toEqual([{ id: 'm1' }])
		expect(self.loading).toBe(false)
		expect(self.error).toBe('')
	})

	it('reads nothing further when the note is empty', async () => {
		answer({ [`${OBJECTS}/GoodsReceiptNote/grn-1`]: null })
		const self = instance()
		await self.load()
		expect(http.get).toHaveBeenCalledTimes(1)
		expect(self.grn).toBeNull()
		expect(self.loading).toBe(false)
	})

	it('shows the server error, or a fallback, when the note cannot be read', async () => {
		http.get.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const self = instance()
		await self.load()
		expect(self.error).toBe('Forbidden')
		expect(self.loading).toBe(false)

		http.get.mockRejectedValueOnce(new Error('network'))
		await self.load()
		expect(self.error).toBe('Failed to load goods receipt note')
	})

	it('empties the lines and matches when they cannot be read', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const self = instance({ lines: [{ id: 'old' }], matches: [{ id: 'old' }] })
		await self.loadLines()
		await self.loadMatches()
		expect(self.lines).toEqual([])
		expect(self.matches).toEqual([])
	})

	it('reads no purchase orders when the note names none', async () => {
		const self = instance({ grn: { id: 'grn-1' } })
		await self.loadPurchaseOrders()
		expect(self.purchaseOrders).toEqual({})
		expect(http.get).not.toHaveBeenCalled()
	})
})

describe('the transitions', () => {
	it('allows a quality check only on a received note', () => {
		expect(instance({ grn: { statusCode: 'received' } }).canQualityCheck).toBe(true)
		expect(instance({ grn: { statusCode: 'quality_checked' } }).canQualityCheck).toBe(false)
		expect(instance({ grn: null }).canQualityCheck).toBeFalsy()
	})

	it('allows accepting a received or quality-checked note', () => {
		expect(instance({ grn: { statusCode: 'received' } }).canAccept).toBe(true)
		expect(instance({ grn: { statusCode: 'quality_checked' } }).canAccept).toBe(true)
		expect(instance({ grn: { statusCode: 'accepted' } }).canAccept).toBe(false)
		expect(instance({ grn: null }).canAccept).toBe(false)
	})

	it('posts the quality check with the administration and takes the new note', async () => {
		http.post.mockResolvedValue({ data: { id: 'grn-1', statusCode: 'quality_checked' } })
		const self = instance({ grn: { id: 'grn-1', administrationId: 'adm-1', statusCode: 'received' } })
		await self.onQualityCheck()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/goods-receipt-notes/grn-1/quality-check',
			{ administrationId: 'adm-1' },
		)
		expect(self.grn.statusCode).toBe('quality_checked')
		expect(self.transitioning).toBe(false)
	})

	it('keeps the note and shows why when the quality check is refused', async () => {
		const grn = { id: 'grn-1', statusCode: 'received' }
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Not allowed' } } })
		const self = instance({ grn })
		await self.onQualityCheck()
		expect(self.transitionError).toBe('Not allowed')
		expect(self.grn).toBe(grn)
		expect(self.transitioning).toBe(false)

		http.post.mockRejectedValueOnce(new Error('x'))
		await self.onQualityCheck()
		expect(self.transitionError).toBe('Quality check failed')
	})

	it('accepts, takes the new note and re-reads the lines', async () => {
		http.post.mockResolvedValue({ data: { id: 'grn-1', statusCode: 'accepted' } })
		http.get.mockResolvedValue({ data: { results: [{ id: 'l2' }] } })
		const self = instance({ grn: { id: 'grn-1', administrationId: 'adm-2' }, transitionError: 'old' })
		await self.onAccept()
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/goods-receipt-notes/grn-1/accept',
			{ administrationId: 'adm-2' },
		)
		expect(self.grn.statusCode).toBe('accepted')
		expect(self.lines).toEqual([{ id: 'l2' }])
		expect(self.transitionError).toBe('')
	})

	it('keeps the note when the server answers an accept with nothing', async () => {
		const grn = { id: 'grn-1' }
		http.post.mockResolvedValue({ data: null })
		http.get.mockResolvedValue({ data: [] })
		const self = instance({ grn })
		await self.onAccept()
		expect(self.grn).toBe(grn)
	})

	it('shows why an accept failed', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		const self = instance({ grn: { id: 'grn-1' } })
		await self.onAccept()
		expect(self.transitionError).toBe('Locked')
		http.post.mockRejectedValueOnce(new Error('x'))
		await self.onAccept()
		expect(self.transitionError).toBe('Accept failed')
		expect(self.transitioning).toBe(false)
	})
})

describe('display helpers', () => {
	it('shows a purchase order number, else its id', () => {
		const self = instance({ purchaseOrders: { a: { poNumber: 'PO-9' }, b: {}, c: null } })
		expect(self.poNumberOf('a')).toBe('PO-9')
		expect(self.poNumberOf('b')).toBe('b')
		expect(self.poNumberOf('c')).toBe('c')
		expect(self.poNumberOf('z')).toBe('z')
	})

	it('shows quantities with three decimals', () => {
		const self = instance()
		expect(self.formatQty(2)).toBe('2.000')
		expect(self.formatQty(null)).toBe('0.000')
		expect(self.formatQty('1.23456')).toBe('1.235')
	})

	it('shows a dash for no timestamp', () => {
		const self = instance()
		expect(self.formatTimestamp('')).toBe('—')
		expect(self.formatTimestamp(null)).toBe('—')
		const iso = '2026-01-02T03:04:05Z'
		expect(self.formatTimestamp(iso)).toBe(new Date(iso).toLocaleString())
	})

	it('labels every status, the raw code for an unknown one', () => {
		const self = instance()
		expect(['draft', 'received', 'quality_checked', 'accepted', 'rejected'].map(self.statusLabel))
			.toEqual(['Draft', 'Received', 'Quality checked', 'Accepted', 'Rejected'])
		expect(self.statusLabel('lost')).toBe('lost')
	})

	it('links a photo through the files app with the id encoded', () => {
		expect(instance().photoUrl('12 3')).toBe('/index.php/apps/files/?fileid=12%203')
	})
})
