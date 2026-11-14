/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Four modals driven through their templates: the payment-plan action modal
 * (its three modes, title and plan id), the refund-paid modal (its account
 * options and load), the down-payment modal (the rate fields of a typed
 * order, the value label, its load and submit) and the VAT-return modal (its
 * period fields, its result and the close). Only HTTP, the toast, the event
 * bus and the @nextcloud/vue components are replaced; the API helpers run.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
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

const { default: PaymentPlanActionModal } = await import('../../src/modals/PaymentPlanActionModal.vue')
const { default: RefundPaidModal } = await import('../../src/modals/RefundPaidModal.vue')
const { default: DownPaymentInvoiceModal } = await import('../../src/modals/DownPaymentInvoiceModal.vue')
const { default: PrepareVatReturnModal } = await import('../../src/modals/PrepareVatReturnModal.vue')

/**
 * The first button stand-in without a test id: the dialog's close or cancel.
 *
 * @param {object} view The rendered view.
 * @return {object} Its attributes.
 */
function plainButton(view) {
	return view.standIns('NcButton').find((attrs) => attrs['data-testid'] === undefined)
}

/**
 * Fire a listener that may arrive as one function or as an array of them.
 *
 * @param {Function|Array<Function>} listener The listener(s).
 * @param {*} value The emitted value.
 */
function fire(listener, value) {
	for (const handler of [].concat(listener)) {
		handler(value)
	}
}

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
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	bus.emit.mockReset()
})

describe('PaymentPlanActionModal template', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(PaymentPlanActionModal, { props: { open: false } })
		expect(view.standIns('NcDialog')).toHaveLength(0)
	})

	it.each([
		['activate', 'Activate plan', 'Reminders for the invoices on this plan pause'],
		['settle', 'Record a payment', null],
		['cancel', 'Cancel plan', 'Reminders for the invoices on this plan resume.'],
	])('titles the %s mode and shows its fields', async (mode, title, text) => {
		const view = await renderWithStandIns(PaymentPlanActionModal, { props: { open: true, mode, objectId: 'p1' } })
		expect(view.standIns('NcDialog')[0].name).toBe(title)
		const fields = view.standIns('NcTextField').map((attrs) => attrs['data-testid'])
		if (mode === 'settle') {
			expect(fields).toEqual(['payment-plan-amount', 'payment-plan-paid-date', undefined])
		} else if (mode === 'cancel') {
			expect(fields).toEqual(['payment-plan-cancel-reason'])
		} else {
			expect(fields).toEqual([])
		}
		if (text) {
			expect(view.html).toContain(text)
		}
	})

	it('writes the settle fields through their v-models and posts them', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PaymentPlanActionModal, { props: { open: true, mode: 'settle', objectId: 'p 1', onClose } })
		standInById(view, 'NcTextField', 'payment-plan-amount')['onUpdate:modelValue']('120.50')
		standInById(view, 'NcTextField', 'payment-plan-paid-date')['onUpdate:modelValue']('2026-11-01')
		view.standIns('NcTextField')[2]['onUpdate:modelValue']('REF-9')
		http.post.mockResolvedValue({ data: { id: 'p 1', state: 'settled' } })

		await view.byTestId('payment-plan-action-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans/p%201/settle', { amount: 120.5, paidDate: '2026-11-01', reference: 'REF-9' })
		expect(toast.showSuccess).toHaveBeenCalledWith('The payment is recorded.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledWith({ id: 'p 1', state: 'settled' })
	})

	it('cancels with the typed reason, taking the id from the route', async () => {
		const view = await renderWithStandIns(PaymentPlanActionModal, {
			props: { open: true, mode: 'cancel' },
			globals: { $route: { params: { id: 'route-7' } } },
		})
		standInById(view, 'NcTextField', 'payment-plan-cancel-reason')['onUpdate:modelValue']('Paid in full')
		http.post.mockResolvedValue({ data: { id: 'route-7' } })

		await view.byTestId('payment-plan-action-submit').props.onClick()

		expect(view.vm.effectiveId).toBe('route-7')
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans/route-7/cancel', { reason: 'Paid in full' })
		expect(toast.showSuccess).toHaveBeenCalledWith('The plan is cancelled.')
	})

	it('shows a refused action as an alert and keeps the dialog open', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PaymentPlanActionModal, { props: { open: true, mode: 'activate', objectId: 'p1', onClose } })
		http.post.mockRejectedValue({ response: { data: { message: 'Plan has no instalments' } } })

		await view.byTestId('payment-plan-action-submit').props.onClick()
		const again = await view.rerender()

		expect(onClose).not.toHaveBeenCalled()
		expect(vnodeText(again.byTestId('payment-plan-action-error'))).toBe('Plan has no instalments')
	})

	it('closes without a payload from the close button and the dialog', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PaymentPlanActionModal, { props: { open: true, mode: 'activate', onClose } })
		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[], []])
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('RefundPaidModal template', () => {
	const request = { id: 'pr-1', administrationId: 'adm-1' }

	it('offers only accounts with a number, labelled number and name', async () => {
		const view = await renderWithStandIns(RefundPaidModal, {
			props: { paymentRequest: request },
			data: { accounts: [{ accountNumber: 1100, name: 'Bank' }, { name: 'No number' }, null, { accountNumber: '1200' }] },
		})
		expect(standInById(view, 'NcSelect', 'refund-paid-account').options).toEqual([
			{ value: '1100', label: '1100 Bank' },
			{ value: '1200', label: '1200' },
		])
	})

	it('loads the ledger accounts of the request administration', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request } })
		http.get.mockResolvedValue({ data: { objects: [{ accountNumber: '1100' }] } })

		await view.vm.loadAccounts()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Account', { params: { administrationId: 'adm-1', _limit: 1000 } })
		expect(view.vm.accounts).toEqual([{ accountNumber: '1100' }])
		expect(view.vm.loadingAccounts).toBe(false)
	})

	it('names a failed account load', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request } })
		http.get.mockRejectedValue({ response: { data: { error: 'No access' } } })

		await view.vm.loadAccounts()
		const again = await view.rerender()

		expect(vnodeText(again.byTestId('refund-paid-error'))).toBe('No access')
		expect(view.vm.accounts).toEqual([])
	})

	it('posts the typed reference and chosen account from the form', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request, onClose } })
		standInById(view, 'NcTextField', 'refund-paid-reference')['onUpdate:modelValue']('BANK-1')
		standInById(view, 'NcSelect', 'refund-paid-account')['onUpdate:modelValue']({ value: '1100' })
		http.post.mockResolvedValue({ data: { id: 'pr-1' } })

		await view.byTestId('refund-paid-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/payment-requests/pr-1/refund/paid', { bankReference: 'BANK-1', bankAccount: '1100' })
		expect(onClose).toHaveBeenCalledWith({ id: 'pr-1' })
	})

	it('closes without a payload from cancel and the dialog', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request, onClose } })
		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[], []])
	})
})

describe('DownPaymentInvoiceModal template', () => {
	it('asks for the net per VAT rate only for an order typed by hand', async () => {
		const typed = await renderWithStandIns(DownPaymentInvoiceModal, { data: { order: { value: 'PO-1', label: 'PO-1', shillinq: false } } })
		expect(typed.standIns('NcTextField').map((attrs) => attrs['data-testid']).slice(0, 3)).toEqual(['down-payment-rate-21', 'down-payment-rate-9', 'down-payment-rate-0'])
		expect(standInById(typed, 'NcTextField', 'down-payment-rate-9').label).toBe('Net at 9%')

		const known = await renderWithStandIns(DownPaymentInvoiceModal, { data: { order: { value: 'o1', label: 'SO-1', shillinq: true } } })
		expect(standInById(known, 'NcTextField', 'down-payment-rate-21')).toBeUndefined()
	})

	it('labels the value by mode and lists customers and orders as options', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal, {
			data: {
				mode: 'amount',
				customers: [{ id: 'c1', legalName: 'Acme' }, { '@self': { id: 'c2' }, tradeName: 'Beta' }],
				orders: [{ id: 'o1', orderNumber: 'SO-1' }, { id: 'o2', description: 'Second' }],
			},
		})
		expect(standInById(view, 'NcTextField', 'down-payment-value').label).toBe('Net amount (EUR)')
		expect(standInById(view, 'NcSelect', 'down-payment-customer').options).toEqual([
			{ value: 'c1', label: 'Acme' },
			{ value: 'c2', label: 'Beta' },
		])
		expect(standInById(view, 'NcSelect', 'down-payment-order').options).toEqual([
			{ value: 'o1', label: 'SO-1', shillinq: true },
			{ value: 'o2', label: 'Second', shillinq: true },
		])
		expect(standInById(view, 'NcSelect', 'down-payment-order').createOption(' PO 7 ')).toEqual({ value: 'PO 7', label: 'PO 7', shillinq: false })

		const percent = await renderWithStandIns(DownPaymentInvoiceModal)
		expect(standInById(percent, 'NcTextField', 'down-payment-value').label).toBe('Percentage')
	})

	it('enables the submit only with a customer, an order and a positive value', async () => {
		const empty = await renderWithStandIns(DownPaymentInvoiceModal)
		expect(empty.byTestId('down-payment-submit').props.disabled).toBe(true)
		const ready = await renderWithStandIns(DownPaymentInvoiceModal, { data: { customer: { value: 'c1' }, order: { value: 'o1', shillinq: true }, value: '30' } })
		expect(ready.byTestId('down-payment-submit').props.disabled).toBe(false)
	})

	it('loads the administration, then its customers and sales orders', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal)
		http.get
			.mockResolvedValueOnce({ data: { activeAdministrationId: 7 } })
			.mockResolvedValueOnce({ data: { results: [{ id: 'c1' }] } })
			.mockResolvedValueOnce({ data: [{ id: 'o1' }] })

		await DownPaymentInvoiceModal.mounted.call(view.vm)

		expect(http.get.mock.calls).toEqual([
			['/index.php/apps/shillinq/api/administrations/context'],
			['/index.php/apps/openregister/api/objects/shillinq/CustomerMaster', { params: { administrationId: '7', _limit: 500 } }],
			['/index.php/apps/openregister/api/objects/shillinq/OrderPrimitive', { params: { administrationId: '7', _limit: 500, orderType: 'sales' } }],
		])
		expect(view.vm.customers).toEqual([{ id: 'c1' }])
		expect(view.vm.orders).toEqual([{ id: 'o1' }])
		expect(view.vm.loading).toBe(false)
	})

	it('names a failed load', async () => {
		const view = await renderWithStandIns(DownPaymentInvoiceModal)
		http.get.mockRejectedValue(new Error('down'))
		await DownPaymentInvoiceModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The customers and orders could not be loaded.')
	})

	it('creates the draft for a typed order with its rate breakdown and closes with it', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(DownPaymentInvoiceModal, {
			props: { onClose },
			data: { administrationId: '7', customer: { value: 'c1' }, order: { value: 'PO-1', label: 'PO-1', shillinq: false }, value: '1000', invoiceDate: '2026-11-13' },
		})
		standInById(view, 'NcTextField', 'down-payment-rate-21')['onUpdate:modelValue']('5000')
		view.standIns('NcCheckboxRadioSwitch')[1]['onUpdate:modelValue']('amount')
		http.post.mockResolvedValue({ data: { invoiceNumber: 'INV-1' } })

		await view.byTestId('down-payment-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/down-payments', {
			administrationId: '7',
			customerId: 'c1',
			orderReference: 'PO-1',
			orderLabel: 'PO-1',
			amount: 1000,
			invoiceDate: '2026-11-13',
			orderVatBreakdown: [{ rate: 0.21, net: 5000 }],
		})
		expect(toast.showSuccess).toHaveBeenCalledWith('Draft down-payment invoice INV-1 created')
		expect(onClose).toHaveBeenCalledWith({ invoiceNumber: 'INV-1' })
	})

	it('shows a refused draft and closes with null on cancel', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(DownPaymentInvoiceModal, {
			props: { onClose },
			data: { customer: { value: 'c1' }, order: { value: 'o1', shillinq: true }, value: '10' },
		})
		http.post.mockRejectedValue({ response: { data: { message: 'Order closed' } } })

		await view.vm.submit()
		const again = await view.rerender()
		expect(vnodeText(again.byTestId('down-payment-error'))).toBe('Order closed')
		expect(onClose).not.toHaveBeenCalled()

		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[null], [null]])
	})
})

describe('PrepareVatReturnModal template', () => {
	it('asks for a quarter number by default and none for a year', async () => {
		const quarter = await renderWithStandIns(PrepareVatReturnModal)
		expect(standInById(quarter, 'NcTextField', 'prepare-number').label).toBe('Quarter (1 to 4)')
		expect(standInById(quarter, 'NcSelect', 'prepare-period-kind').reduce({ id: 'month' })).toBe('month')
		expect(standInById(quarter, 'NcSelect', 'prepare-regime').reduce({ id: 'kor' })).toBe('kor')

		const month = await renderWithStandIns(PrepareVatReturnModal, { data: { period: 'month' } })
		expect(standInById(month, 'NcTextField', 'prepare-number').label).toBe('Month (1 to 12)')

		const year = await renderWithStandIns(PrepareVatReturnModal, { data: { period: 'year' } })
		expect(standInById(year, 'NcTextField', 'prepare-number')).toBeUndefined()
	})

	it('moves the period to the last finished one when the kind changes', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal)
		const kind = standInById(view, 'NcSelect', 'prepare-period-kind')
		fire(kind['onUpdate:modelValue'], 'year')
		expect(view.vm.period).toBe('year')
		expect(Number(view.vm.periodYear)).toBe(new Date().getFullYear() - 1)
	})

	it('prepares the return from the fields and shows its totals and link', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal, { data: { administrationId: 'adm-1', periodYear: '2026', periodNumber: '3' } })
		expect(view.byTestId('prepare-submit').props.disabled).toBe(false)
		standInById(view, 'NcSelect', 'prepare-regime')['onUpdate:modelValue']('kor')
		standInById(view, 'NcTextField', 'prepare-year')['onUpdate:modelValue']('2025')
		standInById(view, 'NcTextField', 'prepare-number')['onUpdate:modelValue']('2')
		http.post.mockResolvedValue({ data: { data: { id: 'r 1', returnNumber: 'BTW-25-Q2', totalVATCollected: '210', totalVATPaid: 42.5 } } })

		await view.byTestId('prepare-submit').props.onClick()
		const done = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-returns', { administrationId: 'adm-1', period: 'quarter', periodYear: 2025, periodNumber: 2, regime: 'kor' })
		expect(done.html).toContain('Draft return BTW-25-Q2: BTW collected EUR 210.00, input tax EUR 42.50.')
		expect(done.byTestId('prepare-open-return').props.href).toBe('/index.php/apps/shillinq/vat-returns/r%201')
		expect(done.byTestId('prepare-submit')).toBeUndefined()
		expect(done.standIns('NcButton')).toHaveLength(1)
	})

	it('keeps the submit off without an administration and names the load outcome', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal)
		expect(view.byTestId('prepare-submit').props.disabled).toBe(true)

		http.get.mockResolvedValueOnce({ data: {} })
		await PrepareVatReturnModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('Choose an administration first.')

		http.get.mockRejectedValueOnce(new Error('down'))
		await PrepareVatReturnModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The administration could not be loaded.')

		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 4 } })
		view.vm.error = ''
		await PrepareVatReturnModal.mounted.call(view.vm)
		expect(view.vm.administrationId).toBe('4')
		expect(view.vm.error).toBe('')
	})

	it('shows a refused period and closes with the result', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PrepareVatReturnModal, { props: { onClose }, data: { administrationId: 'a' } })
		http.post.mockRejectedValue({ response: { status: 400, data: { error: 'period in the future' } } })

		await view.vm.submit()
		const again = await view.rerender()
		expect(vnodeText(again.byTestId('prepare-error'))).toBe('That period has not ended yet. Prepare a return for a period that is over.')

		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[null], [null]])
	})
})
