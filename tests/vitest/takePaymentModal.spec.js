/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Take payment now on ARInvoiceDetail (receivables-provider-payouts
 * REQ-RPPO-005): the request it sends, the device and QR answers, the QR code
 * of the checkout fallback, the refusal line, and the header action that opens
 * it on an open invoice only. HTTP, the event bus and the @nextcloud/vue
 * components are replaced; src/utils/pointOfSaleApi.js and the qrcode
 * library run.
 *
 * @spec openspec/changes/receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
 */

import { readFileSync } from 'node:fs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
	}
})

const { default: TakePaymentModal } = await import('../../src/modals/TakePaymentModal.vue')
const { checkoutQrImage, refusalMessage, takePayment } = await import('../../src/utils/pointOfSaleApi.js')

const CHECKOUT = 'https://pay.example.test/checkout/tr_7'

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
})

describe('pointOfSaleApi', () => {
	it('posts to the invoice take-payment route and answers the mode and checkout', async () => {
		http.post.mockResolvedValue({ data: { mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' } })

		const answer = await takePayment('inv 1')

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/financial-documents/invoices/inv%201/take-payment')
		expect(answer).toEqual({ mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' })
	})

	it('names the server refusal, else the fallback', () => {
		expect(refusalMessage({ response: { data: { error: 'Register your phone first.' } } }, 'fallback')).toBe('Register your phone first.')
		expect(refusalMessage(new Error('network'), 'fallback')).toBe('fallback')
	})

	it('draws the checkout as a PNG QR code, and nothing without a URL', async () => {
		expect(await checkoutQrImage(CHECKOUT)).toMatch(/^data:image\/png;base64,/)
		expect(await checkoutQrImage('')).toBe('')
	})
})

describe('TakePaymentModal', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(TakePaymentModal)
		expect(view.standIns('NcDialog')).toEqual([])
	})

	it('offers to take the payment before it starts', async () => {
		const view = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })

		expect(view.html).toContain('goes to the payment app on your registered phone')
		expect(view.byTestId('take-payment-start')).toBeDefined()
		expect(view.byTestId('take-payment-qr')).toBeUndefined()
	})

	it('says the payment is on the phone when the provider took it on the device', async () => {
		http.post.mockResolvedValue({ data: { mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' } })
		const view = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })

		await view.vm.start()
		const after = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/financial-documents/invoices/inv-1/take-payment')
		expect(after.byTestId('take-payment-on-device')).toBeDefined()
		expect(after.byTestId('take-payment-qr')).toBeUndefined()
		expect(after.byTestId('take-payment-start')).toBeUndefined()
	})

	it('shows the checkout as a QR code with a link when the phone cannot take it', async () => {
		http.post.mockResolvedValue({ data: { mode: 'qr', request: { id: 'pr-2' }, checkoutUrl: CHECKOUT } })
		const view = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })

		await view.vm.start()
		const after = await view.rerender()

		expect(after.byTestId('take-payment-qr').props.src).toMatch(/^data:image\/png;base64,/)
		expect(after.byTestId('take-payment-qr').props.alt).toBe('QR code of the payment page')
		expect(after.byTestId('take-payment-checkout-link').props.href).toBe(CHECKOUT)
	})

	it('shows the refusal and keeps the start button when the server refuses', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'Register your phone as a payment device first.' } } })
		const view = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })

		await view.vm.start()
		const after = await view.rerender()

		expect(after.html).toContain('Register your phone as a payment device first.')
		expect(after.byTestId('take-payment-start')).toBeDefined()
		expect(view.vm.submitting).toBe(false)
	})

	it('refreshes the invoice page on close only after a payment was started', async () => {
		const idle = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })
		idle.vm.onClose()
		expect(bus.emit).not.toHaveBeenCalled()

		http.post.mockResolvedValue({ data: { mode: 'device', request: {}, checkoutUrl: '' } })
		const started = await renderWithStandIns(TakePaymentModal, { props: { open: true, objectId: 'inv-1' } })
		await started.vm.start()
		started.vm.onClose()
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})
})

describe('the Take payment now header action', () => {
	it.each([
		'src/manifest.json',
		'src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json',
	])('opens TakePaymentModal on an open ARInvoiceDetail in %s', (file) => {
		const manifest = JSON.parse(readFileSync(new URL('../../' + file, import.meta.url), 'utf8'))
		const page = manifest.pages.find((candidate) => candidate.id === 'ARInvoiceDetail')
		const action = page.config.headerActions.find((candidate) => candidate.id === 'take-payment-now')

		expect(action).toMatchObject({ type: 'open-modal', target: 'TakePaymentModal', props: { open: true } })
		expect(action.visibleWhen).toEqual({ field: 'lifecycleState', op: 'in', value: ['issued', 'overdue', 'partially-paid'] })
	})
})
