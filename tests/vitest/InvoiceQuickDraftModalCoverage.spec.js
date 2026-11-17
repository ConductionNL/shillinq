/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceQuickDraftModal driven through its template: rendered with
 * recording stand-ins for @nextcloud/vue (tests/vitest/helpers/standInRender.js),
 * so every listener and v-model writer the template binds is fired as the
 * user would — the customer select, the dates, the reference, each line's
 * inputs and VAT select, add / remove line, the GL account picker, cancel
 * and save — and the rendered totals, error and button labels are read back.
 * Only HTTP, the toast, the event bus and the @nextcloud/vue components are
 * replaced; the real invoiceQuickDraft.js helpers run.
 *
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
	translatePlural: (app, s) => s,
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
		NcSelect: standIn('NcSelect'),
	}
})

const { default: InvoiceQuickDraftModal } = await import('../../src/modals/InvoiceQuickDraftModal.vue')

const CUSTOMERS = '/index.php/apps/openregister/api/objects/shillinq/CustomerMaster'
const SAVE = '/index.php/apps/shillinq/api/ar-invoices/quick-drafts'

/**
 * Answer the customer list with these rows and every other GET with nothing.
 *
 * @param {Array<object>} rows The customers.
 */
function customers(rows) {
	http.get.mockImplementation(async (url) => (url === CUSTOMERS ? { data: { results: rows } } : { data: { results: [] } }))
}

/**
 * Render the dialog open.
 *
 * @param {object} [props] Extra props (listeners).
 * @param {string} [slots] 'vnode' or 'ssr'.
 * @return {Promise<object>} The rendered view.
 */
function render(props = {}, slots = 'vnode') {
	return renderWithStandIns(InvoiceQuickDraftModal, { props: { open: true, ...props }, slots })
}

/**
 * Render the dialog again from a live instance's state. The open watcher is
 * immediate and resets the form, so this second render leaves it out: it
 * shows what the user sees after the edits the first render's handlers made.
 *
 * @param {object} view The first render.
 * @return {Promise<object>} The view rendered from that state.
 */
function show(view) {
	const { watch, ...withoutWatch } = InvoiceQuickDraftModal
	expect(watch.open.immediate).toBe(true)
	return renderWithStandIns(withoutWatch, { props: { open: true }, data: JSON.parse(JSON.stringify(view.vm.$data)) })
}

/**
 * Fire a listener that may arrive as one function or as an array of them.
 *
 * @param {Function|Array<Function>} listener The listener(s).
 * @param {...*} args The arguments.
 * @return {*} The last handler's result.
 */
function fire(listener, ...args) {
	let result
	for (const handler of [].concat(listener)) {
		result = handler(...args)
	}
	return result
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
 * Wait until the customer list has loaded.
 *
 * @param {object} view The rendered view.
 */
async function loaded(view) {
	await vi.waitFor(() => expect(view.vm.loadingCustomers).toBe(false))
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-03-10T12:00:00Z'))
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	toast.showError.mockReset()
	bus.emit.mockReset()
	const store = {}
	vi.stubGlobal('localStorage', {
		getItem: (key) => (key in store ? store[key] : null),
		setItem: (key, value) => {
			store[key] = String(value)
		},
		removeItem: (key) => {
			delete store[key]
		},
	})
	customers([])
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('InvoiceQuickDraftModal template', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(InvoiceQuickDraftModal, { props: { open: false } })
		expect(view.standIns('NcDialog')).toEqual([])
		expect(view.html).not.toContain('iqd')
		expect(http.get).not.toHaveBeenCalledWith(CUSTOMERS, expect.anything())
	})

	it('loads the customers on open and offers them in the customer select', async () => {
		customers([{ customerId: 'C-1', legalName: 'Acme BV' }, { id: 'C-2', tradeName: 'Beta' }])
		const view = await render()
		await loaded(view)

		expect(http.get).toHaveBeenCalledWith(CUSTOMERS, { params: { _limit: 500 } })
		expect(view.standIns('NcDialog')[0].name).toBe('Quick draft invoice')
		const again = await show(view)
		const select = standInById(again, 'NcSelect', 'iqd-customer')
		expect(select.options.map((o) => [o.value, o.display])).toEqual([['C-1', 'Acme BV'], ['C-2', 'Beta']])
		expect(select.inputLabel).toBe('Customer')
		expect(select.loading).toBe(false)
	})

	it('shows the totals and a disabled save on an empty form, today as the invoice date', async () => {
		const view = await render()
		expect(view.byTestId('iqd-invoice-date').props.value).toBe('2026-03-10')
		expect(vnodeText(view.byTestId('iqd-totals'))).toMatch(/Net: € 0,00 VAT: € 0,00 Total: € 0,00/)
		expect(view.byTestId('iqd-save').props.disabled).toBe(true)
		expect(view.html).toContain('Save draft')
		expect(view.byTestId('iqd-line-remove').props.disabled).toBe(true)
		expect(view.byTestId('iqd-error')).toBeUndefined()
	})

	it('picks a customer: GL account, due date from its terms, and a save that turns on', async () => {
		// The customer's term is paymentTermDays (sales-invoice-sending REQ-SIS-005).
		const acme = { customerId: 'C-1', legalName: 'Acme BV', paymentTermDays: 14, defaultGlAccount: 8010 }
		customers([acme])
		const view = await render()
		await loaded(view)
		const again = await show(view)
		const select = standInById(again, 'NcSelect', 'iqd-customer')

		expect(again.byTestId('iqd-save').props.disabled).toBe(true)

		fire(select['onOption:selected'], select.options[0])
		expect(again.vm.selectedCustomer.value).toBe('C-1')
		expect(again.vm.form.glAccount).toBe('8010')
		expect(again.vm.form.dueDate).toBe('2026-03-24')
		again.vm.form.lines[0].description = 'Work'
		again.vm.form.lines[0].unitPrice = 5
		expect((await show(again)).byTestId('iqd-save').props.disabled).toBe(false)

		fire(select['onUpdate:modelValue'], null)
		expect(again.vm.selectedCustomer).toBe(null)
	})

	it('writes each field and line through the template bindings', async () => {
		const view = await render()

		fire(view.byTestId('iqd-invoice-date').props['onUpdate:modelValue'], '2026-04-01')
		fire(view.byTestId('iqd-due-date').props['onUpdate:modelValue'], '2026-05-01')
		fire(view.byTestId('iqd-reference').props['onUpdate:modelValue'], 'PO-9')
		fire(view.byTestId('iqd-line-description').props['onUpdate:modelValue'], 'Consultancy')
		fire(view.byTestId('iqd-line-quantity').props['onUpdate:modelValue'], 2)
		fire(view.byTestId('iqd-line-unit-price').props['onUpdate:modelValue'], 50)
		fire(standInById(view, 'NcSelect', 'iqd-line-vat')['onUpdate:modelValue'], { value: 9 })
		fire(view.byTestId('iqd-gl-account').props['onUpdate:modelValue'], '8000')

		expect(view.vm.form).toMatchObject({
			invoiceDate: '2026-04-01',
			dueDate: '2026-05-01',
			reference: 'PO-9',
			glAccount: '8000',
			lines: [{ description: 'Consultancy', quantity: 2, unitPrice: 50, vatRate: 9 }],
		})

		fire(view.byTestId('iqd-invoice-date').props.onChange)
		expect(view.vm.form.dueDate).toBe('2026-05-01')

		const again = await show(view)
		expect(vnodeText(again.byTestId('iqd-totals'))).toMatch(/Net: € 100,00 VAT: € 9,00 Total: € 109,00/)
		expect(standInById(again, 'NcSelect', 'iqd-line-vat').modelValue).toEqual({ value: 9, display: '9%' })

		fire(standInById(view, 'NcSelect', 'iqd-line-vat')['onUpdate:modelValue'], null)
		expect(view.vm.form.lines[0].vatRate).toBe(21)
	})

	it('adds and removes lines through their buttons', async () => {
		const view = await render()
		fire(view.byTestId('iqd-add-line').props.onClick)
		fire(view.byTestId('iqd-add-line').props.onClick)
		expect(view.vm.form.lines).toHaveLength(3)

		const again = await show(view)
		expect(again.find((v) => v.props?.['data-testid'] === 'iqd-line')).toHaveLength(3)
		const removes = again.find((v) => v.props?.['data-testid'] === 'iqd-line-remove')
		expect(removes.map((v) => v.props.disabled)).toEqual([false, false, false])

		view.vm.form.lines[1].description = 'second'
		fire(view.byTestId('iqd-line-remove').props.onClick)
		expect(view.vm.form.lines.map((l) => l.description)).toEqual(['second', ''])
	})

	it('closes through cancel and the dialog, but not while saving', async () => {
		const onClose = vi.fn()
		const view = await render({ onClose })

		fire(view.byTestId('iqd-cancel').props.onClick)
		fire(view.standIns('NcDialog')[0].onClosing)
		expect(onClose).toHaveBeenCalledTimes(2)

		view.vm.saving = true
		fire(view.byTestId('iqd-cancel').props.onClick)
		expect(onClose).toHaveBeenCalledTimes(2)

		const again = await show(view)
		expect(again.html).toContain('Saving…')
		expect(again.byTestId('iqd-cancel').props.disabled).toBe(true)
	})

	it('saves the draft through the save button, toasts, refreshes the widget and closes', async () => {
		const acme = { customerId: 'C-1', legalName: 'Acme BV', paymentTerms: 'net30' }
		customers([acme])
		const onClose = vi.fn()
		const onCreated = vi.fn()
		const view = await render({ onClose, onCreated })
		await loaded(view)
		view.vm.onCustomerSelected({ value: 'C-1', display: 'Acme BV', customer: acme })
		view.vm.form.reference = 'PO-7'
		view.vm.form.glAccount = '8000'
		Object.assign(view.vm.form.lines[0], { description: 'Consultancy', quantity: 1, unitPrice: 100 })
		http.post.mockResolvedValue({ data: { id: 'inv-1', invoiceNumber: 'INV-2026-1' } })

		const again = await show(view)
		expect(again.byTestId('iqd-save').props.disabled).toBe(false)
		await fire(view.byTestId('iqd-save').props.onClick)

		expect(http.post).toHaveBeenCalledTimes(1)
		const [url, body] = http.post.mock.calls[0]
		expect(url).toBe(SAVE)
		expect(body).toMatchObject({ customerId: 'C-1', invoiceDate: '2026-03-10', dueDate: '2026-04-09', reference: 'PO-7' })
		expect(toast.showSuccess).toHaveBeenCalledWith('Draft invoice INV-2026-1 created.')
		expect(bus.emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'widget-open-debtors' })
		expect(onCreated).toHaveBeenCalledWith({ id: 'inv-1', invoiceNumber: 'INV-2026-1' })
		expect(onClose).toHaveBeenCalledTimes(1)
		expect(view.vm.saving).toBe(false)
	})

	it('shows the server error under the form when the save fails', async () => {
		const view = await render()
		view.vm.selectedCustomer = { value: 'C-1', customer: { customerId: 'C-1' } }
		Object.assign(view.vm.form.lines[0], { description: 'Work', unitPrice: 10 })
		http.post.mockRejectedValue({ response: { data: { message: 'Customer blocked' } } })

		await fire(view.byTestId('iqd-save').props.onClick)

		expect(toast.showError).toHaveBeenCalledWith('Customer blocked')
		const again = await show(view)
		expect(vnodeText(again.byTestId('iqd-error'))).toBe('Customer blocked')
	})

	it('renders the same form the server way', async () => {
		const view = await render({}, 'ssr')
		expect(view.html).toContain('data-testid="iqd-invoice-date"')
		expect(view.html).toContain('value="2026-03-10"')
		expect(view.html).toMatch(/Net: €\s0,00/)
		expect(view.html).toContain('Save draft')
		expect(view.html).toMatch(/disabled aria-label="Remove line" data-testid="iqd-line-remove"/)
	})
})
