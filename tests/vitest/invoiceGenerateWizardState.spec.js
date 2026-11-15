/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceGenerateWizard.vue: loading customers, rate cards and retainers on
 * mount, the option lists and the per-model needs, the select watchers, the
 * line groups per model, toggling lines and posting the draft. Methods,
 * computeds and watchers run bound to a fake `this` seeded from `data()`.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InvoiceGenerateWizard from '../../src/modals/InvoiceGenerateWizard.vue'
import { postInvoice } from '../../src/utils/invoiceGenerateWizard.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/utils/invoiceGenerateWizard.js', async (importOriginal) => ({
	...(await importOriginal()),
	postInvoice: vi.fn(),
}))

/**
 * A fake wizard with computeds as getters/setters, methods bound and a
 * wizard ref that records its result.
 *
 * @return {object}
 */
function wizard() {
	const self = { results: [] }
	for (const [name, method] of Object.entries(InvoiceGenerateWizard.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, InvoiceGenerateWizard.data.call(self))
	for (const [name, computed] of Object.entries(InvoiceGenerateWizard.computed)) {
		const getter = typeof computed === 'function' ? computed : computed.get
		const setter = typeof computed === 'function' ? undefined : computed.set
		Object.defineProperty(self, name, { get: getter.bind(self), set: setter?.bind(self) })
	}
	self.$refs = { wizard: { setResult: (result) => self.results.push(result) } }
	return self
}

describe('InvoiceGenerateWizard mount', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('loads the active administration and then its customers, rate cards and retainers', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/context')) {
				return { data: { activeAdministrationId: 7 } }
			}
			const schema = url.split('/').pop()
			return { data: { results: [{ id: `${schema}-1` }] } }
		})
		const vm = wizard()

		await InvoiceGenerateWizard.mounted.call(vm)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		for (const schema of ['CustomerMaster', 'RateCard', 'RetainerSchedule']) {
			expect(axios.get).toHaveBeenCalledWith(`/index.php/apps/openregister/api/objects/shillinq/${schema}`, { params: { administrationId: '7', _limit: 500 } })
		}
		expect([vm.customers, vm.rateCards, vm.retainers, vm.loading, vm.error]).toEqual([
			[{ id: 'CustomerMaster-1' }],
			[{ id: 'RateCard-1' }],
			[{ id: 'RetainerSchedule-1' }],
			false,
			'',
		])
	})

	it('shows the server message, or a generic one, when loading fails', async () => {
		const vm = wizard()
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No administration' } } })
		await InvoiceGenerateWizard.mounted.call(vm)
		expect([vm.error, vm.loading]).toEqual(['No administration', false])

		axios.get.mockRejectedValueOnce(new Error('down'))
		await InvoiceGenerateWizard.mounted.call(vm)
		expect(vm.error).toBe('The customers could not be loaded.')
	})
})

describe('InvoiceGenerateWizard options', () => {
	it('lists the three steps and every billing model with its label', () => {
		const vm = wizard()

		expect(vm.steps.map((step) => step.id)).toEqual(['source', 'lines', 'review'])
		expect(vm.modelOptions).toEqual([
			{ value: 't_and_m', label: 'Time & Materials' },
			{ value: 'fixed_fee', label: 'Fixed fee' },
			{ value: 'milestone', label: 'Milestone' },
			{ value: 'retainer', label: 'Retainer' },
			{ value: 'mixed', label: 'Mixed' },
			{ value: 'usage', label: 'Usage' },
		])
	})

	it('reads and writes the billing model through its option, defaulting to T&M', () => {
		const vm = wizard()
		expect(vm.model).toEqual({ value: 't_and_m', label: 'Time & Materials' })

		vm.model = { value: 'retainer' }
		expect(vm.source.billingModel).toBe('retainer')
		vm.model = null
		expect(vm.source.billingModel).toBe('t_and_m')

		vm.source.billingModel = 'unknown'
		expect(vm.model).toBe(null)
	})

	it('labels customers, rate cards and retainers', () => {
		const vm = wizard()
		vm.customers = [{ id: 'c1', legalName: 'Acme BV' }, { '@self': { id: 'c2' }, tradeName: 'Beta' }, { id: 'c3', customerId: 'K-3' }, { id: 'c4' }]
		vm.rateCards = [{ id: 'r1', level: 'Senior', hourlyRate: 120 }, { id: 'r2', level: 'Junior' }]
		vm.retainers = [{ id: 's1', label: 'Monthly' }, { id: 's2', scheduleId: 'RS-2' }, { id: 's3' }]

		expect(vm.customerOptions).toEqual([
			{ value: 'c1', label: 'Acme BV' },
			{ value: 'c2', label: 'Beta' },
			{ value: 'c3', label: 'K-3' },
			{ value: 'c4', label: 'c4' },
		])
		expect(vm.rateCardOptions[0].label).toMatch(/^Senior, .*120/)
		expect(vm.rateCardOptions[1]).toEqual({ value: 'r2', label: 'Junior' })
		expect(vm.retainerOptions.map((option) => option.label)).toEqual(['Monthly', 'RS-2', 's3'])
	})

	it('needs a rate card, retainer or fixed fee per billing model', () => {
		const vm = wizard()
		const needs = {}
		for (const model of ['t_and_m', 'fixed_fee', 'retainer', 'mixed', 'usage']) {
			vm.source.billingModel = model
			needs[model] = [vm.needsRateCard, vm.needsRetainer, vm.needsFixedFee]
		}

		expect(needs).toEqual({
			t_and_m: [true, false, false],
			fixed_fee: [false, false, true],
			retainer: [false, true, false],
			mixed: [true, true, true],
			usage: [false, false, false],
		})
	})

	it('copies the chosen select options into the source, clearing on none', () => {
		const vm = wizard()
		const { customer, rateCard, retainer } = InvoiceGenerateWizard.watch

		customer.call(vm, { value: 'c1' })
		rateCard.call(vm, { value: 'r1' })
		retainer.call(vm, { value: 's1' })
		expect([vm.source.customerId, vm.source.rateCardId, vm.source.retainerScheduleId]).toEqual(['c1', 'r1', 's1'])

		customer.call(vm, null)
		rateCard.call(vm, null)
		retainer.call(vm, null)
		expect([vm.source.customerId, vm.source.rateCardId, vm.source.retainerScheduleId]).toEqual(['', '', ''])
	})

	it('words the period', () => {
		const vm = wizard()
		vm.source.fromDate = '2026-04-01'
		vm.source.toDate = '2026-04-30'

		expect(vm.periodLabel).toBe('Period 2026-04-01 to 2026-04-30')
	})
})

describe('InvoiceGenerateWizard lines', () => {
	it('groups hours and costs, and readings only for usage billing', () => {
		const vm = wizard()
		vm.candidates = {
			timeEntries: [{ id: 't1', description: 'Design', personId: 'ann', date: '2026-04-02', hours: 2 }, { id: 't2', personId: 'bob' }],
			expenses: [{ id: 'e1', claimNumber: 'EC-1', submittedDate: '2026-04-03', totalAmount: 'x' }],
			readings: [{ id: 'm1', meterId: 'MTR-1', periodStart: '2026-04-01', periodEnd: '2026-04-30', quantity: 12, unit: 'kWh' }, { id: 'm2', description: 'Water', quantity: '' }],
		}

		const groups = vm.lineGroups
		expect(groups.map((group) => group.key)).toEqual(['timeEntryIds', 'expenseIds'])
		expect(groups[0].rows.map(({ id, title, meta }) => [id, title, meta])).toEqual([
			['t1', 'Design', 'ann · 2026-04-02'],
			['t2', 'bob', 'bob'],
		])
		expect(groups[1].rows[0]).toEqual({ id: 'e1', title: 'EC-1', meta: 'EC-1 · 2026-04-03', quantity: '—' })

		vm.source.billingModel = 'usage'
		const readings = vm.lineGroups[2]
		expect(readings.key).toBe('meterReadingIds')
		expect(readings.rows).toEqual([
			{ id: 'm1', title: 'MTR-1', meta: '2026-04-01 to 2026-04-30', quantity: '12 kWh' },
			{ id: 'm2', title: 'Water', meta: '', quantity: '' },
		])
	})

	it('toggles a line in and out of its list and counts every chosen line', () => {
		const vm = wizard()
		vm.toggle('timeEntryIds', 't1', true)
		vm.toggle('timeEntryIds', 't1', true)
		vm.toggle('expenseIds', 'e1', true)
		expect([vm.chosen.timeEntryIds, vm.count]).toEqual([['t1'], 2])

		vm.toggle('timeEntryIds', 't1', false)
		expect([vm.chosen.timeEntryIds, vm.count]).toEqual([[], 1])
	})
})

describe('InvoiceGenerateWizard posting', () => {
	beforeEach(() => {
		postInvoice.mockReset()
		showSuccess.mockReset()
	})

	it('posts the draft by id and reports success', async () => {
		postInvoice.mockResolvedValue({})
		const vm = wizard()
		vm.draft = { invoiceId: 'inv-9' }

		await vm.onSubmit()

		expect(postInvoice).toHaveBeenCalledWith('inv-9')
		expect(showSuccess).toHaveBeenCalledWith('Invoice posted to sales invoices')
		expect(vm.results).toEqual([{ success: true, message: 'Invoice posted to sales invoices' }])
	})

	it('reports the server message, or a generic one, when posting fails', async () => {
		const vm = wizard()
		postInvoice.mockRejectedValueOnce({ response: { data: { message: 'Period closed' } } })
		await vm.onSubmit()
		postInvoice.mockRejectedValueOnce(new Error('x'))
		await vm.onSubmit()

		expect(postInvoice).toHaveBeenCalledWith('')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(vm.results).toEqual([{ error: 'Period closed' }, { error: 'The invoice could not be posted.' }])
	})
})
