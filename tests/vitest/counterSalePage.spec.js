/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Counter sale page (receivables-provider-payouts REQ-RPPO-006): the
 * request it sends, the total, the device and QR answers, Customer walked
 * away, the refusal line, and its menu entry under Sales. HTTP and the
 * @nextcloud/vue components are replaced; src/utils/pointOfSaleApi.js and the
 * qrcode library run.
 *
 * @spec openspec/changes/receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
 */

import { readFileSync } from 'node:fs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
const administration = vi.hoisted(() => ({ fetchActiveAdministrationId: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/api/administrationApi.js', () => administration)
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton') }
})

const { default: CounterSalePage } = await import('../../src/views/CounterSalePage.vue')
const { cancelCounterSale, counterSaleTotal, startCounterSale } = await import('../../src/utils/pointOfSaleApi.js')

const TEA_TIN = { description: 'Theeblik groot', quantity: 1, unitPrice: 12.5, vatRate: 21 }
const SALE_ROUTE = '/index.php/apps/shillinq/api/financial-documents/counter-sales'

beforeEach(() => {
	http.post.mockReset()
	http.get.mockReset()
	http.get.mockResolvedValue({ data: { results: [] } })
	administration.fetchActiveAdministrationId.mockReset()
	administration.fetchActiveAdministrationId.mockResolvedValue('THV')
})

/**
 * Whether the rendered page carries an element with this test id.
 *
 * @param {object} view The rendered view.
 * @param {string} id The `data-testid`.
 * @return {boolean}
 */
function has(view, id) {
	return view.html.includes(`data-testid="${id}"`)
		|| view.standIns('NcButton').some((attrs) => attrs['data-testid'] === id)
}

/**
 * The page with its administration loaded and one tea tin line entered.
 *
 * @return {Promise<object>} The rendered view.
 */
async function pageWithTeaTin() {
	const view = await renderWithStandIns(CounterSalePage)
	await view.vm.load()
	Object.assign(view.vm.lines[0], TEA_TIN)
	return view
}

describe('counter sale requests', () => {
	it('posts the sale with its lines, and the customer only when one is chosen', async () => {
		http.post.mockResolvedValue({ data: { invoice: { id: 'inv-cs-1' }, mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' } })

		const answer = await startCounterSale({ administrationId: 'THV', lines: [TEA_TIN] })
		await startCounterSale({ administrationId: 'THV', lines: [TEA_TIN], customerId: 'cm-7' })

		expect(http.post).toHaveBeenNthCalledWith(1, SALE_ROUTE, { administrationId: 'THV', lines: [TEA_TIN] })
		expect(http.post).toHaveBeenNthCalledWith(2, SALE_ROUTE, { administrationId: 'THV', lines: [TEA_TIN], customerId: 'cm-7' })
		expect(answer).toEqual({ invoice: { id: 'inv-cs-1' }, mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' })
	})

	it('cancels a sale through its cancel route', async () => {
		http.post.mockResolvedValue({ data: { invoice: { id: 'inv cs', lifecycleState: 'cancelled' } } })

		expect(await cancelCounterSale('inv cs')).toEqual({ id: 'inv cs', lifecycleState: 'cancelled' })
		expect(http.post).toHaveBeenCalledWith(SALE_ROUTE + '/inv%20cs/cancel')
	})

	it('totals the lines to the cent, skipping a line without a price', () => {
		expect(counterSaleTotal([TEA_TIN])).toBe(12.5)
		expect(counterSaleTotal([{ quantity: 3, unitPrice: 4.95 }, { quantity: 1, unitPrice: 0.1 }, { quantity: 1, unitPrice: 0.2 }])).toBe(15.15)
		expect(counterSaleTotal([{ quantity: 1, unitPrice: null }, { unitPrice: 'x' }])).toBe(0)
	})
})

describe('CounterSalePage', () => {
	it('reads the active administration and offers its customers, not the counter-sales debtor', async () => {
		http.get.mockResolvedValue({ data: { results: [
			{ id: 'cm-7', customerId: 'C-7', legalName: 'Bakkerij Groen' },
			{ id: 'cm-cs', customerId: 'COUNTER-SALES', legalName: 'Counter sales' },
		] } })

		const view = await renderWithStandIns(CounterSalePage)
		await view.vm.load()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/CustomerMaster', {
			params: { administrationId: 'THV', _limit: 100 },
		})
		expect(view.vm.customers.map((customer) => customer.id)).toEqual(['cm-7'])
	})

	it('sends the tea tin to the phone and says so', async () => {
		http.post.mockResolvedValue({ data: { invoice: { id: 'inv-cs-1' }, mode: 'device', request: { id: 'pr-1' }, checkoutUrl: '' } })
		const view = await pageWithTeaTin()

		expect(view.vm.total).toBe(12.5)
		await view.vm.takePayment()
		const after = await view.rerender()

		expect(http.post).toHaveBeenCalledWith(SALE_ROUTE, { administrationId: 'THV', lines: [TEA_TIN] })
		expect(has(after, 'counter-sale-on-device')).toBe(true)
		expect(has(after, 'counter-sale-take-payment')).toBe(false)
		expect(has(after, 'counter-sale-walked-away')).toBe(true)
	})

	it('shows a QR code when the phone cannot take the payment', async () => {
		http.post.mockResolvedValue({ data: { invoice: { id: 'inv-cs-1' }, mode: 'qr', request: {}, checkoutUrl: 'https://pay.example.test/tr_9' } })
		const view = await pageWithTeaTin()

		await view.vm.takePayment()
		const after = await view.rerender()

		expect(has(after, 'counter-sale-qr')).toBe(true)
		expect(view.vm.qrImage).toMatch(/^data:image\/png;base64,/)
		expect(after.html).toContain('src="data:image/png;base64,')
	})

	it('cancels the draft when the customer walks away', async () => {
		http.post
			.mockResolvedValueOnce({ data: { invoice: { id: 'inv-cs-1' }, mode: 'device', request: {}, checkoutUrl: '' } })
			.mockResolvedValueOnce({ data: { invoice: { id: 'inv-cs-1', lifecycleState: 'cancelled' } } })
		const view = await pageWithTeaTin()

		await view.vm.takePayment()
		await view.vm.walkedAway()
		const after = await view.rerender()

		expect(http.post).toHaveBeenLastCalledWith(SALE_ROUTE + '/inv-cs-1/cancel')
		expect(has(after, 'counter-sale-cancelled')).toBe(true)
		expect(after.html).toContain('Nothing was booked.')
	})

	it('shows the refusal and stays on the form when the server refuses', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'Register your phone as a payment device first.' } } })
		const view = await pageWithTeaTin()

		await view.vm.takePayment()
		const after = await view.rerender()

		expect(has(after, 'counter-sale-error')).toBe(true)
		expect(after.html).toContain('Register your phone as a payment device first.')
		expect(has(after, 'counter-sale-take-payment')).toBe(true)
		expect(view.vm.submitting).toBe(false)
	})

	it('asks for an administration when the user works in none', async () => {
		administration.fetchActiveAdministrationId.mockResolvedValue('')
		const view = await renderWithStandIns(CounterSalePage)
		await view.vm.load()
		const after = await view.rerender()

		expect(has(after, 'counter-sale-no-administration')).toBe(true)
		expect(http.get).not.toHaveBeenCalled()
	})
})

describe('the Counter sale menu entry', () => {
	it('is under Sales and routes to the custom page', () => {
		const fragment = JSON.parse(readFileSync(new URL('../../src/manifest.d/receivables-counter-sale.json', import.meta.url), 'utf8'))

		expect(fragment.menu[0]).toMatchObject({ id: 'Verkoop', label: 'Sales' })
		expect(fragment.menu[0].children[0]).toMatchObject({ id: 'CounterSale', route: 'CounterSale' })
		expect(fragment.pages[0]).toMatchObject({ id: 'CounterSale', type: 'custom', component: 'CounterSalePage' })
	})
})
