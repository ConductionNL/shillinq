/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceQuickDraftModal.vue: opening resets and loads customers, the
 * customer options, totals and save gate, the line editing helpers and the
 * save round-trip with its toast, widget refresh, events and error text.
 * Methods, computeds and the `open` watcher run bound to a fake `this`
 * seeded from the real `data()`.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InvoiceQuickDraftModal from '../../src/modals/InvoiceQuickDraftModal.vue'
import { saveQuickDraftPrefs } from '../../src/modals/invoiceQuickDraft.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/modals/invoiceQuickDraft.js', async (importOriginal) => ({
	...(await importOriginal()),
	saveQuickDraftPrefs: vi.fn(),
}))

/**
 * A fake modal with computeds as getters and methods bound.
 *
 * @return {object}
 */
function modal() {
	const self = { emitted: [] }
	for (const [name, method] of Object.entries(InvoiceQuickDraftModal.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, InvoiceQuickDraftModal.data.call(self))
	for (const [name, getter] of Object.entries(InvoiceQuickDraftModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	self.$emit = (...args) => self.emitted.push(args)
	return self
}

/**
 * A modal ready to save one 100.00 line at 21% for customer C-1.
 *
 * @return {object}
 */
function filled() {
	const vm = modal()
	vm.selectedCustomer = { value: 'C-1', customer: { customerId: 'C-1' } }
	vm.form = {
		invoiceDate: '2026-05-01',
		dueDate: '2026-05-31',
		reference: 'PO-7',
		glAccount: '8000',
		lines: [{ description: ' Consultancy ', quantity: 1, unitPrice: 100, vatRate: 21 }],
	}
	return vm
}

describe('InvoiceQuickDraftModal opening', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('resets the form and loads customers when opened', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ customerId: 'C-1' }] } })
		const vm = modal()
		vm.error = 'old'
		vm.selectedCustomer = { value: 'X' }
		vm.form.reference = 'stale'

		InvoiceQuickDraftModal.watch.open.handler.call(vm, true)
		await vi.waitFor(() => expect(vm.loadingCustomers).toBe(false))

		expect(InvoiceQuickDraftModal.watch.open.immediate).toBe(true)
		expect([vm.error, vm.selectedCustomer, vm.form.reference, vm.form.lines.length]).toEqual(['', null, '', 1])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/CustomerMaster', { params: { _limit: 500 } })
		expect(vm.customers).toEqual([{ customerId: 'C-1' }])
	})

	it('does nothing when closed', () => {
		const vm = modal()

		InvoiceQuickDraftModal.watch.open.handler.call(vm, false)

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads customers from objects or a bare list and treats failures as none', async () => {
		const vm = modal()
		axios.get.mockResolvedValueOnce({ data: { objects: [{ id: 'c-2' }] } })
		await vm.fetchCustomers()
		expect(vm.customers).toEqual([{ id: 'c-2' }])

		axios.get.mockResolvedValueOnce({ data: [{ id: 'c-3' }] })
		await vm.fetchCustomers()
		expect(vm.customers).toEqual([{ id: 'c-3' }])

		axios.get.mockResolvedValueOnce({ data: { results: 'none' } })
		await vm.fetchCustomers()
		expect(vm.customers).toEqual([])

		vm.customers = [{ id: 'old' }]
		axios.get.mockRejectedValueOnce(new Error('down'))
		await vm.fetchCustomers()
		expect([vm.customers, vm.loadingCustomers]).toEqual([[], false])
	})
})

describe('InvoiceQuickDraftModal form', () => {
	it('labels customers by legal name, trade name or id', () => {
		const vm = modal()
		vm.customers = [
			{ customerId: 'C-1', legalName: 'Acme BV', tradeName: 'Acme' },
			{ id: 'c-2', tradeName: 'Beta' },
			{ customerId: 'C-3' },
			{},
		]

		expect(vm.customerOptions.map(({ value, display }) => [value, display])).toEqual([
			['C-1', 'Acme BV'],
			['c-2', 'Beta'],
			['C-3', 'C-3'],
			['', ''],
		])
	})

	it('totals the lines', () => {
		const vm = modal()
		vm.form.lines = [{ quantity: 2, unitPrice: 50, vatRate: 21 }, { quantity: 1, unitPrice: 10, vatRate: 9 }]

		expect([vm.netAmount, vm.vatAmount, vm.grossAmount]).toEqual([110, 21.9, 131.9])
	})

	it('allows saving only with a customer and a priced, described line, and not while saving', () => {
		const vm = filled()
		expect(vm.canSave).toBe(true)

		vm.saving = true
		expect(vm.canSave).toBe(false)
		vm.saving = false

		vm.form.lines = [{ description: '  ', unitPrice: 10 }, { description: 'x', unitPrice: 0 }]
		expect(vm.canSave).toBe(false)

		vm.form.lines = [{ description: 'x', unitPrice: 1 }]
		vm.selectedCustomer = { value: '' }
		expect(vm.canSave).toBe(false)
		vm.selectedCustomer = null
		expect(vm.canSave).toBe(false)
	})

	it('picks the VAT option of a line, defaulting to 21%', () => {
		const vm = modal()
		expect(vm.vatOptionFor({ vatRate: '9' })).toEqual({ value: 9, display: '9%' })
		expect(vm.vatOptionFor({ vatRate: 6 })).toEqual({ value: 21, display: '21%' })

		const line = { vatRate: 21 }
		vm.onVatSelected(line, { value: '0' })
		expect(line.vatRate).toBe(0)
		vm.onVatSelected(line, null)
		expect(line.vatRate).toBe(21)
	})

	it('adds lines and removes them down to one', () => {
		const vm = modal()
		vm.addLine()
		vm.form.lines[1].description = 'second'
		expect(vm.form.lines.length).toBe(2)

		vm.removeLine(0)
		expect(vm.form.lines.map((line) => line.description)).toEqual(['second'])
		vm.removeLine(0)
		expect(vm.form.lines.length).toBe(1)
	})

	it('recomputes the due date from the selected customer or net30', () => {
		const vm = modal()
		vm.form.invoiceDate = '2026-05-01'

		// The customer's term is paymentTermDays (sales-invoice-sending REQ-SIS-005).
		vm.recomputeDueDate({ customerId: 'C-1', paymentTermDays: 14 })
		expect(vm.form.dueDate).toBe('2026-05-15')

		vm.selectedCustomer = { customer: { paymentTermDays: 7 } }
		vm.recomputeDueDate('change-event')
		expect(vm.form.dueDate).toBe('2026-05-08')

		vm.selectedCustomer = null
		vm.recomputeDueDate()
		expect(vm.form.dueDate).toBe('2026-05-31')
	})

	it('formats euros the Dutch way and dates as ISO days', () => {
		const vm = modal()
		expect(vm.formatEuro('12.5').replace(/\s/g, ' ')).toBe('€ 12,50')
		expect(vm.formatEuro('x').replace(/\s/g, ' ')).toBe('€ 0,00')
		expect(vm.today()).toMatch(/^\d{4}-\d{2}-\d{2}$/)
	})

	it('closes unless saving', () => {
		const vm = modal()
		vm.saving = true
		vm.onClose()
		expect(vm.emitted).toEqual([])

		vm.saving = false
		vm.onClose()
		expect(vm.emitted).toEqual([['close']])
	})
})

describe('InvoiceQuickDraftModal save', () => {
	beforeEach(() => {
		axios.post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
		saveQuickDraftPrefs.mockReset()
	})

	it('posts the draft, stores the preferences, toasts, refreshes and closes', async () => {
		axios.post.mockResolvedValue({ data: { id: 'inv-1', invoiceNumber: 'F-2026-0007' } })
		const vm = filled()

		await vm.onSave()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/quick-drafts', {
			customerId: 'C-1',
			invoiceDate: '2026-05-01',
			dueDate: '2026-05-31',
			reference: 'PO-7',
			glAccount: '8000',
			lines: [{ description: 'Consultancy', quantity: 1, unitPrice: 100, vatRate: 21 }],
		})
		expect(saveQuickDraftPrefs).toHaveBeenCalledWith('C-1', { glAccount: '8000', vatCode: 21, description: ' Consultancy ', unitPrice: 100 })
		expect(showSuccess).toHaveBeenCalledWith('Draft invoice F-2026-0007 created.')
		expect(emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'widget-open-debtors' })
		expect([vm.emitted, vm.saving]).toEqual([[['created', { id: 'inv-1', invoiceNumber: 'F-2026-0007' }], ['close']], false])
	})

	it('names the draft by id when the server gives no number', async () => {
		axios.post.mockResolvedValue({ data: { id: 'inv-2' } })
		const vm = filled()

		await vm.onSave()

		expect(showSuccess).toHaveBeenCalledWith('Draft invoice inv-2 created.')
		expect(vm.emitted[0]).toEqual(['created', { id: 'inv-2', invoiceNumber: '' }])
	})

	it('does not post when it cannot save', async () => {
		const vm = modal()

		await vm.onSave()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows the server message, the error message or the generic one and stays open', async () => {
		const shown = []
		for (const failure of [{ response: { data: { message: 'Customer blocked' } } }, new Error('Network Error'), {}]) {
			axios.post.mockRejectedValueOnce(failure)
			const vm = filled()

			await vm.onSave()

			shown.push([vm.error, vm.saving, vm.emitted.length])
		}

		expect(shown).toEqual([
			['Customer blocked', false, 0],
			['Network Error', false, 0],
			['Failed to create draft invoice.', false, 0],
		])
		expect(showError).toHaveBeenCalledTimes(3)
		expect(saveQuickDraftPrefs).not.toHaveBeenCalled()
	})
})
