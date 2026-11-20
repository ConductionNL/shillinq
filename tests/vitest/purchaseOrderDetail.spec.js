/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins PurchaseOrderDetail's logic against a fake instance: when an order
 * may be sent (every approval decided), loading the order and its goods
 * receipts and three-way matches from OpenRegister, the Peppol and e-mail
 * transmissions with their errors, and the formatting helpers.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PurchaseOrderDetail from '../../src/components/purchase-order/PurchaseOrderDetail.vue'

const { computed, methods } = PurchaseOrderDetail
const originalGet = axios.get
const originalPost = axios.post

/**
 * A fake component instance with the component's data, methods and `t`.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function instance(overrides = {}) {
	const vm = {
		id: 'po-1',
		...PurchaseOrderDetail.data(),
		t: (_app, text) => text,
		...overrides,
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * The canSend computed for an order.
 *
 * @param {object|null} purchaseOrder The order.
 * @return {boolean} Whether it may be sent.
 */
function canSend(purchaseOrder) {
	return computed.canSend.call({ purchaseOrder })
}

afterEach(() => {
	axios.get = originalGet
	axios.post = originalPost
})

describe('PurchaseOrderDetail canSend', () => {
	it('is false without an order, once sent, or without an approval chain', () => {
		expect(canSend(null)).toBe(false)
		expect(canSend({ statusCode: 'sent', approvalChain: [{ decision: 'approved', decidedAt: 'x' }] })).toBe(false)
		expect(canSend({ statusCode: 'approved' })).toBe(false)
		expect(canSend({ statusCode: 'approved', approvalChain: [] })).toBe(false)
	})

	it('is true only when every step is approved and dated', () => {
		const approved = { decision: 'approved', decidedAt: '2026-01-01T00:00:00Z' }
		expect(canSend({ statusCode: 'approved', approvalChain: [approved, approved] })).toBe(true)
		expect(canSend({ statusCode: 'approved', approvalChain: [approved, { decision: 'pending', decidedAt: 'x' }] })).toBe(false)
		expect(canSend({ statusCode: 'approved', approvalChain: [approved, { decision: 'approved' }] })).toBe(false)
	})
})

describe('PurchaseOrderDetail loading', () => {
	it('loads the order, then its goods receipts and matches filtered on this order', async () => {
		const calls = []
		axios.get = vi.fn(async (url, config) => {
			calls.push([url, config])
			if (url.endsWith('/PurchaseOrder/po-1')) {
				return { data: { id: 'po-1', currency: 'USD' } }
			}
			if (url.endsWith('/GoodsReceiptNote')) {
				return { data: { results: [{ id: 'grn-1' }] } }
			}
			return { data: [{ id: 'm-1' }] }
		})
		const vm = instance()

		await PurchaseOrderDetail.created.call(vm)

		expect(calls).toEqual([
			['/index.php/apps/openregister/api/objects/shillinq/PurchaseOrder/po-1', undefined],
			// Which send channels the server can use is read beside the order (task 3.2).
			['/index.php/apps/shillinq/api/purchase-orders/dispatch-channels', undefined],
			['/index.php/apps/openregister/api/objects/shillinq/GoodsReceiptNote', { params: { filter: { poIds: 'po-1' } } }],
			['/index.php/apps/openregister/api/objects/shillinq/ThreeWayMatch', { params: { filter: { matchedPoIds: 'po-1' } } }],
		])
		expect(vm.purchaseOrder).toEqual({ id: 'po-1', currency: 'USD' })
		expect(vm.grns).toEqual([{ id: 'grn-1' }])
		expect(vm.matches).toEqual([{ id: 'm-1' }])
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
	})

	it('does not look for related records when there is no order', async () => {
		axios.get = vi.fn(async () => ({ data: null }))
		const vm = instance()

		await vm.loadPurchaseOrder()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(vm.purchaseOrder).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('falls back to empty lists when the related lookups fail', async () => {
		axios.get = vi.fn(async (url) => {
			if (url.endsWith('/PurchaseOrder/po-1')) {
				return { data: { id: 'po-1' } }
			}
			throw new Error('schema not registered')
		})
		const vm = instance({ grns: ['stale'], matches: ['stale'] })

		await vm.loadPurchaseOrder()

		expect(vm.grns).toEqual([])
		expect(vm.matches).toEqual([])
		expect(vm.error).toBe('')
	})

	it('shows the server error, or a generic one, when the order cannot be loaded', async () => {
		axios.get = vi.fn(async () => {
			throw { response: { data: { error: 'Not allowed' } } }
		})
		const vm = instance()
		await vm.loadPurchaseOrder()
		expect(vm.error).toBe('Not allowed')
		expect(vm.loading).toBe(false)

		axios.get = vi.fn(async () => {
			throw new Error('offline')
		})
		const other = instance()
		await other.loadPurchaseOrder()
		expect(other.error).toBe('Failed to load purchase order')
	})
})

describe('PurchaseOrderDetail sending', () => {
	beforeEach(() => {
		axios.post = vi.fn(async () => ({ data: { id: 'po-1', statusCode: 'sent' } }))
	})

	it('sends over Peppol with the administration and takes the answer as the order', async () => {
		const vm = instance({ purchaseOrder: { id: 'po-1', administrationId: 'adm-1' }, sendError: 'old' })

		await vm.onSendPeppol()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/purchase-orders/po-1/transmit/peppol',
			{ administrationId: 'adm-1' },
		)
		expect(vm.purchaseOrder).toEqual({ id: 'po-1', statusCode: 'sent' })
		expect(vm.sendError).toBe('')
		expect(vm.sending).toBe(false)
		expect(vm.sendingChannel).toBe('')
	})

	it('sends by e-mail with the manual fallback reason', async () => {
		const vm = instance({ purchaseOrder: { id: 'po-1', administrationId: 'adm-1' } })

		await vm.onSendEmail()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/purchase-orders/po-1/transmit/email',
			{ administrationId: 'adm-1', fallbackReason: 'manual_pdf_email_fallback' },
		)
	})

	it('marks the channel while sending', async () => {
		let seen = null
		const vm = instance({ purchaseOrder: { id: 'po-1' } })
		axios.post = vi.fn(async () => {
			seen = [vm.sending, vm.sendingChannel]
			return { data: null }
		})

		await vm.onSendPeppol()

		expect(seen).toEqual([true, 'peppol'])
		expect(vm.purchaseOrder).toEqual({ id: 'po-1' })
	})

	it('keeps the order and shows the server error, or a generic one, when sending fails', async () => {
		const order = { id: 'po-1', administrationId: 'adm-1' }
		axios.post = vi.fn(async () => {
			throw { response: { data: { error: 'Peppol participant unknown' } } }
		})
		const vm = instance({ purchaseOrder: order })
		await vm.onSendPeppol()
		expect(vm.sendError).toBe('Peppol participant unknown')
		expect(vm.purchaseOrder).toBe(order)
		expect(vm.sending).toBe(false)

		axios.post = vi.fn(async () => {
			throw new Error('offline')
		})
		await vm.onSendEmail()
		expect(vm.sendError).toBe('Failed to send purchase order')
	})
})

describe('PurchaseOrderDetail formatting', () => {
	it('formats money in the order currency, EUR by default', () => {
		expect(instance({ purchaseOrder: { currency: 'USD' } }).formatMoney(12.5)).toBe('USD 12.50')
		expect(instance().formatMoney('3')).toBe('EUR 3.00')
		expect(instance().formatMoney(null)).toBe('EUR 0.00')
	})

	it('formats a rate as a percentage', () => {
		const vm = instance()
		expect(vm.formatPct(0.21)).toBe('21.00%')
		expect(vm.formatPct(undefined)).toBe('0.00%')
	})

	it('formats a timestamp with the locale', () => {
		const iso = '2026-03-01T10:00:00Z'
		expect(instance().formatTimestamp(iso)).toBe(new Date(iso).toLocaleString())
	})

	it('labels the approval roles and passes other roles through', () => {
		const vm = instance()
		expect(vm.roleLabel('teamleider')).toBe('Team lead')
		expect(vm.roleLabel('facility_manager')).toBe('Facility Manager')
		expect(vm.roleLabel('procurement_manager')).toBe('Procurement Manager')
		expect(vm.roleLabel('cfo')).toBe('cfo')
	})
})
