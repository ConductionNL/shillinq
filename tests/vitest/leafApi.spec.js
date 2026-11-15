/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the leaf API client: every call targets the right URL with
 * the host identity and payload, and reads the server's envelope — returning
 * an empty object when the server sends no body. Plus the two pure helpers:
 * `formatAmount` (the Dutch currency line, its non-numeric and bad-currency
 * fallbacks) and `isResolvable`. Only HTTP is replaced.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	createQuote,
	creditInvoice,
	formatAmount,
	hostIdentity,
	isResolvable,
	issueInvoice,
	raisePayout,
	readLeaf,
	requestInvoicePayment,
	sendPaymentLink,
	settleByOtherMeans,
} from '../../src/integrations/leafApi.js'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => `/index.php${path.replace(/{(\w+)}/g, (m, k) => (k in params ? params[k] : m))}`,
}))

const HOST = { register: 'dossiq', schema: 'case', objectId: 'c-1' }

describe('leaf API client', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads a leaf envelope off the host object', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [1] } })

		const out = await readLeaf(HOST, 'shillinq-payment-requests')

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/case/c-1/integrations/shillinq-payment-requests')
		expect(out).toEqual({ items: [1] })
	})

	it('returns an empty object when the server sends no body', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: null })

		expect(await readLeaf(HOST, 'leaf')).toEqual({})
		expect(await sendPaymentLink('p-1')).toEqual({})
		expect(await settleByOtherMeans('p-1', { method: 'cash' })).toEqual({})
		expect(await issueInvoice(HOST, { amount: 5 })).toEqual({})
		expect(await requestInvoicePayment('i-1')).toEqual({})
		expect(await createQuote(HOST, { amount: 9 })).toEqual({})
		expect(await creditInvoice('i-1')).toEqual({})
		expect(await raisePayout(HOST, { amount: 3 })).toEqual({})
		expect(post).toHaveBeenCalled()
	})

	it('sends the payment link and settles through the shillinq routes', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } })

		await sendPaymentLink('p-7')
		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/payment-requests/p-7/send', {})

		const settlement = { method: 'cash', settlementReference: 'KAS-1' }
		expect(await settleByOtherMeans('p-7', settlement)).toEqual({ ok: true })
		expect(post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/payment-requests/p-7/settle', settlement)
	})

	it('posts the host identity with the invoice, quote and payout bodies', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { invoice: { id: 'i-9' } } })

		await issueInvoice(HOST, { amount: 50, description: 'Leges' })
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/financial-documents/invoices',
			{ register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 50, description: 'Leges' },
		)

		await createQuote(HOST, { amount: 9 })
		expect(post).toHaveBeenLastCalledWith(
			'/index.php/apps/shillinq/api/financial-documents/quotes',
			{ register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 9 },
		)

		await raisePayout(HOST, { amount: 3, iban: 'NL00' })
		expect(post).toHaveBeenLastCalledWith(
			'/index.php/apps/shillinq/api/financial-documents/payouts',
			{ register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 3, iban: 'NL00' },
		)
	})

	it('credits an invoice and requests its payment by id', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		await creditInvoice('i-3')
		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/financial-documents/invoices/i-3/credit', {})

		await requestInvoicePayment('i-3')
		expect(post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/financial-documents/invoices/i-3/payment-request', {})
	})

	describe('formatAmount', () => {
		it('writes a Dutch currency line, defaulting the currency to euro', () => {
			expect(formatAmount(85, 'EUR')).toMatch(/85/)
			// No currency given → euro default, same as an explicit EUR.
			expect(formatAmount(85, '')).toBe(formatAmount(85, 'EUR'))
		})

		it('is empty for a non-numeric amount', () => {
			expect(formatAmount('not a number', 'EUR')).toBe('')
			expect(formatAmount(Infinity, 'EUR')).toBe('')
		})

		it('falls back to a plain "CODE amount" line when the currency code is invalid', () => {
			// An invalid ISO code makes Intl.NumberFormat throw; the catch arm
			// returns the code and the fixed-point amount.
			expect(formatAmount(12.5, 'NOT-A-CURRENCY')).toBe('NOT-A-CURRENCY 12.50')
		})
	})

	describe('identity helpers', () => {
		it('prefers discrete props, falling back to integrationContext', () => {
			expect(hostIdentity({ register: 'r', schema: 's', objectId: 'o' })).toEqual({ register: 'r', schema: 's', objectId: 'o' })
			expect(hostIdentity({ integrationContext: { register: 'r2', schema: 's2', objectId: 'o2' } })).toEqual({ register: 'r2', schema: 's2', objectId: 'o2' })
			expect(hostIdentity({})).toEqual({ register: '', schema: '', objectId: '' })
		})

		it('is resolvable only when all three parts are present', () => {
			expect(isResolvable({ register: 'r', schema: 's', objectId: 'o' })).toBe(true)
			expect(isResolvable({ register: 'r', schema: '', objectId: 'o' })).toBe(false)
			expect(isResolvable({ register: '', schema: '', objectId: '' })).toBe(false)
		})
	})
})
