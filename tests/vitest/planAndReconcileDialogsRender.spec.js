/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Two dialogs rendered server-side (no DOM), with the parts of their
 * behaviour other specs leave out: Agree a payment plan in its form and its
 * schedule step, and the payment-run reconcile dialog with its file, its
 * result, its run id and closing.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => (key in vars ? String(vars[key]) : m)) }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'label', 'inputLabel', 'variant', 'modelValue', 'value', 'type', 'options', 'helperText'],
		render() {
			return h(tag, { 'data-stub': name, 'data-testid': this.$attrs['data-testid'], 'data-label': this.label, 'data-checked': this.modelValue === true ? 'true' : undefined, disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return {
		NcDialog: stub('NcDialog'),
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', 'label'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

const { default: AgreePaymentPlanModal } = await import('../../src/modals/AgreePaymentPlanModal.vue')
const { default: PaymentRunReconcileModal } = await import('../../src/modals/PaymentRunReconcileModal.vue')

/**
 * Render a dialog with props and its data laid over, without its mounted hook.
 *
 * @param {object} view The component.
 * @param {object} props The props.
 * @param {object} [state] Data to lay over.
 * @return {Promise<string>} The HTML, without fragment comments.
 */
async function render(view, props, state = {}) {
	const own = view.data()
	const options = {
		...view,
		mounted() {},
		data: () => ({ ...own, ...state, form: { ...(own.form || {}), ...(state.form || {}) } }),
	}
	return (await renderToString(createSSRApp(options, props))).replace(/<!--[[\]]-->/g, '')
}

/**
 * The instance methods and computed of a dialog bound to a plain object.
 *
 * @param {object} view The component.
 * @param {object} over Props and data.
 * @return {object} The instance.
 */
function instance(view, over = {}) {
	const self = { ...view.data(), ...over }
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(view.computed ?? {})) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('AgreePaymentPlanModal rendered', () => {
	it('renders nothing while closed', async () => {
		expect(await render(AgreePaymentPlanModal, { open: false })).not.toContain('agree-payment-plan-modal')
	})

	it('says so when the customer has no overdue invoice, and cannot draw up', async () => {
		const html = await render(AgreePaymentPlanModal, { open: true }, { loading: false })
		expect(html).toContain('This customer has no overdue invoices.')
		expect(html).toMatch(/data-testid="agree-plan-draw-up"[^>]*disabled/)
	})

	it('lists the invoices with the chosen ones ticked, and asks for a count by default', async () => {
		const html = await render(
			AgreePaymentPlanModal,
			{ open: true },
			{
				loading: false,
				invoices: [{ id: 'i1', invoiceNumber: 'F-1', amountDue: 100 }, { id: 'i2', invoiceNumber: 'F-2', amountDue: 25.5 }],
				form: { invoiceIds: ['i1'] },
			},
		)
		expect(html).toMatch(/data-testid="agree-plan-invoice-F-1"[^>]*data-checked="true"[^>]*>F-1 · EUR 100.00/)
		expect(html).toMatch(/data-testid="agree-plan-invoice-F-2"[^>]*>F-2 · EUR 25.50/)
		expect(html).toContain('data-testid="agree-plan-count"')
		expect(html).not.toContain('data-testid="agree-plan-amount"')
		expect(html).not.toContain('data-label="Collection costs"')
		expect(html).not.toMatch(/data-testid="agree-plan-draw-up"[^>]*disabled/)
	})

	it('asks for an amount per instalment, and for the costs when they are included', async () => {
		const html = await render(
			AgreePaymentPlanModal,
			{ open: true },
			{ loading: false, invoices: [{ id: 'i1', invoiceNumber: 'F-1', amountDue: 1 }], form: { mode: 'amount', includesCharges: true } },
		)
		expect(html).toContain('data-testid="agree-plan-amount"')
		expect(html).not.toContain('data-testid="agree-plan-count"')
		expect(html).toContain('data-label="Collection costs"')
	})

	it('shows the drawn-up schedule and offers to activate it, with an error when there is one', async () => {
		const html = await render(
			AgreePaymentPlanModal,
			{ open: true },
			{
				draft: {
					plan: { id: 'p1', planNumber: 'BR-7', totalAmount: 300 },
					instalments: [{ id: 'a', dueDate: '2026-12-01', amount: 150 }, { id: 'b', dueDate: '2027-01-01', amount: 150 }],
				},
				error: 'Mail failed',
				submitting: true,
			},
		)
		expect(html).toMatch(/data-testid="agree-plan-summary"[^>]*>Plan BR-7: 2 instalments, EUR 300.00 in total./)
		expect(html).toContain('2026-12-01 · EUR 150.00')
		expect(html).toContain('2027-01-01 · EUR 150.00')
		expect(html).toMatch(/data-testid="agree-plan-activate"[^>]*disabled/)
		expect(html).not.toContain('data-testid="agree-plan-draw-up"')
		expect(html).toMatch(/data-testid="agree-plan-error"[^>]*>Mail failed/)
	})

	it('offers monthly first, then weekly, and closes without a value', () => {
		const vm = instance(AgreePaymentPlanModal, { closed: [] })
		vm.$emit = (...args) => vm.closed.push(args)
		expect(vm.frequencyOptions).toEqual([{ value: 'monthly', label: 'Monthly' }, { value: 'weekly', label: 'Weekly' }])
		vm.onClose()
		expect(vm.closed).toEqual([['close']])
	})
})

describe('PaymentRunReconcileModal', () => {
	beforeEach(() => {
		globalThis.FileReader = class {
			readAsText(file) {
				this.result = `contents of ${file.name}`
				this.onload()
			}
		}
	})

	it('renders nothing while closed, and nothing to reconcile without a file', async () => {
		expect(await render(PaymentRunReconcileModal, { open: false })).not.toContain('payment-run-reconcile-modal')
		const html = await render(PaymentRunReconcileModal, { open: true })
		expect(html).toContain('data-testid="payment-run-reconcile-file"')
		expect(html).not.toContain('Selected:')
		expect(html).toMatch(/data-testid="payment-run-reconcile-submit"[^>]*disabled/)
	})

	it('shows the chosen file, and a full match as reconciled', async () => {
		const html = await render(PaymentRunReconcileModal, { open: true }, { fileName: 'camt.xml', fileContents: '<xml/>', result: { result: 'full' } })
		expect(html).toContain('Selected: camt.xml')
		expect(html).toContain('prr__result--ok')
		expect(html).toContain('Reconciled — all lines matched.')
		expect(html).not.toMatch(/data-testid="payment-run-reconcile-submit"[^>]*disabled/)
	})

	it('shows a partial match with its note', async () => {
		const html = await render(PaymentRunReconcileModal, { open: true }, { result: { result: 'partial', mismatchNote: '2 of 3 lines matched' } })
		expect(html).toContain('prr__result--warn')
		expect(html).toContain('2 of 3 lines matched')
	})

	it('reads the chosen file, and forgets it when the choice is cleared', () => {
		const vm = instance(PaymentRunReconcileModal)
		vm.onFileSelected({ target: { files: [{ name: 'camt.xml' }] } })
		expect([vm.fileName, vm.fileContents]).toEqual(['camt.xml', 'contents of camt.xml'])

		vm.onFileSelected({ target: { files: [] } })
		expect([vm.fileName, vm.fileContents]).toEqual(['', ''])
		vm.onFileSelected({ target: {} })
		expect(vm.fileName).toBe('')
	})

	it('takes the run id from the prop, else the route, and has no result class before a result', () => {
		expect(instance(PaymentRunReconcileModal, { paymentRunId: 'run-1', $route: { params: { id: 'r' } } }).runId).toBe('run-1')
		expect(instance(PaymentRunReconcileModal, { $route: { params: { id: 'run-9' } } }).runId).toBe('run-9')
		expect(instance(PaymentRunReconcileModal).runId).toBe('')
		expect(instance(PaymentRunReconcileModal).resultClass).toBe('')
	})

	it('clears the file, the result and the input when closed', () => {
		const input = { value: 'C:\\camt.xml' }
		const vm = instance(PaymentRunReconcileModal, { fileName: 'camt.xml', fileContents: 'x', result: { result: 'full' }, $refs: { fileInput: input }, closed: [] })
		vm.$emit = (...args) => vm.closed.push(args)

		vm.onClose()

		expect([vm.fileName, vm.fileContents, vm.result, input.value, vm.closed]).toEqual(['', '', null, '', [['close']]])

		const bare = instance(PaymentRunReconcileModal, { $refs: {}, closed: [] })
		bare.$emit = (...args) => bare.closed.push(args)
		bare.onClose()
		expect(bare.closed).toEqual([['close']])
	})
})
