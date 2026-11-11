/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for InvoiceGenerateWizard.vue: the line groups it
 * offers (hours, costs passed on, and meter readings for a usage customer)
 * and the step validation (source refusals, line loading, drafting). Methods
 * and computed properties run bound to a fake `this`.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import InvoiceGenerateWizard from '../../src/modals/InvoiceGenerateWizard.vue'
import { draftInvoice, loadCandidates } from '../../src/utils/invoiceGenerateWizard.js'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}`, translatePlural: (app, one) => `${app}:${one}` }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnWizardDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcCheckboxRadioSwitch: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/utils/invoiceGenerateWizard.js', async (importOriginal) => ({
	...(await importOriginal()),
	loadCandidates: vi.fn(),
	draftInvoice: vi.fn(),
}))

/**
 * A fake wizard with its methods bound.
 *
 * @return {object}
 */
function wizard() {
	const self = { ...InvoiceGenerateWizard.data.call({}) }
	for (const [name, method] of Object.entries(InvoiceGenerateWizard.methods)) {
		self[name] = method.bind(self)
	}
	self.money = (value) => `EUR ${value}`
	return self
}

const CANDIDATES = {
	timeEntries: [{ id: 't1', description: 'Advies', personId: 'p1', date: '2026-01-05', hours: 2 }, { id: 't2', personId: 'p2', hours: '1.25' }],
	expenses: [{ id: 'e1', claimNumber: 'C-1', submittedDate: '2026-01-06', totalPassThroughAmount: 12 }, { id: 'e2', description: 'Taxi', totalAmount: 30 }],
	readings: [{ id: 'r1', meterId: 'M-1', periodStart: '2026-01-01', periodEnd: '2026-01-31', quantity: 120, unit: 'kWh' }, { id: 'r2', description: 'Water', quantity: 0, unit: '' }],
}

describe('InvoiceGenerateWizard', () => {
	beforeEach(() => {
		loadCandidates.mockReset()
		draftInvoice.mockReset()
	})

	it('offers hours and costs, plus readings for a usage customer', () => {
		const vm = wizard()
		vm.candidates = CANDIDATES
		vm.source = { billingModel: 'hourly' }
		const plain = InvoiceGenerateWizard.computed.lineGroups.call(vm)
		vm.source = { billingModel: 'usage' }
		const usage = InvoiceGenerateWizard.computed.lineGroups.call(vm)

		expect(plain.map((group) => group.key)).toEqual(['timeEntryIds', 'expenseIds'])
		const hours = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 1 })
		expect(usage).toEqual([
			{
				key: 'timeEntryIds',
				title: 'shillinq:Hours',
				label: 'shillinq:Description',
				quantityLabel: 'shillinq:Hours',
				empty: 'shillinq:No hours in this period.',
				rows: [
					{ id: 't1', title: 'Advies', meta: 'p1 · 2026-01-05', quantity: hours(2) },
					{ id: 't2', title: 'p2', meta: 'p2', quantity: hours('1.25') },
				],
			},
			{
				key: 'expenseIds',
				title: 'shillinq:Costs passed on',
				label: 'shillinq:Costs',
				quantityLabel: 'shillinq:Amount',
				empty: 'shillinq:No costs passed on to this customer in this period.',
				rows: [
					{ id: 'e1', title: 'C-1', meta: 'C-1 · 2026-01-06', quantity: 'EUR 12' },
					{ id: 'e2', title: 'Taxi', meta: '', quantity: 'EUR 30' },
				],
			},
			{
				key: 'meterReadingIds',
				title: 'shillinq:Meter readings',
				label: 'shillinq:Meter',
				quantityLabel: 'shillinq:Usage',
				empty: 'shillinq:This customer has no rated readings in this period that are not on an invoice yet.',
				rows: [
					{ id: 'r1', title: 'M-1', meta: '2026-01-01 to 2026-01-31', quantity: '120 kWh' },
					{ id: 'r2', title: 'Water', meta: '', quantity: '0' },
				],
			},
		])
	})

	it('refuses an incomplete or reversed source period', async () => {
		const vm = wizard()
		vm.source = { customerId: '', fromDate: '2026-01-01', toDate: '2026-01-31' }
		const missing = await vm.validate('source')
		vm.source = { customerId: 'c1', fromDate: '2026-02-01', toDate: '2026-01-31' }
		const reversed = await vm.validate('source')

		expect([missing, reversed]).toEqual(['shillinq:Choose a customer and a period first.', 'shillinq:The period ends before it starts.'])
		expect(loadCandidates).not.toHaveBeenCalled()
	})

	it('loads the lines with everything chosen, or words the failure', async () => {
		loadCandidates.mockResolvedValueOnce(CANDIDATES)
		const vm = wizard()
		vm.source = { customerId: 'c1', fromDate: '2026-01-01', toDate: '2026-01-31' }

		expect(await vm.validate('source')).toBe(true)
		expect(loadCandidates).toHaveBeenCalledWith(vm.source)
		expect([vm.chosen, vm.linesLoading]).toEqual([{ timeEntryIds: ['t1', 't2'], expenseIds: ['e1', 'e2'], meterReadingIds: ['r1', 'r2'] }, false])

		loadCandidates.mockRejectedValueOnce(new Error('x'))
		expect(await vm.validate('source')).toBe('shillinq:The lines could not be loaded.')
		expect(vm.linesLoading).toBe(false)
	})

	it('drafts the invoice from the chosen lines, or words the failure', async () => {
		draftInvoice.mockResolvedValueOnce({ summary: { netAmount: 1 } })
		const vm = wizard()
		vm.source = { customerId: 'c1', fromDate: '2026-01-01', toDate: '2026-01-31' }
		vm.chosen = { timeEntryIds: ['t1'], expenseIds: [], meterReadingIds: [] }

		expect(await vm.validate('lines')).toBe(true)
		expect(vm.draft).toEqual({ summary: { netAmount: 1 } })

		draftInvoice.mockRejectedValueOnce(new Error('x'))
		expect(await vm.validate('lines')).toBe('shillinq:Failed to draft invoice')
		expect(await vm.validate('confirm')).toBe(true)
	})
})
