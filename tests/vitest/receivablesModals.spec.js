/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The receivables dialogs: Agree a payment plan (load, select, draw up,
 * activate), New down-payment invoice (load, options, submit) and the
 * recurring invoice profile (form, preview, load, validate, create, update).
 * Only HTTP and the Nextcloud globals are stubbed; the request builders in
 * src/utils run for real.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AgreePaymentPlanModal from '../../src/modals/AgreePaymentPlanModal.vue'
import DownPaymentInvoiceModal from '../../src/modals/DownPaymentInvoiceModal.vue'
import RecurringInvoiceProfileModal from '../../src/modals/RecurringInvoiceProfileModal.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcDialog: {},
	NcSelect: {},
	NcTextField: {},
}))

/**
 * A fake component instance: data, bound methods, live computed getters,
 * a recorded $emit and the given overrides.
 *
 * @param {object} Comp The component options.
 * @param {object} state Props and data overrides.
 * @return {object} The instance.
 */
function instance(Comp, state = {}) {
	const vm = { emitted: [] }
	vm.$emit = (...args) => vm.emitted.push(args)
	for (const [name, method] of Object.entries(Comp.methods || {})) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(Comp.computed || {})) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	Object.assign(vm, Comp.data ? Comp.data.call(vm) : {}, state)
	return vm
}

const httpError = (message) => Object.assign(new Error('x'), { response: { data: { message } } })

beforeEach(() => {
	for (const fn of [axios.get, axios.post, axios.put, axios.patch, showSuccess, showError, emit]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('AgreePaymentPlanModal', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-06-15T10:00:00Z'))
	})

	it('takes the id from the prop, else the route, else empty', () => {
		expect(instance(AgreePaymentPlanModal, { objectId: 'c-1', $route: { params: { id: 'r' } } }).effectiveId).toBe('c-1')
		expect(instance(AgreePaymentPlanModal, { $route: { params: { id: 'r-9' } } }).effectiveId).toBe('r-9')
		expect(instance(AgreePaymentPlanModal).effectiveId).toBe('')
		expect(instance(AgreePaymentPlanModal).frequencyOptions.map((o) => o.value)).toEqual(['monthly', 'weekly'])
	})

	it('loads the customer\'s overdue invoices, oldest first, all selected', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 'i-2', lifecycleState: 'overdue', dueDate: '2026-05-01', amountDue: 50 },
					{ id: 'i-paid', lifecycleState: 'overdue', dueDate: '2026-01-01', amountDue: 0 },
					{ id: 'i-1', lifecycleState: 'issued', dueDate: '2026-04-01', amountDue: 20 },
					{ id: 'i-future', lifecycleState: 'issued', dueDate: '2026-07-01', amountDue: 20 },
				],
			},
		})
		const vm = instance(AgreePaymentPlanModal, { objectId: 'c-1' })
		await AgreePaymentPlanModal.mounted.call(vm)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/ARInvoice',
			{ params: { customerId: 'c-1', _limit: 500 } },
		)
		expect(vm.frequency).toEqual({ value: 'monthly', label: 'Monthly' })
		expect(vm.customerId).toBe('c-1')
		expect(vm.invoices.map((i) => i.id)).toEqual(['i-1', 'i-2'])
		expect(vm.form.invoiceIds).toEqual(['i-1', 'i-2'])
		expect(vm.loading).toBe(false)
	})

	it('opened from an invoice, plans for its customer with only that invoice selected', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { customerId: 77 } })
			.mockResolvedValueOnce({ data: [{ id: 'inv-1', lifecycleState: 'overdue', amountDue: 5 }, { id: 'inv-2', lifecycleState: 'overdue', amountDue: 5 }] })
		const vm = instance(AgreePaymentPlanModal, { from: 'invoice', objectId: 'inv-1' })
		await AgreePaymentPlanModal.mounted.call(vm)
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/shillinq/ARInvoice/inv-1')
		expect(axios.get.mock.calls[1][1]).toEqual({ params: { customerId: '77', _limit: 500 } })
		expect(vm.customerId).toBe('77')
		expect(vm.form.invoiceIds).toEqual(['inv-1'])
	})

	it('shows the load error and stops loading', async () => {
		axios.get.mockRejectedValueOnce(httpError('nope'))
		const vm = instance(AgreePaymentPlanModal, { objectId: 'c-1' })
		await AgreePaymentPlanModal.mounted.call(vm)
		expect(vm.error).toBe('nope')
		expect(vm.loading).toBe(false)
		axios.get.mockRejectedValueOnce(new Error('down'))
		const other = instance(AgreePaymentPlanModal, { objectId: 'c-1' })
		await AgreePaymentPlanModal.mounted.call(other)
		expect(other.error).toBe('The invoices could not be loaded.')
	})

	it('formats money and toggles a selection on and off', () => {
		const vm = instance(AgreePaymentPlanModal)
		expect(vm.money(12.5)).toBe('EUR 12.50')
		expect(vm.money(null)).toBe('EUR 0.00')
		vm.form.invoiceIds = ['a', 'b']
		vm.toggle('a')
		expect(vm.form.invoiceIds).toEqual(['b'])
		vm.toggle('c')
		expect(vm.form.invoiceIds).toEqual(['b', 'c'])
	})

	it('draws up the schedule with the first selected invoice\'s administration', async () => {
		const vm = instance(AgreePaymentPlanModal, { customerId: 'c-1', error: 'old' })
		vm.invoices = [{ id: 'x', administrationId: 'adm-x' }, { id: 'y', administrationId: 'adm-y' }]
		vm.form.invoiceIds = ['y']
		vm.form.firstDueDate = '2026-07-01'
		vm.frequency = { value: 'weekly' }
		axios.post.mockImplementationOnce(async () => {
			expect(vm.submitting).toBe(true)
			expect(vm.error).toBe('')
			return { data: { plan: { id: 'pp-1' } } }
		})
		await vm.drawUp()
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/v1/payment-plans')
		expect(body).toMatchObject({
			administrationId: 'adm-y',
			customerId: 'c-1',
			invoiceIds: ['y'],
			instalmentCount: 6,
			instalmentAmount: null,
			frequency: 'weekly',
			firstDueDate: '2026-07-01',
			graceDays: 14,
			chargesAmount: 0,
		})
		expect(vm.draft).toEqual({ plan: { id: 'pp-1' } })
		expect(vm.submitting).toBe(false)
	})

	it('falls back to monthly and no administration, and reports a refused draft', async () => {
		const vm = instance(AgreePaymentPlanModal, { customerId: 'c-1' })
		axios.post.mockRejectedValueOnce(new Error('boom'))
		await vm.drawUp()
		expect(axios.post.mock.calls[0][1]).toMatchObject({ frequency: 'monthly', administrationId: '' })
		expect(vm.error).toBe('The schedule could not be drawn up.')
		expect(vm.draft).toBe(null)
		expect(vm.submitting).toBe(false)
	})

	it('activates the drawn-up plan, refreshes the page and closes with it', async () => {
		const vm = instance(AgreePaymentPlanModal, { draft: { plan: { id: 'pp 1' } } })
		axios.post.mockResolvedValueOnce({ data: { id: 'pp 1', planNumber: 'PP-7' } })
		await vm.activate()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans/pp%201/activate')
		expect(showSuccess).toHaveBeenCalledWith('Payment plan PP-7 is active.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(vm.emitted).toEqual([['close', { id: 'pp 1', planNumber: 'PP-7' }]])
		expect(vm.submitting).toBe(false)
	})

	it('keeps the dialog open when activation is refused, and closes empty', async () => {
		const vm = instance(AgreePaymentPlanModal, { draft: { plan: { id: 'pp-1' } } })
		axios.post.mockRejectedValueOnce(httpError('Plan already active'))
		await vm.activate()
		expect(vm.error).toBe('Plan already active')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(emit).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([])
		vm.onClose()
		expect(vm.emitted).toEqual([['close']])
	})
})

describe('DownPaymentInvoiceModal', () => {
	it('loads the customers and sales orders of the active administration', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { activeAdministrationId: 42 } })
			.mockResolvedValueOnce({ data: { results: [{ id: 'c-1', legalName: 'Acme' }] } })
			.mockResolvedValueOnce({ data: { objects: [{ id: 'o-1', orderNumber: 'SO-1' }] } })
		const vm = instance(DownPaymentInvoiceModal)
		await DownPaymentInvoiceModal.mounted.call(vm)
		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/administrations/context')
		expect(axios.get.mock.calls[1]).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/CustomerMaster',
			{ params: { administrationId: '42', _limit: 500 } },
		])
		expect(axios.get.mock.calls[2]).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/OrderPrimitive',
			{ params: { administrationId: '42', _limit: 500, orderType: 'sales' } },
		])
		expect(vm.administrationId).toBe('42')
		expect(vm.customers).toEqual([{ id: 'c-1', legalName: 'Acme' }])
		expect(vm.orders).toEqual([{ id: 'o-1', orderNumber: 'SO-1' }])
		expect(vm.loading).toBe(false)
	})

	it('treats a non-list answer as no rows and reports a failed load', async () => {
		axios.get
			.mockResolvedValueOnce({ data: {} })
			.mockResolvedValueOnce({ data: { results: 'x' } })
			.mockResolvedValueOnce({ data: [{ id: 'o' }] })
		const vm = instance(DownPaymentInvoiceModal)
		await DownPaymentInvoiceModal.mounted.call(vm)
		expect(vm.administrationId).toBe('')
		expect(vm.customers).toEqual([])
		expect(vm.orders).toEqual([{ id: 'o' }])

		axios.get.mockRejectedValueOnce(new Error('down'))
		const failed = instance(DownPaymentInvoiceModal)
		await DownPaymentInvoiceModal.mounted.call(failed)
		expect(failed.error).toBe('The customers and orders could not be loaded.')
		expect(failed.loading).toBe(false)
	})

	it('labels customers and orders by their best name', () => {
		const vm = instance(DownPaymentInvoiceModal, {
			customers: [
				{ id: 1, legalName: 'Legal' },
				{ '@self': { id: 'self-2' }, tradeName: 'Trade' },
				{ id: 'c-3', customerId: 'CUST-3' },
				{ id: 'c-4' },
			],
			orders: [
				{ id: 'o-1', orderNumber: 'SO-1', description: 'd' },
				{ '@self': { id: 'o-2' }, description: 'Second' },
				{ id: 'o-3' },
			],
		})
		expect(vm.customerOptions).toEqual([
			{ value: '1', label: 'Legal' },
			{ value: 'self-2', label: 'Trade' },
			{ value: 'c-3', label: 'CUST-3' },
			{ value: 'c-4', label: 'c-4' },
		])
		expect(vm.orderOptions).toEqual([
			{ value: 'o-1', label: 'SO-1', shillinq: true },
			{ value: 'o-2', label: 'Second', shillinq: true },
			{ value: 'o-3', label: 'o-3', shillinq: true },
		])
		expect(vm.typedOrder('  PO-9 ')).toEqual({ value: 'PO-9', label: 'PO-9', shillinq: false })
	})

	it('allows a submit only with a customer, an order and a positive share', () => {
		const ready = { customer: { value: 'c' }, order: { value: 'o' } }
		expect(instance(DownPaymentInvoiceModal, { ...ready, value: '12,5' }).canSubmit).toBe(true)
		expect(instance(DownPaymentInvoiceModal, { ...ready, value: '0' }).canSubmit).toBe(false)
		expect(instance(DownPaymentInvoiceModal, { ...ready, value: 'abc' }).canSubmit).toBe(false)
		expect(instance(DownPaymentInvoiceModal, { ...ready, value: '' }).canSubmit).toBe(false)
		expect(instance(DownPaymentInvoiceModal, { order: { value: 'o' }, value: '10' }).canSubmit).toBe(false)
		expect(instance(DownPaymentInvoiceModal, { customer: { value: 'c' }, value: '10' }).canSubmit).toBe(false)
	})

	it('raises the down payment for a held order and closes with the invoice', async () => {
		const vm = instance(DownPaymentInvoiceModal, {
			administrationId: 'adm',
			customer: { value: 'c-1' },
			order: { value: 'o-1', label: 'SO-1', shillinq: true },
			value: '30',
			invoiceDate: '2026-03-01',
		})
		axios.post.mockResolvedValueOnce({ data: { invoiceNumber: 'DP-1' } })
		await vm.submit()
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/ar-invoices/down-payments')
		expect(body).toMatchObject({ administrationId: 'adm', customerId: 'c-1', percentage: 30, invoiceDate: '2026-03-01' })
		expect(body).not.toHaveProperty('orderVatBreakdown')
		expect(showSuccess).toHaveBeenCalledWith('Draft down-payment invoice DP-1 created')
		expect(vm.emitted).toEqual([['close', { invoiceNumber: 'DP-1' }]])
		expect(vm.submitting).toBe(false)
	})

	it('sends an amount for an outside order, and shows a refusal', async () => {
		const vm = instance(DownPaymentInvoiceModal, {
			customer: { value: 'c-1' },
			order: { value: 'PO-9', label: 'PO-9', shillinq: false },
			mode: 'amount',
			value: '100',
		})
		axios.post.mockRejectedValueOnce(httpError('Order already invoiced'))
		await vm.submit()
		const body = axios.post.mock.calls[0][1]
		expect(body.amount).toBe(100)
		expect(body).not.toHaveProperty('percentage')
		expect(body).toHaveProperty('orderVatBreakdown')
		expect(vm.error).toBe('Order already invoiced')
		expect(vm.emitted).toEqual([])
		vm.close()
		expect(vm.emitted).toEqual([['close', null]])
	})
})

describe('RecurringInvoiceProfileModal', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-03-10T12:00:00Z'))
	})

	it('starts with a blank monthly draft-for-review form and one line', () => {
		const vm = instance(RecurringInvoiceProfileModal)
		expect(vm.form).toMatchObject({ frequency: 'monthly', interval: 1, invoiceDay: 1, startDate: '2026-03-10', issueMode: 'draft-for-review', currency: 'EUR' })
		expect(vm.form.lines).toHaveLength(1)
		expect(vm.frequencyOption.value).toBe('monthly')
		expect(vm.issueModeOption.value).toBe('draft-for-review')
	})

	it('resolves the selected options with fallbacks', () => {
		const vm = instance(RecurringInvoiceProfileModal)
		vm.form.frequency = 'quarterly'
		vm.form.issueMode = 'auto-issue'
		expect(vm.frequencyOption.value).toBe('quarterly')
		expect(vm.issueModeOption.value).toBe('auto-issue')
		vm.form.frequency = 'daily'
		vm.form.issueMode = 'other'
		expect(vm.frequencyOption.value).toBe('monthly')
		expect(vm.issueModeOption.value).toBe('draft-for-review')
		expect(vm.vatOptionFor({ vatCode: '9' }).value).toBe(9)
		expect(vm.vatOptionFor({ vatCode: 6 }).value).toBe(21)
	})

	it('updates fields and lines from the selects', () => {
		const vm = instance(RecurringInvoiceProfileModal)
		vm.onSelect('frequency', { value: 'weekly' })
		expect(vm.form.frequency).toBe('weekly')
		vm.onSelect('frequency', null)
		expect(vm.form.frequency).toBe('weekly')
		const line = { vatCode: 21 }
		vm.onVatSelected(line, { value: '9' })
		expect(line.vatCode).toBe(9)
		vm.onVatSelected(line, null)
		expect(line.vatCode).toBe(21)
	})

	it('adds lines and never removes the last one', () => {
		const vm = instance(RecurringInvoiceProfileModal)
		vm.removeLine(0)
		expect(vm.form.lines).toHaveLength(1)
		vm.addLine()
		vm.form.lines[1].description = 'second'
		expect(vm.form.lines).toHaveLength(2)
		vm.removeLine(0)
		expect(vm.form.lines.map((l) => l.description)).toEqual(['second'])
	})

	it('sums the net per period and previews the line texts for this month', () => {
		const vm = instance(RecurringInvoiceProfileModal)
		vm.form.lines = [
			{ description: 'Hosting {period}', quantity: 2, unitPrice: 10.005 },
			{ description: '  ', quantity: 1, unitPrice: 5 },
			{ description: 'Fee {month}/{year}', quantity: 1, unitPrice: 0 },
		]
		expect(vm.perPeriodNetAmount).toBe(25.01)
		expect(vm.previewDescriptions).toEqual(['Hosting March 2026', 'Fee March/2026'])
		expect(vm.formatEuro(1234.5).replace(/\s/g, ' ')).toBe('€ 1.234,50')
		expect(vm.formatEuro('x').replace(/\s/g, ' ')).toBe('€ 0,00')
	})

	it('resets the form when opened and loads the record being edited', async () => {
		axios.get.mockResolvedValueOnce({ data: { object: { name: 'Hosting', lines: [] } } })
		const vm = instance(RecurringInvoiceProfileModal, { recordId: 'rp-1', errors: ['old'] })
		vm.form.name = 'stale'
		RecurringInvoiceProfileModal.watch.open.call(vm, true)
		expect(vm.errors).toEqual([])
		await vi.waitFor(() => expect(vm.form.name).toBe('Hosting'))
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/RecurringInvoiceProfile/rp-1')
		expect(vm.form.lines).toHaveLength(1)
		expect(vm.form.frequency).toBe('monthly')

		const fresh = instance(RecurringInvoiceProfileModal, { errors: ['x'] })
		fresh.form.name = 'stale'
		RecurringInvoiceProfileModal.watch.open.call(fresh, false)
		expect(fresh.form.name).toBe('stale')
		RecurringInvoiceProfileModal.watch.open.call(fresh, true)
		expect(fresh.form.name).toBe('')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('keeps the loaded lines and reports a failed load', async () => {
		axios.get.mockResolvedValueOnce({ data: { name: 'Raw', lines: [{ description: 'L' }] } })
		const vm = instance(RecurringInvoiceProfileModal, { recordId: 'rp-1' })
		await vm.fetchProfile()
		expect(vm.form.name).toBe('Raw')
		expect(vm.form.lines).toEqual([{ description: 'L' }])
		axios.get.mockRejectedValueOnce(new Error('down'))
		await vm.fetchProfile()
		expect(showError).toHaveBeenCalledWith('Failed to load recurring profile.')
		expect(vm.form.name).toBe('Raw')
	})

	it('refuses an incomplete profile without saving', async () => {
		const vm = instance(RecurringInvoiceProfileModal)
		await vm.onSave()
		expect(vm.errors).toEqual([
			'A profile name is required.',
			'A customer is required.',
			'At least one line with a description and a price is required.',
		])
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([])
	})

	/**
	 * A valid form on the given instance.
	 *
	 * @param {object} vm The instance.
	 */
	function fill(vm) {
		vm.form.name = ' Hosting '
		vm.form.customerReference = 'c-1'
		vm.form.lines = [{ description: 'Hosting', quantity: 1, unitPrice: 10, vatCode: 21 }]
	}

	it('creates a new profile and closes', async () => {
		const vm = instance(RecurringInvoiceProfileModal)
		fill(vm)
		axios.post.mockResolvedValueOnce({ data: {} })
		await vm.onSave()
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/shillinq/RecurringInvoiceProfile')
		expect(body).toMatchObject({ name: 'Hosting', customerReference: 'c-1', status: 'draft' })
		expect(showSuccess).toHaveBeenCalledWith('Recurring profile created.')
		expect(vm.emitted).toEqual([['saved'], ['close']])
		expect(vm.saving).toBe(false)
	})

	it('updates an existing profile', async () => {
		const vm = instance(RecurringInvoiceProfileModal, { recordId: 'rp-1' })
		fill(vm)
		axios.put.mockResolvedValueOnce({ data: {} })
		await vm.onSave()
		expect(axios.put.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/shillinq/RecurringInvoiceProfile/rp-1')
		expect(axios.post).not.toHaveBeenCalled()
		expect(showSuccess).toHaveBeenCalledWith('Recurring profile updated.')
	})

	it('shows the server error, else the error message, else the fallback', async () => {
		const cases = [
			[Object.assign(new Error('m'), { response: { data: { error: 'Server says no' } } }), 'Server says no'],
			[new Error('Network down'), 'Network down'],
			[{}, 'Failed to save recurring profile.'],
		]
		for (const [failure, message] of cases) {
			showError.mockReset()
			const vm = instance(RecurringInvoiceProfileModal)
			fill(vm)
			axios.post.mockRejectedValueOnce(failure)
			await vm.onSave()
			expect(vm.errors).toEqual([message])
			expect(showError).toHaveBeenCalledWith(message)
			expect(vm.emitted).toEqual([])
			expect(vm.saving).toBe(false)
		}
	})

	it('does not close while saving', () => {
		const vm = instance(RecurringInvoiceProfileModal, { saving: true })
		vm.onClose()
		expect(vm.emitted).toEqual([])
		vm.saving = false
		vm.onClose()
		expect(vm.emitted).toEqual([['close']])
	})
})
