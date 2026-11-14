/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The purchase-order form and detail as they render (server-side, so no DOM
 * is needed), and the form's own steps: loading the administrations, the
 * server's approval chain preview, the line editing and the submit refusals
 * and failure.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag = 'div') => ({
		name,
		props: ['label', 'inputLabel', 'disabled', 'modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-label': this.label || this.inputLabel, disabled: this.disabled || undefined }, this.$slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton', 'button'),
		NcInputField: stub('NcInputField'),
		NcSelect: stub('NcSelect'),
		NcTextArea: stub('NcTextArea'),
		NcTextField: stub('NcTextField'),
	}
})

const t = (_app, text) => text
const { default: PurchaseOrderForm } = await import('../../src/components/purchase-order/PurchaseOrderForm.vue')
const { default: PurchaseOrderDetail } = await import('../../src/components/purchase-order/PurchaseOrderDetail.vue')

/**
 * Render a component with its data replaced by `state` and its load hooks off.
 *
 * @param {object} component The component.
 * @param {object} state The data to render with.
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
async function render(component, state, props = {}) {
	const app = createSSRApp({ ...component, created: undefined, watch: {}, data() { return { ...component.data.call(this), ...state } } }, props)
	app.config.globalProperties.t = t
	app.component('RouterLink', { props: ['to'], render() { return h('a', { 'data-to': `${this.to.name}:${this.to.params.id}` }, this.$slots.default?.()) } })
	return renderToString(app)
}

/**
 * A stand-in instance of the form for calling its methods.
 *
 * @param {object} state Fields to set.
 * @return {object} The instance.
 */
function form(state = {}) {
	const self = { t, pushed: [], $router: { push: (to) => self.pushed.push(to) } }
	for (const [name, method] of Object.entries(PurchaseOrderForm.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, PurchaseOrderForm.data.call(self), state)
	Object.defineProperty(self, 'total', { get: () => PurchaseOrderForm.computed.total.call(self) })
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PurchaseOrderForm renders', () => {
	it('each line with its total, the order total and the server chain', async () => {
		const html = await render(PurchaseOrderForm, {
			lines: [{ productCode: 'P-1', quantity: 2, unitPrice: 12.5, vatRate: 0.21, glAccount: '4000' }],
			approvalChain: [{ order: 1, role: 'teamleider' }, { order: 2, role: 'cfo' }],
		})
		expect(html).toContain('data-testid="po-form-line-0"')
		expect(html).toContain('EUR 25.00')
		expect(html).toMatch(/data-testid="po-form-total"[\s\S]*<strong[^>]*>EUR 25.00<\/strong>/)
		expect(html).toContain('data-testid="po-form-chain"')
		expect(html).toContain('Team lead')
		expect(html).toContain('cfo')
		expect(html).not.toContain('No approvers required yet')
		expect(html).toContain('Create purchase order')
		expect(html).not.toContain('data-testid="po-form-error"')
	})

	it('the empty chain, the error and the busy submit button', async () => {
		const html = await render(PurchaseOrderForm, { currency: 'USD', approvalChain: [], error: 'Supplier id is required', submitting: true })
		expect(html).toContain('No approvers required yet')
		expect(html).toContain('USD 0.00')
		expect(html).toMatch(/data-testid="po-form-error"[^>]*>\s*Supplier id is required/)
		expect(html).toContain('Creating…')
		expect(html).toMatch(/<button[^>]*disabled[^>]*data-testid="po-form-submit"|data-testid="po-form-submit"[^>]*disabled/)
	})
})

describe('PurchaseOrderForm steps', () => {
	it('loads the administrations and picks the active one', async () => {
		axios.get.mockResolvedValueOnce({ data: { administrations: [{ administrationId: 'a1', name: 'Main' }, { administrationId: 'a2', administrationCode: 'SUB' }, { administrationId: 'a3' }], activeAdministrationId: 'a2' } })
		const self = form()
		await self.loadAdministrationContext()
		expect(axios.get).toHaveBeenCalledWith('/apps/shillinq/api/administrations/context')
		expect(self.administrationOptions).toEqual([
			{ value: 'a1', label: 'Main' },
			{ value: 'a2', label: 'SUB' },
			{ value: 'a3', label: 'a3' },
		])
		expect(self.administrationId).toBe('a2')
	})

	it('shows an inline error when the administrations cannot be read', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const self = form()
		await self.loadAdministrationContext()
		expect(self.error).toBe('Failed to load administration context')
		expect(self.administrationOptions).toEqual([])
	})

	it('asks the server for the chain of a positive amount only, and clears it on failure', async () => {
		const self = form({ approvalChain: [{ role: 'old' }] })
		await self.refreshApprovalChain(0)
		expect(self.approvalChain).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockResolvedValueOnce({ data: { chain: [{ order: 1, role: 'teamleider' }] } })
		await self.refreshApprovalChain(150)
		expect(axios.get).toHaveBeenCalledWith('/apps/shillinq/api/purchase-orders/approval-chain', { params: { amount: 150 } })
		expect(self.approvalChain).toEqual([{ order: 1, role: 'teamleider' }])

		axios.get.mockResolvedValueOnce({ data: {} })
		await self.refreshApprovalChain(150)
		expect(self.approvalChain).toEqual([])

		self.approvalChain = [{ role: 'x' }]
		axios.get.mockRejectedValueOnce(new Error('down'))
		await self.refreshApprovalChain(150)
		expect(self.approvalChain).toEqual([])
	})

	it('adds and removes lines, keeping one empty line', () => {
		const self = form()
		self.addLine()
		expect(self.lines).toHaveLength(2)
		self.removeLine(0)
		self.removeLine(0)
		expect(self.lines).toEqual([{ productCode: '', quantity: 1, unitPrice: 0, vatRate: 0.21, glAccount: '' }])
	})

	it('totals lines in whole cents and labels roles', () => {
		const self = form({ lines: [{ quantity: '3', unitPrice: '0.1' }, { quantity: 'x', unitPrice: 5 }] })
		expect(self.total).toBe(0.3)
		expect(self.roleLabel('facility_manager')).toBe('Facility Manager')
		expect(self.roleLabel('procurement_manager')).toBe('Procurement Manager')
		expect(self.roleLabel('cfo')).toBe('cfo')
		expect(form({ currency: '' }).formatMoney(null)).toBe('EUR 0.00')
	})

	it.each([
		[{ administrationId: '' }, 'Administration is required'],
		[{ supplierId: '' }, 'Supplier id is required'],
		[{ costCenter: '' }, 'Cost center is required'],
		[{ lines: [{ quantity: 0, unitPrice: 10 }] }, 'Purchase order total must be positive'],
	])('refuses to submit %o', async (missing, message) => {
		const self = form({ administrationId: 'a1', supplierId: 's1', costCenter: 'cc', lines: [{ quantity: 1, unitPrice: 10 }], ...missing })
		await self.onSubmit()
		expect(self.error).toBe(message)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('posts the order and opens it, or shows the server failure', async () => {
		const lines = [{ quantity: 1, unitPrice: 10 }]
		const self = form({ administrationId: 'a1', supplierId: 's1', costCenter: 'cc', projectCode: 'p', notes: 'n', lines })
		axios.post.mockResolvedValueOnce({ data: { poNumber: 'PO-7' } })
		await self.onSubmit()
		expect(axios.post).toHaveBeenCalledWith('/apps/shillinq/api/purchase-orders', { administrationId: 'a1', supplierId: 's1', costCenter: 'cc', projectCode: 'p', currency: 'EUR', notes: 'n', lines })
		expect(self.pushed).toEqual([{ name: 'PurchaseOrderDetail', params: { id: 'PO-7' } }])
		expect([self.error, self.submitting]).toEqual(['', false])

		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Supplier unknown' } } })
		await self.onSubmit()
		expect([self.error, self.submitting]).toEqual(['Supplier unknown', false])

		axios.post.mockRejectedValueOnce(new Error('down'))
		await self.onSubmit()
		expect(self.error).toBe('Failed to create purchase order')
	})
})

describe('PurchaseOrderDetail renders', () => {
	const order = {
		poNumber: 'PO-7',
		statusCode: 'approved',
		supplierId: 'S-1',
		costCenter: 'CC-1',
		projectCode: 'PRJ',
		totalAmount: 121,
		lines: [{ lineNumber: 1, productCode: 'P-1', quantity: 2, unitPrice: 50, vatRate: 0.21, glAccount: '4000', lineTotal: 100 }],
		approvalChain: [{ order: 1, role: 'teamleider', decision: 'approved', decidedAt: '2026-11-01T10:00:00Z', userId: 'anna' }],
		peppolMessageId: 'MSG-1',
		peppolSentAt: '2026-11-02T10:00:00Z',
		peppolFallbackReason: 'No Peppol id',
	}

	it('the loading state and the error', async () => {
		expect(await render(PurchaseOrderDetail, { loading: true }, { id: 'po-1' })).toContain('Loading purchase order…')
		const failed = await render(PurchaseOrderDetail, { loading: false, error: 'Failed to load purchase order' }, { id: 'po-1' })
		expect(failed).toMatch(/data-testid="po-detail-error"[^>]*>\s*Failed to load purchase order/)
	})

	it('an approved order with its lines, signed chain, related records and transmission', async () => {
		const html = await render(PurchaseOrderDetail, {
			loading: false,
			purchaseOrder: order,
			grns: [{ id: 'g1', grnNumber: 'GRN-1' }, { id: 'g2' }],
			matches: [{ id: 'm1', matchReference: 'MATCH-1' }],
			sendError: 'Peppol access point refused',
		}, { id: 'po-1' })
		expect(html).toMatch(/<h2[^>]*>PO-7<\/h2>/)
		expect(html).toContain('Project')
		expect(html).toContain('EUR 121.00')
		expect(html).toContain('data-testid="po-detail-line-1"')
		expect(html).toContain('21.00%')
		expect(html).toContain('Team lead')
		expect(html).toContain('Signed')
		expect(html).toContain('(anna)')
		expect(html).toContain('data-to="GoodsReceiptNoteDetail:g1"')
		expect(html).toContain('GRN-1')
		expect(html).toMatch(/data-to="GoodsReceiptNoteDetail:g2"[^>]*>g2/)
		expect(html).toContain('data-to="ThreeWayMatchDetail:m1"')
		expect(html).toContain('MSG-1')
		expect(html).toContain('No Peppol id')
		expect(html).toContain('Send via Peppol')
		expect(html).not.toContain('Sending is blocked')
		expect(html).toMatch(/data-testid="po-detail-send-error"[^>]*>\s*Peppol access point refused/)
	})

	it('an unsigned order without related records, and the busy channel', async () => {
		const unsigned = { ...order, projectCode: '', approvalChain: [{ order: 1, role: 'teamleider', decision: 'pending' }], peppolMessageId: '', peppolSentAt: '', peppolFallbackReason: '' }
		const html = await render(PurchaseOrderDetail, { loading: false, purchaseOrder: unsigned, sending: true, sendingChannel: 'email' }, { id: 'po-1' })
		expect(html).toContain('No goods receipt notes yet')
		expect(html).toContain('No matches yet')
		expect(html).toContain('Not yet transmitted')
		expect(html).not.toContain('Fallback reason')
		expect(html).toContain('Sending PDF…')
		expect(html).toContain('Sending is blocked until every approver signs.')
		expect(html).not.toContain('Signed')

		const peppol = await render(PurchaseOrderDetail, { loading: false, purchaseOrder: { ...unsigned, approvalChain: [] }, sending: true, sendingChannel: 'peppol' }, { id: 'po-1' })
		expect(peppol).toContain('Sending Peppol…')
		expect(peppol).toContain('No approval chain required.')
	})

	it('a sent order says so', async () => {
		const html = await render(PurchaseOrderDetail, { loading: false, purchaseOrder: { ...order, statusCode: 'sent' } }, { id: 'po-1' })
		expect(html).toContain('data-testid="po-detail-already-sent"')
		expect(html).not.toContain('Sending is blocked')
	})
})
