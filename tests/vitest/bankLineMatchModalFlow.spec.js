/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BankLineMatchModal.vue beyond confirm(): loading the line (by id, by line
 * key, not found), the invoices and AP transactions it can match, the
 * accounts, the payment plans, the candidate order and search, what may be
 * confirmed per tab, paying a plan, and each tab rendered server-side.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { payPlanFromLine, planCandidates } from '../../src/utils/paymentPlanApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/utils/paymentPlanApi.js', () => ({ planCandidates: vi.fn(), payPlanFromLine: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'label', 'inputLabel', 'variant', 'modelValue', 'value', 'type', 'options'],
		render() {
			return h(tag, { 'data-stub': name, 'data-testid': this.$attrs['data-testid'], 'data-variant': this.variant, 'aria-selected': this.$attrs['aria-selected'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
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

const { default: BankLineMatchModal } = await import('../../src/modals/BankLineMatchModal.vue')

const LINE = { id: 'bl-1', amount: 121, administrationId: 'adm-1', valueDate: '2026-11-02', counterpartyName: 'Hostbaar', remittanceInfo: 'Factuur 2026-0412' }

/**
 * A fake modal with its methods and computed bound.
 *
 * @param {object} over Props and data.
 * @return {object}
 */
function modal(over = {}) {
	const self = { lineId: 'bl-1', ...BankLineMatchModal.data.call({}), closed: [], ...over }
	for (const [name, method] of Object.entries(BankLineMatchModal.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(BankLineMatchModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	self.$emit = (...args) => self.closed.push(args)
	return self
}

/**
 * Render the modal with its data laid over.
 *
 * @param {object} state Data to lay over.
 * @return {Promise<string>} The HTML.
 */
async function render(state) {
	const view = { ...BankLineMatchModal, mounted() {}, data: () => ({ ...BankLineMatchModal.data(), ...state }) }
	return (await renderToString(createSSRApp(view, { lineId: 'bl-1' }))).replace(/<!--[[\]]-->/g, '')
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	showSuccess.mockReset()
	emit.mockReset()
	planCandidates.mockReset()
	payPlanFromLine.mockReset()
})

describe('BankLineMatchModal loading', () => {
	it('reads the line by its object id', async () => {
		axios.get.mockResolvedValueOnce({ data: LINE })
		const vm = modal()

		await vm.loadLine()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/BankStatementLine/bl-1')
		expect([vm.line, vm.loadError]).toEqual([LINE, ''])
	})

	it('falls back to the line key, then says the line was not found', async () => {
		axios.get.mockRejectedValueOnce(new Error('404')).mockResolvedValueOnce({ data: { results: [LINE] } })
		const found = modal()
		await found.loadLine()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/shillinq/BankStatementLine', { params: { lineId: 'bl-1', _limit: 1 } })
		expect(found.line).toEqual(LINE)

		axios.get.mockRejectedValueOnce(new Error('404')).mockResolvedValueOnce({ data: { results: [] } })
		const empty = modal()
		await empty.loadLine()
		expect([empty.line, empty.loadError]).toEqual([null, 'Bank line not found'])

		axios.get.mockRejectedValueOnce(new Error('404')).mockRejectedValueOnce(new Error('500'))
		const failed = modal()
		await failed.loadLine()
		expect([failed.line, failed.loadError]).toEqual([null, 'Bank line not found'])
	})

	it('lists the open sales invoices for money in', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 'i1', invoiceNumber: 'F-2', buyerName: 'Hostbaar', lifecycleState: 'issued', amountDue: 121 },
					{ id: 'i2', customerId: 'c-9', lifecycleState: 'overdue', grossAmount: '50' },
					{ id: 'i3', invoiceNumber: 'F-3', lifecycleState: 'paid', amountDue: 10 },
				],
			},
		})
		const vm = modal({ line: LINE })

		await vm.loadInvoices()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/ARInvoice', { params: { administrationId: 'adm-1', _limit: 500 } })
		expect(vm.invoices).toEqual([
			{ id: 'i1', number: 'F-2', counterparty: 'Hostbaar', amount: 121 },
			{ id: 'i2', number: 'i2', counterparty: 'c-9', amount: 50 },
		])
	})

	it('lists the open AP transactions for money out', async () => {
		axios.get.mockResolvedValueOnce({
			data: [
				{ id: 'a1', invoiceNumber: 'IN-7', vendorId: 'v-1', state: 'partially-paid', totalAmount: 30 },
				{ id: 'a2', state: 'paid', totalAmount: 30 },
				{ id: 'a3', state: 'issued' },
			],
		})
		const vm = modal({ line: { ...LINE, amount: -30 } })

		await vm.loadInvoices()

		expect(axios.get.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/shillinq/APTransaction')
		expect(vm.invoices).toEqual([
			{ id: 'a1', number: 'IN-7', counterparty: 'v-1', amount: 30 },
			{ id: 'a3', number: 'a3', counterparty: '', amount: 0 },
		])
	})

	it('reads the accounts of the administration, and treats a non-list answer as none', async () => {
		axios.get.mockResolvedValueOnce({ data: { objects: [{ accountNumber: '4000', name: 'Kosten' }, { accountNumber: '1500' }] } })
		const vm = modal({ line: LINE })
		await vm.loadAccounts()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Account', { params: { administrationId: 'adm-1', _limit: 1000 } })
		expect(vm.accountOptions).toEqual([{ value: '4000', label: '4000 Kosten' }, { value: '1500', label: '1500' }])

		axios.get.mockResolvedValueOnce({ data: { results: 'none' } })
		await vm.loadAccounts()
		expect(vm.accounts).toEqual([])
	})

	it('preselects a plan whose reference was found, and only for money in', async () => {
		planCandidates.mockResolvedValueOnce([{ planId: 'p1', confidence: 'high' }, { planId: 'p2', confidence: 'low' }])
		const credit = modal({ line: LINE })
		await credit.loadPlans()
		expect(planCandidates).toHaveBeenCalledWith('bl-1')
		expect(credit.planId).toBe('p1')

		planCandidates.mockResolvedValueOnce([{ planId: 'p2', confidence: 'low' }])
		const unsure = modal({ line: LINE })
		await unsure.loadPlans()
		expect([unsure.plans.length, unsure.planId]).toEqual([1, ''])

		planCandidates.mockRejectedValueOnce(new Error('down'))
		const failed = modal({ line: LINE })
		await failed.loadPlans()
		expect(failed.plans).toEqual([])

		const debit = modal({ line: { ...LINE, amount: -5 } })
		await debit.loadPlans()
		expect(planCandidates).toHaveBeenCalledTimes(3)
	})

	it('loads the lists only once the line is found', async () => {
		const vm = modal()
		const calls = []
		vm.loadLine = async () => {
			calls.push('line')
		}
		vm.loadInvoices = async () => calls.push('invoices')
		vm.loadAccounts = async () => calls.push('accounts')
		vm.loadPlans = async () => calls.push('plans')

		await BankLineMatchModal.mounted.call(vm)
		expect(calls).toEqual(['line'])

		vm.line = LINE
		await BankLineMatchModal.mounted.call(vm)
		expect(calls).toEqual(['line', 'line', 'invoices', 'accounts', 'plans'])
	})
})

describe('BankLineMatchModal choosing', () => {
	it('puts exact amounts first, then by number, and searches number, counterparty and amount', () => {
		const vm = modal({
			line: LINE,
			invoices: [
				{ id: 'b', number: 'F-9', counterparty: 'Alfa', amount: 10 },
				{ id: 'a', number: 'F-1', counterparty: 'Beta', amount: 20 },
				{ id: 'c', number: 'F-5', counterparty: 'Hostbaar', amount: 121 },
			],
		})

		expect(vm.candidates.map((row) => row.id)).toEqual(['c', 'a', 'b'])
		vm.search = ' host '
		expect(vm.candidates.map((row) => row.id)).toEqual(['c'])
		vm.search = 'f-9'
		expect(vm.candidates.map((row) => row.id)).toEqual(['b'])
		vm.search = '20'
		expect(vm.candidates.map((row) => row.id)).toEqual(['a'])
	})

	it('toggles a selection and totals it', () => {
		const vm = modal({ invoices: [{ id: 'a', amount: 20 }, { id: 'b', amount: 1.5 }] })
		vm.toggle('a')
		vm.toggle('b')
		expect(vm.selectedTotal).toBe(21.5)
		vm.toggle('a')
		expect([vm.selected, vm.selectedTotal]).toEqual([['b'], 1.5])
	})

	it('summarises the line, without the parts it lacks', () => {
		expect(modal().lineSummary).toBe('')
		expect(modal({ line: LINE }).lineSummary).toBe('2026-11-02 · Hostbaar · EUR 121.00 · Factuur 2026-0412')
		expect(modal({ line: { amount: -3.5, reference: 'R-1' } }).lineSummary).toBe('EUR 3.50 · R-1')
	})

	it('allows confirming only with something chosen on the open tab', () => {
		expect(modal().canConfirm).toBe(false)
		const vm = modal({ line: LINE })
		expect(vm.canConfirm).toBe(false)
		vm.selected = ['a']
		expect(vm.canConfirm).toBe(true)

		vm.tab = 'plan'
		expect(vm.canConfirm).toBe(false)
		vm.planId = 'p1'
		expect(vm.canConfirm).toBe(true)

		vm.tab = 'ledger'
		expect(vm.canConfirm).toBe(false)
		vm.account = { value: '4000' }
		expect(vm.canConfirm).toBe(true)
		vm.vatRate = { value: 21 }
		expect(vm.canConfirm).toBe(false)
		vm.vatAccount = { value: '1500' }
		expect(vm.canConfirm).toBe(true)
		vm.vatRate = { value: 0 }
		vm.vatAccount = null
		expect(vm.canConfirm).toBe(true)
	})

	it('offers 0, 9 and 21 percent VAT and counts zero as money in', () => {
		expect(modal().vatOptions).toEqual([{ value: 0, label: '0%' }, { value: 9, label: '9%' }, { value: 21, label: '21%' }])
		expect(modal().isCredit).toBe(true)
		expect(modal({ line: { amount: '-0.01' } }).isCredit).toBe(false)
	})
})

describe('BankLineMatchModal paying a plan', () => {
	it('pays the chosen plan, toasts, refreshes and closes', async () => {
		payPlanFromLine.mockResolvedValueOnce({ planId: 'p1' })
		const vm = modal({ line: LINE, tab: 'plan', planId: 'p1' })

		await vm.confirm()

		expect(payPlanFromLine).toHaveBeenCalledWith('p1', 'bl-1')
		expect(axios.post).not.toHaveBeenCalled()
		expect(showSuccess).toHaveBeenCalledWith('Bank line paid to the payment plan.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect([vm.closed, vm.submitting]).toEqual([[['close', { planId: 'p1' }]], false])
	})

	it('shows the server message, or the generic one, and stays open', async () => {
		payPlanFromLine.mockRejectedValueOnce({ response: { data: { message: 'Plan is settled' } } }).mockRejectedValueOnce(new Error('x'))
		const vm = modal({ line: LINE, tab: 'plan', planId: 'p1' })

		await vm.confirm()
		expect(vm.error).toBe('Plan is settled')
		await vm.confirm()
		expect([vm.error, vm.closed, vm.submitting]).toEqual(['The bank line could not be matched.', [], false])
	})
})

describe('BankLineMatchModal rendered', () => {
	it('shows the line and its invoices, with nothing confirmable yet', async () => {
		const html = await render({ line: LINE, invoices: [{ id: 'i1', number: 'F-2', counterparty: 'Hostbaar', amount: 121 }] })

		expect(html).toContain('2026-11-02 · Hostbaar · EUR 121.00 · Factuur 2026-0412')
		expect(html).toContain('data-testid="bank-line-match-invoice-F-2"')
		expect(html).toContain('F-2 · Hostbaar ·')
		expect(html).toContain('Selected: EUR 0.00')
		expect(html).toContain('data-testid="bank-line-match-tab-plan"')
		expect(html).toMatch(/data-testid="bank-line-match-confirm"[^>]*disabled/)
	})

	it('says so when no invoice is open, and offers no plan tab for money out', async () => {
		const html = await render({ line: { ...LINE, amount: -4 } })
		expect(html).toContain('No open invoices found.')
		expect(html).not.toContain('data-testid="bank-line-match-tab-plan"')
	})

	it('shows the load error without a line', async () => {
		const html = await render({ loadError: 'Bank line not found' })
		expect(html).toMatch(/role="alert"[^>]*>Bank line not found/)
	})

	it('lists the plans with how each was found, or says none fits', async () => {
		const html = await render({
			line: LINE,
			tab: 'plan',
			planId: 'p1',
			plans: [
				{ planId: 'p1', planNumber: 'BR-1', customerName: 'Jansen', nextDueAmount: 50, confidence: 'high' },
				{ planId: 'p2', planNumber: 'BR-2', customerName: 'Pietersen', nextDueAmount: 25, confidence: 'medium' },
			],
		})
		expect(html).toContain('BR-1 · Jansen · EUR 50.00 · Reference found')
		expect(html).toContain('BR-2 · Pietersen · EUR 25.00 · Amount and account fit')
		expect(html).not.toMatch(/data-testid="bank-line-match-confirm"[^>]*disabled/)

		expect(await render({ line: LINE, tab: 'plan' })).toContain('No active payment plan fits this line.')
	})

	it('asks for a VAT account only with a VAT rate above zero, and shows an error', async () => {
		const ledger = await render({ line: LINE, tab: 'ledger', account: { value: '4000' } })
		expect(ledger).toContain('data-testid="bank-line-match-account"')
		expect(ledger).not.toContain('data-testid="bank-line-match-vat-account"')

		const withVat = await render({ line: LINE, tab: 'ledger', vatRate: { value: 21 }, error: 'Too large' })
		expect(withVat).toContain('data-testid="bank-line-match-vat-account"')
		expect(withVat).toMatch(/data-testid="bank-line-match-error"[^>]*>Too large/)
	})
})
