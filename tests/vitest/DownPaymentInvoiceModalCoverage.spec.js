/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * DownPaymentInvoiceModal driven through its template: rendered with
 * recording stand-ins for @nextcloud/vue (tests/vitest/helpers/standInRender.js),
 * so every v-model writer the template binds (customer, order, the per-rate
 * nets, the mode radios, the value and the invoice date) is fired, the form
 * it builds is submitted through the Create draft button with its exact
 * request, and the rendered labels, rates and error are read back — the
 * client way and the server way. Only HTTP, the toast and the @nextcloud/vue
 * components are replaced; the real downPaymentApi.js helpers run.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcCheckboxRadioSwitch: standIn('NcCheckboxRadioSwitch'),
		NcDialog: standIn('NcDialog'),
		NcSelect: standIn('NcSelect'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: DownPaymentInvoiceModal } = await import('../../src/modals/DownPaymentInvoiceModal.vue')

const RAISE = '/index.php/apps/shillinq/api/ar-invoices/down-payments'

/**
 * The attributes of the stand-in carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} name The stand-in's component name.
 * @param {string} testId The data-testid.
 * @return {object|undefined} Its attributes.
 */
function standInById(view, name, testId) {
	return view.standIns(name).find((attrs) => attrs['data-testid'] === testId)
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-06-15T09:00:00Z'))
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('DownPaymentInvoiceModal template', () => {
	it('starts on today, a percentage and a disabled submit', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal)
		expect(standInById(view, 'NcTextField', 'down-payment-date')).toMatchObject({ modelValue: '2026-06-15', type: 'date', label: 'Invoice date' })
		expect(view.standIns('NcCheckboxRadioSwitch').map((attrs) => [attrs.value, attrs.modelValue])).toEqual([
			['percentage', 'percentage'],
			['amount', 'percentage'],
		])
		expect(standInById(view, 'NcSelect', 'down-payment-customer').inputLabel).toBe('Customer')
		expect(view.byTestId('down-payment-submit').props.disabled).toBe(true)
		expect(view.byTestId('down-payment-error')).toBeUndefined()
		expect(view.standIns('NcDialog')[0].name).toBe('New down-payment invoice')
	})

	it('builds the whole form through the template and raises a percentage of a shillinq order', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(DownPaymentInvoiceModal, {
			props: { onClose },
			data: { administrationId: '7', customers: [{ id: 'c1', legalName: 'Acme' }], orders: [{ id: 'o1', orderNumber: 'SO-1' }] },
		})
		const customer = standInById(view, 'NcSelect', 'down-payment-customer')
		const order = standInById(view, 'NcSelect', 'down-payment-order')

		customer['onUpdate:modelValue'](customer.options[0])
		order['onUpdate:modelValue'](order.options[0])
		standInById(view, 'NcTextField', 'down-payment-value')['onUpdate:modelValue']('30')
		standInById(view, 'NcTextField', 'down-payment-date')['onUpdate:modelValue']('2026-06-20')
		view.standIns('NcCheckboxRadioSwitch')[1]['onUpdate:modelValue']('amount')
		view.standIns('NcCheckboxRadioSwitch')[0]['onUpdate:modelValue']('percentage')

		expect(view.vm.$data).toMatchObject({
			customer: { value: 'c1', label: 'Acme' },
			order: { value: 'o1', label: 'SO-1', shillinq: true },
			value: '30',
			invoiceDate: '2026-06-20',
			mode: 'percentage',
		})

		const again = await view.rerender()
		const submit = again.byTestId('down-payment-submit')
		expect(submit.props.disabled).toBe(false)
		expect(standInById(again, 'NcTextField', 'down-payment-rate-21')).toBeUndefined()

		http.post.mockResolvedValue({ data: { invoiceNumber: 'DP-3' } })
		await view.byTestId('down-payment-submit').props.onClick()

		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post.mock.calls[0][0]).toBe(RAISE)
		expect(http.post.mock.calls[0][1]).toMatchObject({ administrationId: '7', customerId: 'c1', invoiceDate: '2026-06-20', percentage: 30 })
		expect(toast.showSuccess).toHaveBeenCalledWith('Draft down-payment invoice DP-3 created')
		expect(onClose).toHaveBeenCalledWith({ invoiceNumber: 'DP-3' })
		expect(view.vm.submitting).toBe(false)
	})

	it('asks the nets of a typed order per rate and writes each one', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal, { data: { order: { value: 'PO-1', label: 'PO-1', shillinq: false } } })
		const fields = view.standIns('NcTextField').filter((attrs) => String(attrs['data-testid']).startsWith('down-payment-rate-'))
		expect(fields.map((attrs) => attrs.label)).toEqual(['Net at 21%', 'Net at 9%', 'Net at 0%'])
		expect(fields.map((attrs) => attrs.inputmode)).toEqual(['decimal', 'decimal', 'decimal'])

		fields.forEach((attrs, index) => attrs['onUpdate:modelValue'](String(100 * (index + 1))))
		expect(view.vm.rates).toEqual({ 21: '100', 9: '200', 0: '300' })
		expect(view.find((vnode) => vnode.type === 'legend').map(vnodeText)).toEqual(['Order net amount per VAT rate'])
	})

	it('names a refused submit in the alert, the server way too', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal, {
			data: { customer: { value: 'c1' }, order: { value: 'o1', shillinq: true }, value: '10' },
		})
		http.post.mockRejectedValue(new Error('network down'))

		await view.byTestId('down-payment-submit').props.onClick()

		expect(view.vm.error).not.toBe('')
		const shown = await renderWithStandIns(DownPaymentInvoiceModal, { data: { error: view.vm.error, mode: 'amount', order: { value: 'PO-2', shillinq: false } }, slots: 'ssr' })
		expect(shown.html).toMatch(new RegExp(`role="alert" data-testid="down-payment-error"[^>]*>\\s*${view.vm.error}\\s*<`))
		expect(shown.html).toContain('Order net amount per VAT rate')
		expect(shown.html).toContain('The down payment is booked as an advance received.')
		expect(shown.html).toContain('Create draft')
		expect(standInById(shown, 'NcTextField', 'down-payment-value').label).toBe('Net amount (EUR)')
	})
})
