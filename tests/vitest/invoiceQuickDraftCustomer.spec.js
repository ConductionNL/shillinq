/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins what choosing a customer in the quick-draft dialog does to the form:
 * the customer's default GL account, the stored per-customer preferences on
 * the first line, and the due date from the payment terms.
 *
 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import InvoiceQuickDraftModal from '../../src/modals/InvoiceQuickDraftModal.vue'
import { saveQuickDraftPrefs } from '../../src/modals/invoiceQuickDraft.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

/**
 * A fake dialog instance with the given form and bound methods.
 *
 * @param {object} form The form state.
 * @return {object}
 */
function vm(form) {
	const ctx = { selectedCustomer: 'old', form }
	for (const [name, fn] of Object.entries(InvoiceQuickDraftModal.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * A fresh form with one line.
 *
 * @param {object} line Line overrides.
 * @param {string} glAccount The form's GL account.
 * @return {object}
 */
function form(line = {}, glAccount = '') {
	return {
		invoiceDate: '2026-01-10',
		dueDate: 'unset',
		reference: '',
		glAccount,
		lines: [{ description: '', quantity: 1, unitPrice: 0, vatRate: 21, ...line }],
	}
}

const CUSTOMER = { customerId: 'c-1', paymentTerms: 'net14', defaultGlAccount: 8010 }

describe('InvoiceQuickDraftModal — onCustomerSelected', () => {
	beforeEach(() => {
		const store = {}
		globalThis.localStorage = {
			getItem: (k) => (k in store ? store[k] : null),
			setItem: (k, v) => {
				store[k] = String(v)
			},
			removeItem: (k) => {
				delete store[k]
			},
		}
	})

	it('clears the selection and leaves the form alone without a customer', () => {
		for (const option of [null, undefined, { value: 'c-1' }]) {
			const ctx = vm(form())
			ctx.onCustomerSelected(option)
			expect(ctx.selectedCustomer).toBe(option || null)
			expect(ctx.form).toEqual(form())
		}
	})

	it('takes the default GL account only into an empty field, then the due date', () => {
		const ctx = vm(form())
		const option = { value: 'c-1', customer: CUSTOMER }
		ctx.onCustomerSelected(option)
		expect(ctx.selectedCustomer).toBe(option)
		expect(ctx.form.glAccount).toBe('8010')
		expect(ctx.form.dueDate).not.toBe('unset')
		const kept = vm(form({}, '7000'))
		kept.onCustomerSelected(option)
		expect(kept.form.glAccount).toBe('7000')
	})

	it('applies stored preferences over the default and only into blank line fields', () => {
		saveQuickDraftPrefs('c-1', { glAccount: 8100, description: 'Hosting', unitPrice: 49, vatCode: 0 })
		const blank = vm(form())
		blank.onCustomerSelected({ value: 'c-1', customer: CUSTOMER })
		expect(blank.form.glAccount).toBe('8100')
		expect(blank.form.lines[0]).toEqual({ description: 'Hosting', quantity: 1, unitPrice: 49, vatRate: 0 })

		const filled = vm(form({ description: 'Mine', unitPrice: 5, vatRate: 9 }, '7000'))
		filled.onCustomerSelected({ value: 'c-1', customer: CUSTOMER })
		expect(filled.form.glAccount).toBe('8100')
		expect(filled.form.lines[0]).toEqual({ description: 'Mine', quantity: 1, unitPrice: 5, vatRate: 0 })
	})

	it('ignores empty preference values and copes with no lines', () => {
		saveQuickDraftPrefs('c-1', { glAccount: '', description: '', unitPrice: 0, vatCode: null })
		const ctx = vm(form({}, ''))
		ctx.onCustomerSelected({ value: 'c-1', customer: { customerId: 'c-1' } })
		expect(ctx.form.glAccount).toBe('')
		expect(ctx.form.lines[0]).toEqual(form().lines[0])

		saveQuickDraftPrefs('c-2', { glAccount: '8200', description: 'X' })
		const none = vm({ ...form(), lines: [] })
		none.onCustomerSelected({ value: 'c-2', customer: { customerId: 'c-2' } })
		expect(none.form.glAccount).toBe('8200')
		expect(none.form.lines).toEqual([])
	})
})
