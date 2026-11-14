/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BankLineMatchModal.vue's confirm(): the match
 * body per tab, the toast for a full and a part payment, the refresh and
 * close, and the error text. Methods run bound to a fake `this` seeded from
 * the real `data()`.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BankLineMatchModal from '../../src/modals/BankLineMatchModal.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => `${app}:` + text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake modal for bank line `bl/1` with its methods bound.
 *
 * @return {object}
 */
function modal() {
	const self = { ...BankLineMatchModal.data.call({}), line: { id: 'bl/1' }, closed: [] }
	for (const [name, method] of Object.entries(BankLineMatchModal.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.closed.push(args)
	self.money = (value) => `EUR ${value}`
	return self
}

const URL = '/index.php/apps/shillinq/api/v1/bank-lines/bl%2F1/match'

describe('BankLineMatchModal confirm', () => {
	beforeEach(() => {
		axios.post.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('matches invoices, toasts, refreshes and closes', async () => {
		axios.post.mockResolvedValue({ data: { remainder: 0 } })
		const vm = modal()
		vm.tab = 'invoices'
		vm.selected = [{ id: 'inv-1', amount: 10 }]

		await vm.confirm()

		expect(axios.post).toHaveBeenCalledWith(URL, { targets: [{ id: 'inv-1', amount: 10 }] })
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Bank line matched.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect([vm.closed, vm.submitting, vm.error]).toEqual([[['close', { remainder: 0 }]], false, ''])
	})

	it('books against a ledger account and words a part payment', async () => {
		axios.post.mockResolvedValue({ data: { remainder: '12.5' } })
		const vm = modal()
		vm.tab = 'ledger'
		vm.account = { value: '4000' }
		vm.vatRate = null
		vm.vatAccount = undefined
		vm.description = 'Bankkosten'

		await vm.confirm()

		expect(axios.post).toHaveBeenCalledWith(URL, { ledgerAccount: { accountNumber: '4000', vatRate: 0, vatAccountNumber: '', description: 'Bankkosten' } })
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Part payment recorded, EUR 12.5 remains open.')
	})

	it('shows the server message, or the generic one, and does not close', async () => {
		const errors = []
		for (const failure of [{ response: { data: { message: 'Already matched' } } }, new Error('x')]) {
			axios.post.mockRejectedValueOnce(failure)
			const vm = modal()
			vm.tab = 'invoices'
			await vm.confirm()
			errors.push([vm.error, vm.closed.length, vm.submitting])
		}

		expect(errors).toEqual([['Already matched', 0, false], ['shillinq:The bank line could not be matched.', 0, false]])
		expect(emit).not.toHaveBeenCalled()
	})

	it('hands the plan tab to confirmPlan', async () => {
		const vm = modal()
		vm.tab = 'plan'
		vm.confirmPlan = vi.fn(async () => {})

		await vm.confirm()

		expect(vm.confirmPlan).toHaveBeenCalledOnce()
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.submitting).toBe(true)
	})
})
