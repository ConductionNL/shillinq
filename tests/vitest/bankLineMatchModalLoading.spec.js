/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BankLineMatchModal.vue: loading the line, its open invoices, accounts and
 * payment plans; the derived summary, candidate order, totals and the
 * confirm gate per tab; and paying a line to a payment plan. Methods and
 * computeds run bound to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BankLineMatchModal from '../../src/modals/BankLineMatchModal.vue'
import { payPlanFromLine, planCandidates } from '../../src/utils/paymentPlanApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/utils/paymentPlanApi.js', () => ({ payPlanFromLine: vi.fn(), planCandidates: vi.fn() }))

const BASE = '/index.php/apps/openregister/api/objects/shillinq'

/**
 * A fake modal for the given line id, with computeds as getters and methods bound.
 *
 * @param {string} lineId The line id prop.
 * @return {object}
 */
function modal(lineId = 'bl-1') {
	const self = { ...BankLineMatchModal.data.call({}), lineId, emitted: [] }
	for (const [name, getter] of Object.entries(BankLineMatchModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	for (const [name, method] of Object.entries(BankLineMatchModal.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.emitted.push(args)
	return self
}

describe('BankLineMatchModal loading', () => {
	beforeEach(() => {
		axios.get.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
		planCandidates.mockReset()
		payPlanFromLine.mockReset()
	})

	it('reads the line by uuid', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: 'bl-1', amount: 10 } })
		const vm = modal('a/b')

		await vm.loadLine()

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/BankStatementLine/a%2Fb`)
		expect([vm.line, vm.loadError]).toEqual([{ id: 'bl-1', amount: 10 }, ''])
	})

	it('falls back to the lineId business key', async () => {
		axios.get.mockRejectedValueOnce(new Error('404')).mockResolvedValueOnce({ data: { results: [{ id: 'bl-9' }] } })
		const vm = modal('L-9')

		await vm.loadLine()

		expect(axios.get).toHaveBeenLastCalledWith(`${BASE}/BankStatementLine`, { params: { lineId: 'L-9', _limit: 1 } })
		expect(vm.line).toEqual({ id: 'bl-9' })
	})

	it('reports a line that is found neither way', async () => {
		for (const second of [() => axios.get.mockResolvedValueOnce({ data: { objects: [] } }), () => axios.get.mockRejectedValueOnce(new Error('down'))]) {
			axios.get.mockReset()
			axios.get.mockRejectedValueOnce(new Error('404'))
			second()
			const vm = modal()

			await vm.loadLine()

			expect([vm.line, vm.loadError]).toEqual([null, 'shillinq:Bank line not found'])
		}
	})

	it('loads open receivables for an incoming line', async () => {
		axios.get.mockResolvedValue({
			data: {
				results: [
					{ id: 'i1', invoiceNumber: 'F-1', buyerName: 'Acme', lifecycleState: 'issued', amountDue: 50, grossAmount: 60 },
					{ id: 'i2', customerId: 'c-2', lifecycleState: 'overdue', grossAmount: 70 },
					{ id: 'i3', lifecycleState: 'paid', amountDue: 10 },
				],
			},
		})
		const vm = modal()
		vm.line = { id: 'bl-1', amount: 50, administrationId: 'adm-1' }

		await vm.loadInvoices()

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/ARInvoice`, { params: { administrationId: 'adm-1', _limit: 500 } })
		expect(vm.invoices).toEqual([
			{ id: 'i1', number: 'F-1', counterparty: 'Acme', amount: 50 },
			{ id: 'i2', number: 'i2', counterparty: 'c-2', amount: 70 },
		])
	})

	it('loads open payables, including part-paid ones, for an outgoing line', async () => {
		axios.get.mockResolvedValue({
			data: [
				{ id: 'p1', invoiceNumber: 'B-1', vendorId: 'v-1', state: 'partially-paid', totalAmount: 30 },
				{ id: 'p2', state: 'issued' },
				{ id: 'p3', state: 'draft', totalAmount: 5 },
			],
		})
		const vm = modal()
		vm.line = { id: 'bl-1', amount: -30, administrationId: 'adm-1' }

		await vm.loadInvoices()

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/APTransaction`, { params: { administrationId: 'adm-1', _limit: 500 } })
		expect(vm.invoices).toEqual([
			{ id: 'p1', number: 'B-1', counterparty: 'v-1', amount: 30 },
			{ id: 'p2', number: 'p2', counterparty: '', amount: 0 },
		])
	})

	it('loads the administration accounts and treats a non-list answer as none', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ accountNumber: '4000', name: 'Kosten' }, { accountNumber: '1300' }] } })
		const vm = modal()
		vm.line = { id: 'bl-1', administrationId: 'adm-1' }

		await vm.loadAccounts()

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/Account`, { params: { administrationId: 'adm-1', _limit: 1000 } })
		expect(vm.accountOptions).toEqual([{ value: '4000', label: '4000 Kosten' }, { value: '1300', label: '1300' }])

		axios.get.mockResolvedValueOnce({ data: { results: 'nope' } })
		await vm.loadAccounts()
		expect(vm.accounts).toEqual([])
	})

	it('preselects a high-confidence plan for an incoming line only', async () => {
		planCandidates.mockResolvedValue([{ planId: 'pp-1', confidence: 'high' }, { planId: 'pp-2', confidence: 'low' }])
		const vm = modal()
		vm.line = { id: 'bl-1', amount: 20 }

		await vm.loadPlans()

		expect(planCandidates).toHaveBeenCalledWith('bl-1')
		expect(vm.planId).toBe('pp-1')

		const outgoing = modal()
		outgoing.line = { id: 'bl-2', amount: -20 }
		await outgoing.loadPlans()
		expect(planCandidates).toHaveBeenCalledTimes(1)
	})

	it('leaves a low-confidence plan unselected and a failing lookup empty', async () => {
		planCandidates.mockResolvedValueOnce([{ planId: 'pp-2', confidence: 'low' }])
		const vm = modal()
		vm.line = { id: 'bl-1', amount: 20 }
		await vm.loadPlans()
		expect([vm.plans.length, vm.planId]).toEqual([1, ''])

		planCandidates.mockRejectedValueOnce(new Error('down'))
		await vm.loadPlans()
		expect(vm.plans).toEqual([])
	})

	it('mounts by loading the line and then the rest, or only the line when it is missing', async () => {
		const vm = modal()
		const calls = []
		vm.loadLine = async () => { calls.push('line'); vm.line = { id: 'bl-1' } }
		vm.loadInvoices = async () => calls.push('invoices')
		vm.loadAccounts = async () => calls.push('accounts')
		vm.loadPlans = async () => calls.push('plans')

		await BankLineMatchModal.mounted.call(vm)
		expect(calls).toEqual(['line', 'invoices', 'accounts', 'plans'])

		calls.length = 0
		vm.loadLine = async () => { calls.push('line'); vm.line = null }
		await BankLineMatchModal.mounted.call(vm)
		expect(calls).toEqual(['line'])
	})
})

describe('BankLineMatchModal derived state', () => {
	it('summarises the line, skipping empty parts', () => {
		const vm = modal()
		expect(vm.lineSummary).toBe('')

		vm.line = { valueDate: '2026-05-01', counterpartyName: 'Acme', amount: -12.5, reference: 'REF-1' }
		expect(vm.lineSummary).toBe('2026-05-01 · Acme · EUR 12.50 · REF-1')

		vm.line = { amount: 3, remittanceInfo: 'Factuur 7', reference: 'REF-2' }
		expect(vm.lineSummary).toBe('EUR 3.00 · Factuur 7')
	})

	it('filters candidates by number, party or amount and puts exact amounts first', () => {
		const vm = modal()
		vm.line = { amount: -40 }
		vm.invoices = [
			{ id: 'a', number: 'F-3', counterparty: 'Beta', amount: 10 },
			{ id: 'b', number: 'F-2', counterparty: 'Acme', amount: 40 },
			{ id: 'c', number: 'F-1', counterparty: 'Acme', amount: 25 },
		]

		expect(vm.candidates.map((invoice) => invoice.id)).toEqual(['b', 'c', 'a'])

		vm.search = ' acme '
		expect(vm.candidates.map((invoice) => invoice.id)).toEqual(['b', 'c'])
		vm.search = 'f-3'
		expect(vm.candidates.map((invoice) => invoice.id)).toEqual(['a'])
		vm.search = '25'
		expect(vm.candidates.map((invoice) => invoice.id)).toEqual(['c'])
	})

	it('toggles selections and totals the selected invoices', () => {
		const vm = modal()
		vm.invoices = [{ id: 'a', amount: 10 }, { id: 'b', amount: 2.5 }]

		vm.toggle('a')
		vm.toggle('b')
		expect([vm.selected, vm.selectedTotal]).toEqual([['a', 'b'], 12.5])

		vm.toggle('a')
		expect([vm.selected, vm.selectedTotal]).toEqual([['b'], 2.5])
	})

	it('offers the three VAT rates', () => {
		expect(modal().vatOptions).toEqual([
			{ value: 0, label: '0%' },
			{ value: 9, label: '9%' },
			{ value: 21, label: '21%' },
		])
	})

	it('gates confirm on what each tab needs', () => {
		const vm = modal()
		expect(vm.canConfirm).toBe(false)

		vm.line = { amount: 1 }
		expect(vm.canConfirm).toBe(false)
		vm.selected = ['a']
		expect(vm.canConfirm).toBe(true)

		vm.tab = 'plan'
		expect(vm.canConfirm).toBe(false)
		vm.planId = 'pp-1'
		expect(vm.canConfirm).toBe(true)

		vm.tab = 'ledger'
		expect(vm.canConfirm).toBe(false)
		vm.account = { value: '4000' }
		expect(vm.canConfirm).toBe(true)
		vm.vatRate = { value: 21 }
		expect(vm.canConfirm).toBe(false)
		vm.vatAccount = { value: '1500' }
		expect(vm.canConfirm).toBe(true)
		vm.vatAccount = null
		vm.vatRate = { value: 0 }
		expect(vm.canConfirm).toBe(true)
	})

	it('reads incoming and outgoing lines by sign', () => {
		const vm = modal()
		expect(vm.isCredit).toBe(true)
		vm.line = { amount: '-0.01' }
		expect(vm.isCredit).toBe(false)
	})
})

describe('BankLineMatchModal pay to plan', () => {
	beforeEach(() => {
		showSuccess.mockReset()
		emit.mockReset()
		payPlanFromLine.mockReset()
	})

	it('pays the line to the chosen plan, toasts, refreshes and closes', async () => {
		payPlanFromLine.mockResolvedValue({ matchId: 'm-1' })
		const vm = modal()
		vm.line = { id: 'bl-1' }
		vm.tab = 'plan'
		vm.planId = 'pp-1'

		await vm.confirm()

		expect(payPlanFromLine).toHaveBeenCalledWith('pp-1', 'bl-1')
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Bank line paid to the payment plan.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect([vm.emitted, vm.submitting, vm.error]).toEqual([[['close', { matchId: 'm-1' }]], false, ''])
	})

	it('shows the server message, or the generic one, when paying fails', async () => {
		const errors = []
		for (const failure of [{ response: { data: { message: 'Plan closed' } } }, new Error('x')]) {
			payPlanFromLine.mockRejectedValueOnce(failure)
			const vm = modal()
			vm.line = { id: 'bl-1' }
			vm.tab = 'plan'
			vm.planId = 'pp-1'

			await vm.confirm()

			errors.push([vm.error, vm.submitting, vm.emitted.length])
		}

		expect(errors).toEqual([['Plan closed', false, 0], ['shillinq:The bank line could not be matched.', false, 0]])
	})
})
