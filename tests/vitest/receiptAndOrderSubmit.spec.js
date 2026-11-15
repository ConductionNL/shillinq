/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the submit handlers of the goods-receipt-note and purchase-order
 * forms on a fake instance: the refusals, the requests in order with their
 * payloads, the navigation and the failure text.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import GoodsReceiptNoteForm from '../../src/components/goods-receipt-note/GoodsReceiptNoteForm.vue'
import PurchaseOrderForm from '../../src/components/purchase-order/PurchaseOrderForm.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const originalPost = axios.post

afterEach(() => {
	axios.post = originalPost
})

/**
 * A fake instance of a form: state, bound methods, computed getters, a
 * recording router.
 *
 * @param {object} component The SFC.
 * @param {object} state The data fields.
 * @return {object}
 */
function vm(component, state) {
	const ctx = { error: 'old', submitting: null, pushed: [], ...state }
	for (const [name, fn] of Object.entries(component.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.t = (app, text) => text
	ctx.$router = { push: (to) => ctx.pushed.push(to) }
	for (const name of Object.keys(component.computed || {})) {
		if (!(name in state)) {
			Object.defineProperty(ctx, name, { get: () => component.computed[name].call(ctx), configurable: true })
		}
	}
	return ctx
}

/**
 * Record axios.post calls, answering each with the next response.
 *
 * @param {Array<Function>} answers One answer per call.
 * @return {Array<Array>} The recorded calls.
 */
function recordPosts(answers) {
	const calls = []
	axios.post = vi.fn(async (url, body) => {
		calls.push([url, body])
		return answers[calls.length - 1]()
	})
	return calls
}

const GRN_STATE = {
	administrationId: 'adm-1',
	selectedPoIds: ['po-1'],
	carrier: 'DHL',
	deliveryNoteReference: 'DN-1',
	photos: [],
	availableLines: [{ id: 'l-1' }, { id: 'l-2' }, { id: 'l-3' }, { id: 'l-4' }],
	lineState: {
		'l-1': { quantityReceived: '5', quantityAccepted: '', quantityRejected: 0, rejectionReason: '', batchReference: 'B1' },
		'l-2': { quantityReceived: 0 },
		'l-4': { quantityReceived: 2, quantityAccepted: 1, quantityRejected: 1, rejectionReason: 'Broken', batchReference: '' },
	},
}

describe('GoodsReceiptNoteForm onSubmit', () => {
	it('refuses without a received line', async () => {
		const calls = recordPosts([])
		const ctx = vm(GoodsReceiptNoteForm, { ...GRN_STATE, lineState: {} })
		await ctx.onSubmit()
		expect(ctx.error).toBe('Pick at least one PO and one line with a received quantity')
		expect(ctx.submitting).toBe(null)
		expect(calls).toEqual([])
	})

	it.each([
		['id', { id: 'grn-1' }, 'grn-1'],
		['@self id', { '@self': { id: 'grn-2' } }, 'grn-2'],
		['number', { grnNumber: 'GRN-3' }, 'GRN-3'],
	])('posts the note and its received lines, then opens it by %s', async (name, data, id) => {
		const calls = recordPosts([async () => ({ data }), async () => ({}), async () => ({})])
		const ctx = vm(GoodsReceiptNoteForm, GRN_STATE)
		await ctx.onSubmit()
		expect(calls).toMatchSnapshot()
		expect(ctx.pushed).toEqual([{ name: 'GoodsReceiptNoteDetail', params: { id } }])
		expect([ctx.error, ctx.submitting]).toEqual(['', false])
	})

	it.each([
		['no id in the response', [async () => ({ data: {} })], 'Failed to save goods receipt'],
		['no data at all', [async () => ({})], 'Failed to save goods receipt'],
		['a refused line', [async () => ({ data: { id: 'g' } }), async () => { const e = new Error('x'); e.response = { data: { error: 'Line refused' } }; throw e }], 'Line refused'],
	])('reports %s', async (name, answers, message) => {
		const calls = recordPosts(answers)
		const ctx = vm(GoodsReceiptNoteForm, GRN_STATE)
		await ctx.onSubmit()
		expect([ctx.error, ctx.submitting, ctx.pushed, calls.length]).toEqual([message, false, [], answers.length])
	})
})

const PO_STATE = {
	administrationId: 'adm-1',
	supplierId: 's-1',
	costCenter: 'CC',
	projectCode: 'P',
	currency: 'EUR',
	notes: 'n',
	lines: [{ description: 'x', quantity: 1, unitPrice: 10 }],
	total: 10,
}

describe('PurchaseOrderForm onSubmit', () => {
	it.each([
		['administration', { administrationId: '' }, 'Administration is required'],
		['supplier', { supplierId: '' }, 'Supplier id is required'],
		['cost center', { costCenter: null }, 'Cost center is required'],
		['positive total', { total: 0 }, 'Purchase order total must be positive'],
		['the first missing field', { supplierId: '', total: -1 }, 'Supplier id is required'],
	])('refuses without a %s', async (name, state, message) => {
		const calls = recordPosts([])
		const ctx = vm(PurchaseOrderForm, { ...PO_STATE, ...state })
		await ctx.onSubmit()
		expect([ctx.error, ctx.submitting, calls]).toEqual([message, null, []])
	})

	it.each([
		['id', { id: 'po-1' }, [{ name: 'PurchaseOrderDetail', params: { id: 'po-1' } }]],
		['@self id', { '@self': { id: 'po-2' } }, [{ name: 'PurchaseOrderDetail', params: { id: 'po-2' } }]],
		['number', { poNumber: 'PO-3' }, [{ name: 'PurchaseOrderDetail', params: { id: 'PO-3' } }]],
		['nothing', {}, []],
	])('posts the order and opens it by %s', async (name, data, pushed) => {
		const calls = recordPosts([async () => ({ data })])
		const ctx = vm(PurchaseOrderForm, PO_STATE)
		await ctx.onSubmit()
		expect(calls).toEqual([['/apps/shillinq/api/purchase-orders', { administrationId: 'adm-1', supplierId: 's-1', costCenter: 'CC', projectCode: 'P', currency: 'EUR', notes: 'n', lines: PO_STATE.lines }]])
		expect([ctx.pushed, ctx.error, ctx.submitting]).toEqual([pushed, '', false])
	})

	it('reports a refused order', async () => {
		recordPosts([async () => { throw new Error('down') }])
		const ctx = vm(PurchaseOrderForm, PO_STATE)
		await ctx.onSubmit()
		expect([ctx.error, ctx.submitting]).toEqual(['Failed to create purchase order', false])
	})
})
