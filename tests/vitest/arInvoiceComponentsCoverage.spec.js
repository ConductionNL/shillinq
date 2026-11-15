/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AREInvoiceActions and ArDownPaymentPanel rendered server-side with
 * @nextcloud/axios mocked: the send button's state and label, the queued,
 * fallback and refused sends; the down-payment position, the orders left to
 * deduct, the empty, loading and error states, and the load and deduct calls.
 *
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			push(`<button data-stub="NcButton"${ctx.$attrs.disabled ? ' disabled' : ''}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('</button>')
		},
	},
}))

const { default: AREInvoiceActions } = await import('../../src/components/ar-invoice/AREInvoiceActions.vue')
const { default: ArDownPaymentPanel } = await import('../../src/components/ar-invoice/ArDownPaymentPanel.vue')

const t = (app, text) => text

/**
 * Render a component with props and globals, optionally laying state over its data.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @param {object} [state] Data to lay over its own.
 * @return {Promise<{html: string, vm: object}>}
 */
async function render(component, props, state = {}) {
	let vm = null
	const app = createSSRApp(
		{
			...component,
			mixins: [{ created() { vm = this; Object.assign(this.$data, state) } }],
		},
		props,
	)
	app.config.globalProperties.t = t
	app.config.warnHandler = () => {}
	const html = await renderToString(app)
	return { html, vm }
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	showSuccess.mockReset()
})

describe('AREInvoiceActions', () => {
	const issued = { lifecycleState: 'issued', invoiceNumber: 'INV-7', administrationId: 'adm-1', deliveryStatus: 'not-sent' }

	it('offers the send for an issued invoice and blocks it otherwise', async () => {
		const ready = await render(AREInvoiceActions, { object: issued })
		expect(ready.vm.canSend).toBe(true)
		expect(ready.html).toContain('Send e-invoice')
		expect(ready.html).not.toMatch(/<button data-stub="NcButton" disabled/)

		const draft = await render(AREInvoiceActions, { object: { lifecycleState: 'draft' } })
		expect(draft.vm.canSend).toBe(false)
		expect(draft.vm.deliveryStatus).toBe('not-sent')
		expect(draft.vm.administrationId).toBe('')
		expect(draft.vm.invoiceNumber).toBe('')
		expect(draft.html).toMatch(/<button data-stub="NcButton" disabled/)
	})

	it('shows the sending label, the error and the fallback notice', async () => {
		const { html } = await render(AREInvoiceActions, { object: issued }, { sending: true, sendError: 'Refused', fallbackNotice: 'Use PDF' })

		expect(html).toContain('Sending…')
		expect(html).toContain('data-testid="ar-einvoice-send-error"')
		expect(html).toContain('Refused')
		expect(html).toContain('Use PDF')
	})

	it('queues the send, updates the status and confirms it', async () => {
		axios.post.mockResolvedValue({ data: { deliveryStatus: 'queued' } })
		const { vm } = await render(AREInvoiceActions, { object: issued })

		await vm.onSend()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/INV-7/send-einvoice', { administrationId: 'adm-1' })
		expect(vm.deliveryStatus).toBe('queued')
		expect(vm.fallbackNotice).toBe('')
		expect(vm.sending).toBe(false)
		expect(showSuccess).toHaveBeenCalledWith('Invoice queued for Peppol delivery.')
	})

	it('names the fallback when the debtor has no Peppol participant, without a success toast', async () => {
		axios.post.mockResolvedValue({ data: { fallback: true } })
		const { vm } = await render(AREInvoiceActions, { object: issued })

		await vm.onSend()

		expect(vm.fallbackNotice).toBe('No Peppol participant found for this debtor — use PDF + email instead.')
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('shows the server error of a refused send and stops sending', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Not issued' } } })
		axios.post.mockRejectedValueOnce(new Error('network'))
		const { vm } = await render(AREInvoiceActions, { object: issued })

		await vm.onSend()
		expect(vm.sendError).toBe('Not issued')
		expect(vm.sending).toBe(false)

		await vm.onSend()
		expect(vm.sendError).toBe('Failed to send e-invoice')
	})
})

describe('ArDownPaymentPanel', () => {
	const reload = vi.fn()

	beforeEach(() => {
		globalThis.window = { location: { reload } }
		reload.mockReset()
	})

	afterEach(() => {
		delete globalThis.window
	})

	const pending = () => new Promise(() => {})

	it('shows the loading state, then nothing without an invoice id', async () => {
		const loading = await render(ArDownPaymentPanel, { objectId: '' })
		expect(loading.html).toContain('Loading down payments…')
		expect(axios.get).not.toHaveBeenCalled()

		const empty = await render(ArDownPaymentPanel, { objectId: '' }, { loading: false })
		expect(empty.html).toContain('No down payments for this invoice.')
	})

	it('loads the position and open orders of the invoice', async () => {
		axios.get.mockResolvedValue({ data: { position: [{ id: 1 }], openOrders: [{ orderReference: 'o1' }], orderLabel: 'PO-1' } })
		const { vm } = await render(ArDownPaymentPanel, { objectId: 42 })

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/42/down-payments')
		expect(vm.data).toEqual({ position: [{ id: 1 }], openOrders: [{ orderReference: 'o1' }], orderLabel: 'PO-1' })
		expect(vm.loading).toBe(false)
	})

	it('reads an empty answer as no down payments, and shows a load failure', async () => {
		axios.get.mockResolvedValueOnce({ data: null })
		axios.get.mockResolvedValueOnce({ data: null })
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No access' } } })
		const { vm } = await render(ArDownPaymentPanel, { objectId: 'inv-1' })

		await vm.load()
		expect(vm.data).toEqual({ position: [], openOrders: [], orderLabel: '' })

		await vm.load()
		expect(vm.error).toBe('No access')
		expect(vm.loading).toBe(false)
	})

	it('renders the position rows and the orders left to deduct', async () => {
		axios.get.mockImplementation(pending)
		const data = {
			orderLabel: 'PO-9',
			position: [
				{ id: 1, invoiceNumber: 'DP-1', grossAmount: 121, paid: true, deductedOnInvoiceNumber: 'INV-3' },
				{ id: 2, invoiceNumber: 'DP-2', grossAmount: 'x', paid: false, deductedOnInvoiceNumber: '' },
			],
			openOrders: [{ orderReference: 'o9', orderLabel: 'PO-9', grossAmount: 50 }],
		}

		const { html } = await render(ArDownPaymentPanel, { objectId: 'inv-1' }, { loading: false, data })

		expect(html).toContain('Down payments on order PO-9')
		expect(html).toContain('DP-1')
		expect(html).toMatch(/€\s?121,00/)
		expect(html).toMatch(/€\s?0,00/)
		expect(html).toContain('Not paid yet')
		expect(html).toContain('INV-3')
		expect(html).toContain('Not deducted yet')
		expect(html).toMatch(/Down payments to deduct for order PO-9: €\s?50,00/)
		expect(html).toContain('Deduct down payments')
		expect(html).not.toContain('No down payments for this invoice.')
	})

	it('shows an error in place of the panel', async () => {
		axios.get.mockImplementation(pending)

		const { html } = await render(ArDownPaymentPanel, { objectId: 'inv-1' }, { loading: false, error: 'Broken' })

		expect(html).toContain('role="alert"')
		expect(html).toContain('Broken')
		expect(html).not.toContain('No down payments')
	})

	it('deducts the order on this invoice and reloads the page', async () => {
		axios.get.mockImplementation(pending)
		axios.post.mockResolvedValue({ data: {} })
		const { vm } = await render(ArDownPaymentPanel, { objectId: 'inv-1' })

		await vm.deduct('o9')

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/inv-1/down-payment-deductions', { orderReference: 'o9' })
		expect(showSuccess).toHaveBeenCalledWith('The down payments are deducted on this invoice.')
		expect(reload).toHaveBeenCalledTimes(1)
		expect(vm.deducting).toBe(false)
	})

	it('shows a refused deduction without reloading', async () => {
		axios.get.mockImplementation(pending)
		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Already deducted' } } })
		axios.post.mockRejectedValueOnce(new Error('network'))
		const { vm } = await render(ArDownPaymentPanel, { objectId: 'inv-1' })

		await vm.deduct('o9')
		expect(vm.error).toBe('Already deducted')

		await vm.deduct('o9')
		expect(vm.error).toBe('The down payments could not be deducted.')
		expect(reload).not.toHaveBeenCalled()
		expect(vm.deducting).toBe(false)
	})
})
