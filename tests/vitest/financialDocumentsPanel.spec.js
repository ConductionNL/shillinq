/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the financial documents panel (REQ-FDO-003, REQ-FDO-004):
 * its registration, the kinds and acts a placement narrows it to, and the
 * two new calls it makes.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShillinqFinancialDocumentsPanel, {
	actsForRow,
	ALL_ACTIONS,
} from '../../src/integrations/ShillinqFinancialDocumentsPanel.vue'
import {
	createQuote,
	creditInvoice,
	FINANCIAL_DOCUMENTS_DATA_LEAF,
	issueInvoice,
	raisePayout,
	requestInvoicePayment,
} from '../../src/integrations/leafApi.js'
import {
	FINANCIAL_DOCUMENTS_PANEL_ID,
	registerFinancialDocumentsLeaf,
} from '../../src/integrations/registerFinancialDocumentsLeaf.js'

const ALL_ACTS = { issueInvoice: true, requestPayment: true, administerPayments: true, createQuote: true, creditInvoice: true, raisePayout: true }

const invoice = { id: 'i-1', kind: 'invoice', number: 'F-1', amount: 120, currency: 'EUR', paymentState: 'outstanding', restricted: false, path: '/apps/shillinq/bookkeeping/accounts-receivable/i-1' }
const request = { id: 'p-1', kind: 'payment-request', number: '', amount: 85, currency: 'EUR', paymentState: 'outstanding', restricted: false, path: '' }
const quote = { id: 'q-1', kind: 'quote', number: 'OFF-1', amount: null, currency: 'EUR', paymentState: 'none', restricted: false, path: '' }

describe('financial documents panel', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('registers its own id with a mount pair', () => {
		const registered = []
		registerFinancialDocumentsLeaf({ OCA: { OpenRegister: { integrations: { register: (entry) => registered.push(entry) } } } })

		expect(registered.map((entry) => entry.id)).toEqual(['shillinq-financial-documents-panel'])
		expect(FINANCIAL_DOCUMENTS_PANEL_ID).toBe('shillinq-financial-documents-panel')
		expect(registered[0].renderMode).toBe('mount')
		expect(registered[0].surfaces).toEqual(['detail-page', 'single-entity'])
		expect(FINANCIAL_DOCUMENTS_DATA_LEAF).toBe('shillinq-financial-documents')
	})

	it('offers request payment on an outstanding invoice and the payment acts on a request', () => {
		expect(actsForRow(invoice, ALL_ACTS, ALL_ACTIONS)).toEqual(['request-payment', 'credit-invoice'])
		expect(actsForRow(request, ALL_ACTS, ALL_ACTIONS)).toEqual(['send-link', 'record-payment'])
		expect(actsForRow(quote, ALL_ACTS, ALL_ACTIONS)).toEqual([])
	})

	it('offers nothing the caller does not hold, the placement leaves out, or a restricted row', () => {
		expect(actsForRow(invoice, { ...ALL_ACTS, requestPayment: false, creditInvoice: false }, ALL_ACTIONS)).toEqual([])
		expect(actsForRow(request, ALL_ACTS, ['request-payment'])).toEqual([])
		expect(actsForRow({ ...invoice, restricted: true }, ALL_ACTS, ALL_ACTIONS)).toEqual([])
		expect(actsForRow({ ...invoice, paymentState: 'paid' }, ALL_ACTS, ALL_ACTIONS)).toEqual(['credit-invoice'])
		expect(actsForRow({ ...invoice, kind: 'credit-note', paymentState: 'none' }, ALL_ACTS, ALL_ACTIONS)).toEqual([])
	})

	it('shows only the kinds the placement names', () => {
		const rows = ShillinqFinancialDocumentsPanel.computed.visibleRows.call({ rows: [invoice, request, quote], kinds: ['invoice', 'payment-request'] })

		expect(rows.map((row) => row.id)).toEqual(['i-1', 'p-1'])
	})

	it('offers issue invoice only when the caller holds it and the placement allows it', () => {
		const offers = ShillinqFinancialDocumentsPanel.methods.offers

		expect(offers.call({ acts: ALL_ACTS, actions: ALL_ACTIONS }, 'issue-invoice')).toBe(true)
		expect(offers.call({ acts: { ...ALL_ACTS, issueInvoice: false }, actions: ALL_ACTIONS }, 'issue-invoice')).toBe(false)
		expect(offers.call({ acts: ALL_ACTS, actions: ['request-payment'] }, 'issue-invoice')).toBe(false)
	})

	it('offers new quote and raise payout like issue invoice: held and allowed', () => {
		const offers = ShillinqFinancialDocumentsPanel.methods.offers

		expect(offers.call({ acts: ALL_ACTS, actions: ALL_ACTIONS }, 'new-quote')).toBe(true)
		expect(offers.call({ acts: ALL_ACTS, actions: ALL_ACTIONS }, 'raise-payout')).toBe(true)
		expect(offers.call({ acts: { ...ALL_ACTS, raisePayout: false }, actions: ALL_ACTIONS }, 'raise-payout')).toBe(false)
		expect(offers.call({ acts: ALL_ACTS, actions: ['issue-invoice'] }, 'new-quote')).toBe(false)
	})

	it('starts a quote, credits an invoice and raises a payout on the host object', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const host = { register: 'dossiq', schema: 'case', objectId: 'c-1' }

		await createQuote(host, { amount: 4500, description: 'Plein', debtor: { name: 'J', email: 'j@example.nl' } })
		await creditInvoice('i-1')
		await raisePayout(host, { amount: 357, payeeName: 'J. Burger', iban: 'NL91ABNA0417164300' })

		expect(post.mock.calls[0][0]).toContain('/apps/shillinq/api/financial-documents/quotes')
		expect(post.mock.calls[0][1]).toMatchObject({ register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 4500 })
		expect(post.mock.calls[1][0]).toContain('/apps/shillinq/api/financial-documents/invoices/i-1/credit')
		expect(post.mock.calls[2][0]).toContain('/apps/shillinq/api/financial-documents/payouts')
		expect(post.mock.calls[2][1]).toMatchObject({ objectId: 'c-1', payeeName: 'J. Burger', iban: 'NL91ABNA0417164300' })
	})

	it('titles a row by kind, number and amount, and words the payment state', () => {
		const { titleOf, stateOf } = ShillinqFinancialDocumentsPanel.methods

		expect(titleOf(invoice)).toContain('Invoice F-1')
		expect(titleOf(invoice)).toContain('120')
		expect(titleOf(quote)).toBe('Quote OFF-1')
		expect(stateOf(invoice)).toBe('Outstanding')
		expect(stateOf(quote)).toBe('')
	})

	it('issues an invoice on the host object and requests payment of an invoice', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } })

		await issueInvoice({ register: 'dossiq', schema: 'case', objectId: 'c-1' }, { amount: 250, description: 'Leges', debtor: { name: 'J', email: 'j@example.nl' }, objectType: 'case' })
		await requestInvoicePayment('i-1')

		expect(post.mock.calls[0][0]).toContain('/apps/shillinq/api/financial-documents/invoices')
		expect(post.mock.calls[0][1]).toEqual({ register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 250, description: 'Leges', debtor: { name: 'J', email: 'j@example.nl' }, objectType: 'case' })
		expect(post.mock.calls[1][0]).toContain('/apps/shillinq/api/financial-documents/invoices/i-1/payment-request')
	})
})
