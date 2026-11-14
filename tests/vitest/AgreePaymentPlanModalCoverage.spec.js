/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AgreePaymentPlanModal.vue driven through its template: the invoices and
 * plan fields of step one, the drawn-up schedule of step two, the error line,
 * and the buttons, switches and fields wired to the real handlers. Only HTTP,
 * the toast, the event bus and the @nextcloud/vue components are replaced;
 * src/utils/paymentPlanApi.js and downPaymentApi.js run.
 *
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/specs/bookkeeping-credit-control-dunning/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

const { default: AgreePaymentPlanModal } = await import('../../src/modals/AgreePaymentPlanModal.vue')

const INVOICES = [
	{ id: 'i1', invoiceNumber: 'F-1', amountDue: 100, administrationId: 'adm-1' },
	{ id: 'i2', invoiceNumber: 'F-2', amountDue: 50.5, administrationId: 'adm-1' },
]

const DRAFT = {
	plan: { id: 'plan 1', planNumber: 'BR-7', totalAmount: 150.5 },
	instalments: [
		{ id: 'n1', dueDate: '2026-12-01', amount: 75.25 },
		{ id: 'n2', dueDate: '2027-01-01', amount: 75.25 },
	],
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

/**
 * The attributes of the text field with a label.
 *
 * @param {object} view The rendered view.
 * @param {string} label The label.
 * @return {object|undefined} Its attributes.
 */
function fieldByLabel(view, label) {
	return view.standIns('NcTextField').find((attrs) => attrs.label === label)
}

/**
 * The text a component vnode's default slot renders.
 *
 * @param {object} vnode A component vnode.
 * @return {string} The text.
 */
function slotText(vnode) {
	return [].concat(vnode.children.default()).map(vnodeText).join(' ').replace(/\s+/g, ' ').trim()
}

/**
 * The component vnodes of a stand-in name, in render order.
 *
 * @param {object} view The rendered view.
 * @param {string} name The component name.
 * @return {Array<object>} The vnodes.
 */
function componentVnodes(view, name) {
	return view.find((vnode) => vnode.type?.name === name)
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	bus.emit.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('AgreePaymentPlanModal step one', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal)
		expect(view.standIns('NcDialog')).toEqual([])
	})

	it('lists the invoices with their amounts and toggles them from their switches', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal, {
			props: { open: true },
			data: { loading: false, invoices: INVOICES, form: { ...AgreePaymentPlanModal.data().form, invoiceIds: ['i1'] } },
		})

		const first = standInById(view, 'NcCheckboxRadioSwitch', 'agree-plan-invoice-F-1')
		const second = standInById(view, 'NcCheckboxRadioSwitch', 'agree-plan-invoice-F-2')
		expect([first.modelValue, second.modelValue]).toEqual([true, false])
		expect(componentVnodes(view, 'NcCheckboxRadioSwitch').slice(0, 2).map(slotText)).toEqual(['F-1 · EUR 100.00', 'F-2 · EUR 50.50'])
		expect(view.html).toMatch(/<legend[^>]*>Invoices<\/legend>/)
		expect(view.byTestId('agree-plan-draw-up').props.disabled).toBe(false)

		second['onUpdate:modelValue'](true)
		first['onUpdate:modelValue'](false)
		expect(view.vm.form.invoiceIds).toEqual(['i2'])

		const none = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, data: { loading: false, invoices: INVOICES } })
		expect(none.byTestId('agree-plan-draw-up').props.disabled).toBe(true)
	})

	it('says when the customer has nothing overdue, but not while loading', async () => {
		const empty = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, data: { loading: false } })
		expect(empty.html).toContain('This customer has no overdue invoices.')
		const loading = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true } })
		expect(loading.html).not.toContain('This customer has no overdue invoices.')
		expect(loading.html).toMatch(/<legend[^>]*>Invoices<\/legend>/)
	})

	it('switches between a count and an amount per instalment, and shows collection costs only when included', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, data: { loading: false, invoices: INVOICES } })
		expect(standInById(view, 'NcTextField', 'agree-plan-count').modelValue).toBe(6)
		expect(standInById(view, 'NcTextField', 'agree-plan-amount')).toBeUndefined()
		expect(fieldByLabel(view, 'Collection costs')).toBeUndefined()

		const modes = view.standIns('NcCheckboxRadioSwitch').filter((attrs) => attrs.name === 'plan-mode')
		expect(modes.map((attrs) => attrs.value)).toEqual(['count', 'amount'])
		modes[1]['onUpdate:modelValue']('amount')
		standInById(view, 'NcTextField', 'agree-plan-count')['onUpdate:modelValue']('4')
		standInById(view, 'NcSelect', 'agree-plan-frequency')['onUpdate:modelValue']({ value: 'weekly', label: 'Weekly' })
		standInById(view, 'NcTextField', 'agree-plan-first-due')['onUpdate:modelValue']('2026-12-01')
		fieldByLabel(view, 'Grace days')['onUpdate:modelValue']('7')
		view.standIns('NcCheckboxRadioSwitch').find((attrs) => attrs.name === undefined && attrs['data-testid'] === undefined)['onUpdate:modelValue'](true)
		fieldByLabel(view, 'Agreed with')['onUpdate:modelValue']('Mr Jansen')
		fieldByLabel(view, 'Note')['onUpdate:modelValue']('By phone')

		const amount = await view.rerender()
		expect(standInById(amount, 'NcTextField', 'agree-plan-count')).toBeUndefined()
		standInById(amount, 'NcTextField', 'agree-plan-amount')['onUpdate:modelValue']('25')
		fieldByLabel(amount, 'Collection costs')['onUpdate:modelValue']('40')
		amount.standIns('NcCheckboxRadioSwitch').filter((attrs) => attrs.name === 'plan-mode')[0]['onUpdate:modelValue']('count')

		expect(view.vm.frequency).toEqual({ value: 'weekly', label: 'Weekly' })
		expect(amount.vm.form).toMatchObject({ mode: 'count', instalmentAmount: '25', chargesAmount: '40', includesCharges: true, firstDueDate: '2026-12-01', graceDays: '7', agreedWith: 'Mr Jansen', note: 'By phone' })
	})

	it('draws up the schedule from the fields, then shows it for activation', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal, {
			props: { open: true },
			data: { loading: false, invoices: INVOICES, customerId: 'c-1', frequency: { value: 'weekly' }, form: { ...AgreePaymentPlanModal.data().form, invoiceIds: ['i2'], firstDueDate: '2026-12-01' } },
		})
		http.post.mockResolvedValue({ data: DRAFT })

		await view.byTestId('agree-plan-draw-up').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans', {
			administrationId: 'adm-1',
			customerId: 'c-1',
			invoiceIds: ['i2'],
			instalmentCount: 6,
			instalmentAmount: null,
			frequency: 'weekly',
			firstDueDate: '2026-12-01',
			graceDays: 14,
			includesCharges: false,
			chargesAmount: 0,
			agreedWith: '',
			note: '',
		})
		const drawn = await view.rerender()
		expect(vnodeText(drawn.byTestId('agree-plan-summary'))).toBe('Plan BR-7: 2 instalments, EUR 150.50 in total.')
		expect(drawn.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual(['2026-12-01 · EUR 75.25', '2027-01-01 · EUR 75.25'])
		expect(drawn.html).toContain('Activating pauses reminders for these invoices and mails the schedule to the customer.')
		expect(drawn.byTestId('agree-plan-draw-up')).toBeUndefined()
		expect(drawn.byTestId('agree-plan-activate').props.disabled).toBe(false)
	})

	it('names a refused schedule in the error line', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, data: { loading: false, invoices: INVOICES, form: { ...AgreePaymentPlanModal.data().form, invoiceIds: ['i1'] } } })
		http.post.mockRejectedValueOnce({ response: { data: { message: 'Too many instalments' } } })

		await view.byTestId('agree-plan-draw-up').props.onClick()
		const failed = await view.rerender()

		expect(vnodeText(failed.byTestId('agree-plan-error'))).toBe('Too many instalments')
		expect([failed.vm.draft, failed.vm.submitting]).toEqual([null, false])
	})
})

describe('AgreePaymentPlanModal step two', () => {
	it('activates the plan, toasts, refreshes and closes with it', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true, onClose }, data: { draft: DRAFT } })
		http.post.mockResolvedValue({ data: { id: 'plan 1', planNumber: 'BR-7' } })

		await view.byTestId('agree-plan-activate').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans/plan%201/activate')
		expect(toast.showSuccess).toHaveBeenCalledWith('Payment plan BR-7 is active.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledWith({ id: 'plan 1', planNumber: 'BR-7' })
	})

	it('keeps the dialog open with the reason when activation fails, and disables it while busy', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true, onClose }, data: { draft: DRAFT } })
		http.post.mockRejectedValueOnce(new Error('down'))

		await view.byTestId('agree-plan-activate').props.onClick()

		expect(vnodeText((await view.rerender()).byTestId('agree-plan-error'))).toBe('The payment plan could not be activated.')
		expect(onClose).not.toHaveBeenCalled()
		const busy = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, data: { draft: DRAFT, submitting: true } })
		expect(busy.byTestId('agree-plan-activate').props.disabled).toBe(true)
	})

	it('closes from Cancel and from the dialog', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true, onClose }, data: { draft: DRAFT } })
		const cancel = componentVnodes(view, 'NcButton').find((vnode) => !vnode.props['data-testid'])
		expect(slotText(cancel)).toBe('Cancel')

		cancel.props.onClick()
		view.standIns('NcDialog')[0].onClosing()

		expect(onClose).toHaveBeenCalledTimes(2)
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('AgreePaymentPlanModal server-rendered', () => {
	it('renders step one with its fields, costs and error', async () => {
		const html = (await renderWithStandIns(AgreePaymentPlanModal, {
			slots: 'ssr',
			props: { open: true },
			data: { loading: false, invoices: INVOICES, error: 'Nope', form: { ...AgreePaymentPlanModal.data().form, mode: 'amount', includesCharges: true } },
		})).html
		expect(html).toMatch(/<legend[^>]*>Invoices<\/legend>/)
		expect(html).toMatch(/F-1 · EUR 100.00[\s\S]*F-2 · EUR 50.50/)
		expect(html).toContain('Number of instalments')
		expect(html).toContain('Include collection costs already charged')
		expect(html).toMatch(/data-testid="agree-plan-error"[^>]*>\s*Nope/)
		expect(html).toMatch(/Cancel[\s\S]*Draw up schedule/)
		expect(html).not.toContain('Activate plan')
	})

	it('renders the empty step one and the drawn-up schedule', async () => {
		const empty = (await renderWithStandIns(AgreePaymentPlanModal, { slots: 'ssr', props: { open: true }, data: { loading: false } })).html
		expect(empty).toContain('This customer has no overdue invoices.')
		expect(empty).not.toContain('agree-plan-error')

		const drawn = (await renderWithStandIns(AgreePaymentPlanModal, { slots: 'ssr', props: { open: true }, data: { draft: DRAFT } })).html
		expect(drawn).toContain('Plan BR-7: 2 instalments, EUR 150.50 in total.')
		expect(drawn).toMatch(/2026-12-01 · EUR 75.25[\s\S]*2027-01-01 · EUR 75.25/)
		expect(drawn).toMatch(/Cancel[\s\S]*Activate plan/)
		expect(drawn).not.toContain('Draw up schedule')
	})
})

describe('AgreePaymentPlanModal loading', () => {
	it('loads the plannable invoices of an invoice\'s customer and preselects that invoice', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-14T10:00:00Z'))
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true, from: 'invoice', objectId: 'i2' } })
		http.get
			.mockResolvedValueOnce({ data: { id: 'i2', customerId: 7 } })
			.mockResolvedValueOnce({
				data: {
					results: [
						{ id: 'i2', lifecycleState: 'issued', dueDate: '2026-10-01', amountDue: 20 },
						{ id: 'i1', lifecycleState: 'overdue', dueDate: '2026-09-01', amountDue: 10 },
						{ id: 'i3', lifecycleState: 'issued', dueDate: '2026-12-01', amountDue: 30 },
					],
				},
			})

		await AgreePaymentPlanModal.mounted.call(view.vm)

		expect(http.get.mock.calls).toEqual([
			['/index.php/apps/openregister/api/objects/shillinq/ARInvoice/i2'],
			['/index.php/apps/openregister/api/objects/shillinq/ARInvoice', { params: { customerId: '7', _limit: 500 } }],
		])
		expect([view.vm.customerId, view.vm.invoices.map((row) => row.id), view.vm.form.invoiceIds, view.vm.frequency.value, view.vm.loading]).toEqual(['7', ['i1', 'i2'], ['i2'], 'monthly', false])
	})

	it('names a failed load', async () => {
		const view = await renderWithStandIns(AgreePaymentPlanModal, { props: { open: true }, globals: { $route: { params: { id: 'c-3' } } } })
		http.get.mockRejectedValueOnce({ response: { data: { message: 'No access' } } })

		await AgreePaymentPlanModal.mounted.call(view.vm)

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/ARInvoice', expect.objectContaining({ params: expect.objectContaining({ customerId: 'c-3' }) }))
		expect([view.vm.error, view.vm.loading]).toEqual(['No access', false])
	})
})
