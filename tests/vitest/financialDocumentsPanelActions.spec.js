/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for what the financial documents panel does (REQ-FDO-003,
 * REQ-FDO-004): it reads the rows and the caller's grants off the host
 * object, offers its top acts to a card, submits the issue-invoice, new-quote
 * and raise-payout forms with the host and the form's fields, runs the row
 * acts, and reads again after each success or words the refusal. Methods and
 * computed run against a fake instance; only HTTP is replaced.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShillinqFinancialDocumentsPanel, {
	ALL_ACTIONS,
	ALL_KINDS,
} from '../../src/integrations/ShillinqFinancialDocumentsPanel.vue'

const HOST = { register: 'dossiq', schema: 'case', objectId: 'c-1' }
const GRANTED = {
	issueInvoice: true,
	requestPayment: true,
	administerPayments: true,
	createQuote: true,
	creditInvoice: true,
	raisePayout: true,
}
const invoice = { id: 'i-1', kind: 'invoice', number: 'F-1', amount: 120, currency: 'EUR', paymentState: 'outstanding', restricted: false, path: '/apps/shillinq/bookkeeping/accounts-receivable/i-1', dueOn: '2026-12-31' }

/**
 * A fake panel instance: its props, data, own and mixin methods, and computed
 * as getters.
 *
 * @param {object} props The props.
 * @return {object} The instance.
 */
function panel(props = {}) {
	const component = ShillinqFinancialDocumentsPanel
	const full = { ...HOST, integrationContext: {}, kinds: ALL_KINDS, actions: ALL_ACTIONS, objectType: 'case', ...props }
	const vm = { $props: full, ...full, onHeader: null, onSummary: null }
	Object.assign(vm, component.data.call(vm))
	for (const part of [...component.mixins, component]) {
		for (const [name, fn] of Object.entries(part.methods || {})) {
			vm[name] = fn.bind(vm)
		}
		for (const [name, getter] of Object.entries(part.computed || {})) {
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
	}
	return vm
}

describe('financial documents panel actions', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the rows and merges the caller\'s grants over the closed defaults', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [invoice], acts: { issueInvoice: true } } })
		const vm = panel()

		await vm.load()

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/case/c-1/integrations/shillinq-financial-documents')
		expect(vm.rows).toEqual([invoice])
		expect(vm.acts.issueInvoice).toBe(true)
		expect(vm.acts.raisePayout).toBe(false)
	})

	it('keeps no rows and no grants from an envelope without them, and words a failed read', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { items: null } }).mockRejectedValueOnce(new Error('500'))
		const vm = panel()

		await vm.load()
		expect(vm.rows).toEqual([])
		expect(Object.values(vm.acts).every((granted) => granted === false)).toBe(true)

		await vm.load()
		expect(vm.error).toBe('The financial documents could not be read.')
	})

	it('offers a card only the top acts the caller holds, each opening its form', () => {
		const vm = panel({ actions: ['issue-invoice', 'raise-payout'] })
		vm.acts = { ...GRANTED, raisePayout: false }
		vm.rows = [invoice, { id: 'x', kind: 'payout', number: '', amount: null, paymentState: 'none' }]

		const report = vm.leafReport

		expect(report.actions.map((action) => action.label)).toEqual(['Issue invoice'])
		expect(report.count).toBe(2)
		expect(report.rows.map((row) => row.label)).toEqual([`${vm.titleOf(invoice)} · Outstanding`, 'Payout'])
		vm.form.amount = '12'
		report.actions[0].run()
		expect(vm.formFor).toBe('issue-invoice')
		expect(vm.form).toEqual({ amount: '', description: '', name: '', email: '', iban: '' })
	})

	it('issues an invoice for the host with the form\'s debtor, then reads again', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [invoice] } })
		const vm = panel()
		vm.openForm('issue-invoice')
		Object.assign(vm.form, { amount: '120.50', description: 'Leges', name: 'J. Burger', email: 'j@example.nl' })

		await vm.submitForm()

		expect(post.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/financial-documents/invoices')
		expect(post.mock.calls[0][1]).toMatchObject({
			...HOST,
			amount: 120.5,
			description: 'Leges',
			objectType: 'case',
			debtor: { name: 'J. Burger', email: 'j@example.nl' },
		})
		expect(vm.formFor).toBe('')
		expect(vm.notices.form).toBe('The invoice is issued.')
		expect(get).toHaveBeenCalledTimes(1)
		expect(vm.busyId).toBe('')
	})

	it('starts a quote and raises a payout with the payee and IBAN', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const vm = panel()

		vm.openForm('new-quote')
		Object.assign(vm.form, { amount: '4500', name: 'J', email: 'j@example.nl' })
		await vm.submitForm()
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/financial-documents/quotes')
		expect(post.mock.calls[0][1].debtor).toEqual({ name: 'J', email: 'j@example.nl' })
		expect(vm.notices.form).toBe('The quote is started.')

		vm.openForm('raise-payout')
		Object.assign(vm.form, { amount: '357', name: 'J. Burger', iban: 'NL91ABNA0417164300' })
		await vm.submitForm()
		expect(post.mock.calls[1][0]).toBe('/index.php/apps/shillinq/api/financial-documents/payouts')
		expect(post.mock.calls[1][1]).toMatchObject({ amount: 357, payeeName: 'J. Burger', iban: 'NL91ABNA0417164300' })
		expect(post.mock.calls[1][1]).not.toHaveProperty('debtor')
		expect(vm.notices.form).toBe('The payout is raised.')
	})

	it('keeps a refused form open with the server\'s reason', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { error: 'You may not issue invoices.' } } })
		const get = vi.spyOn(axios, 'get')
		const vm = panel()
		vm.openForm('issue-invoice')

		await vm.submitForm()

		expect(vm.formFor).toBe('issue-invoice')
		expect(vm.notices.form).toBe('You may not issue invoices.')
		expect(get).not.toHaveBeenCalled()
		expect(vm.busyId).toBe('')
	})

	it('credits an invoice, requests its payment and sends a link, reading again after each', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const vm = panel()

		await vm.credit(invoice)
		expect(vm.notices['i-1']).toBe('The credit note is issued.')
		await vm.requestPayment(invoice)
		expect(vm.notices['i-1']).toBe('The payment request is raised.')
		await vm.send({ id: 'p-1' })
		expect(vm.notices['p-1']).toBe('The payment link went out to the debtor.')

		expect(post.mock.calls.map(([url]) => url)).toEqual([
			'/index.php/apps/shillinq/api/financial-documents/invoices/i-1/credit',
			'/index.php/apps/shillinq/api/financial-documents/invoices/i-1/payment-request',
			'/index.php/apps/shillinq/api/payment-requests/p-1/send',
		])
		expect(get).toHaveBeenCalledTimes(3)
		expect(vm.busyId).toBe('')
	})

	it('records a payment for the whole request and closes the settlement form', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const vm = panel()
		vm.openSettle({ id: 'p-1' })
		vm.settleForm.method = 'cash'
		vm.settleForm.settlementReference = 'KAS-7'

		await vm.settle({ id: 'p-1' })

		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/payment-requests/p-1/settle', {
			method: 'cash',
			settlementReference: 'KAS-7',
			reason: '',
			amount: 0,
		})
		expect(vm.notices['p-1']).toBe('The payment is on the record.')
		expect(vm.settleFor).toBe('')
	})

	it('words a refused row act plainly when the server names no reason', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('network'))
		const get = vi.spyOn(axios, 'get')
		const vm = panel()

		await vm.credit(invoice)

		expect(vm.notices['i-1']).toBe('That did not go through.')
		expect(get).not.toHaveBeenCalled()
		expect(vm.busyId).toBe('')
	})

	it('words the confirm button per form, the due date, and links a row to its page', () => {
		const vm = panel()
		vm.acts = GRANTED

		expect(vm.confirmLabelFor('issue-invoice')).toBe('Issue the invoice')
		expect(vm.confirmLabelFor('new-quote')).toBe('Start the quote')
		expect(vm.confirmLabelFor('raise-payout')).toBe('Raise the payout')
		expect(vm.confirmLabelFor('credit-invoice')).toBe('')
		expect(vm.dueLine(invoice)).toBe('Due 2026-12-31')
		expect(vm.dueLine({})).toBe('')
		expect(vm.pageOf(invoice)).toBe('/index.php/apps/shillinq/bookkeeping/accounts-receivable/i-1')
		expect(vm.rowActs(invoice)).toEqual(['request-payment', 'credit-invoice'])
		expect(vm.offers('credit-invoice')).toBe(false)
	})
})
