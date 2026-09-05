/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the InvoiceQuickDraftModal computation + persistence
 * layer (src/modals/invoiceQuickDraft.js): line totals, due-date from
 * payment terms, the draft ARInvoice payload shape and the
 * localStorage preference round-trip with TTL expiry.
 */

import { beforeEach, describe, expect, it } from 'vitest'
import {
	buildInvoicePayload,
	computeTotals,
	defaultDraftLine,
	dueDateFromTerms,
	loadQuickDraftPrefs,
	paymentTermDays,
	periodIdFromDate,
	provisionalInvoiceNumber,
	saveQuickDraftPrefs,
} from '../../src/modals/invoiceQuickDraft.js'

describe('invoiceQuickDraft — totals', () => {
	it('defaultDraftLine seeds qty 1 and 21% VAT', () => {
		expect(defaultDraftLine()).toEqual({
			description: '',
			quantity: 1,
			unitPrice: 0,
			vatRate: 21,
		})
	})

	it('computes net, VAT and gross across mixed VAT rates', () => {
		const lines = [
			{ quantity: 2, unitPrice: 100, vatRate: 21 }, // net 200, vat 42
			{ quantity: 1, unitPrice: 50, vatRate: 9 }, // net 50, vat 4.5
			{ quantity: 3, unitPrice: 10, vatRate: 0 }, // net 30, vat 0
		]
		const totals = computeTotals(lines)
		expect(totals.net).toBe(280)
		expect(totals.vat).toBe(46.5)
		expect(totals.gross).toBe(326.5)
	})

	it('treats missing/invalid fields as zero', () => {
		expect(computeTotals([{ description: 'x' }])).toEqual({
			net: 0,
			vat: 0,
			gross: 0,
		})
		expect(computeTotals([])).toEqual({ net: 0, vat: 0, gross: 0 })
		expect(computeTotals(null)).toEqual({ net: 0, vat: 0, gross: 0 })
	})
})

describe('invoiceQuickDraft — payment terms + due date', () => {
	it('parses day counts from term strings', () => {
		expect(paymentTermDays('net30')).toBe(30)
		expect(paymentTermDays('net 14')).toBe(14)
		expect(paymentTermDays('60')).toBe(60)
		expect(paymentTermDays('')).toBe(30)
		expect(paymentTermDays(undefined)).toBe(30)
	})

	it('computes a due date from invoice date + terms', () => {
		expect(dueDateFromTerms('2026-01-01', 'net30')).toBe('2026-01-31')
		expect(dueDateFromTerms('2026-01-15', 'net14')).toBe('2026-01-29')
	})

	it('returns empty string for missing/invalid invoice date', () => {
		expect(dueDateFromTerms('', 'net30')).toBe('')
		expect(dueDateFromTerms('not-a-date', 'net30')).toBe('')
	})
})

describe('invoiceQuickDraft — payload', () => {
	it('always builds a draft ARInvoice with normalised lines', () => {
		const payload = buildInvoicePayload({
			customerId: 'cust-1',
			invoiceDate: '2026-02-01',
			dueDate: '2026-03-03',
			reference: 'PO-42',
			glAccount: '8000',
			lines: [
				{
					description: ' Consulting ',
					quantity: 2,
					unitPrice: 100,
					vatRate: 21,
				},
				{ description: '', quantity: 0, unitPrice: 0, vatRate: 21 }, // dropped
			],
		})
		expect(payload.lifecycleState).toBe('draft')
		expect(payload.customerId).toBe('cust-1')
		expect(payload.customerReference).toBe('PO-42')
		expect(payload.currency).toBe('EUR')
		expect(payload.netAmount).toBe(200)
		expect(payload.vatAmount).toBe(42)
		expect(payload.grossAmount).toBe(242)
		expect(payload.lines).toHaveLength(1)
		expect(payload.lines[0]).toEqual({
			lineNumber: 1,
			description: 'Consulting',
			quantity: 2,
			unitPrice: 100,
			vatRate: 21,
			glAccount: '8000',
		})
	})

	it('always supplies the schema-required invoiceNumber, administrationId and periodId', () => {
		const payload = buildInvoicePayload({
			customerId: 'cust-1',
			invoiceDate: '2026-02-01',
			dueDate: '2026-03-03',
			administrationId: 'ADM-001',
			lines: [{ description: 'X', quantity: 1, unitPrice: 10, vatRate: 21 }],
		})
		expect(payload.administrationId).toBe('ADM-001')
		expect(payload.periodId).toBe('2026-02')
		expect(payload.invoiceNumber).toMatch(/^DRAFT-20260201-\d{6}$/)
	})

	it('honours explicit invoiceNumber and periodId when provided', () => {
		const payload = buildInvoicePayload({
			customerId: 'cust-1',
			invoiceDate: '2026-02-01',
			dueDate: '2026-03-03',
			administrationId: 'ADM-001',
			invoiceNumber: 'F2026-007',
			periodId: '2026-Q1',
			lines: [{ description: 'X', quantity: 1, unitPrice: 10, vatRate: 21 }],
		})
		expect(payload.invoiceNumber).toBe('F2026-007')
		expect(payload.periodId).toBe('2026-Q1')
	})
})

describe('invoiceQuickDraft — period + provisional number helpers', () => {
	it('derives the YYYY-MM period bucket from the invoice date', () => {
		expect(periodIdFromDate('2026-07-09')).toBe('2026-07')
		expect(periodIdFromDate('2026-12-31')).toBe('2026-12')
		expect(periodIdFromDate('')).toBe('')
		expect(periodIdFromDate('not-a-date')).toBe('')
	})

	it('builds a unique, provisional draft invoice number', () => {
		const num = provisionalInvoiceNumber(
			'2026-07-09',
			new Date(2026, 6, 9, 8, 30, 5),
		)
		expect(num).toBe('DRAFT-20260709-083005')
	})

	it('falls back to a DRAFT prefix when the date is missing', () => {
		const num = provisionalInvoiceNumber('', new Date(2026, 6, 9, 8, 30, 5))
		expect(num).toBe('DRAFT-DRAFT-083005')
	})
})

describe('invoiceQuickDraft — preferences persistence', () => {
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

	it('round-trips per-customer preferences', () => {
		saveQuickDraftPrefs('cust-1', {
			glAccount: '8000',
			vatCode: 9,
			description: 'Hosting',
			unitPrice: 49,
		})
		const prefs = loadQuickDraftPrefs('cust-1')
		expect(prefs.glAccount).toBe('8000')
		expect(prefs.vatCode).toBe(9)
		expect(prefs.description).toBe('Hosting')
		expect(prefs.unitPrice).toBe(49)
	})

	it('returns null for an unknown customer', () => {
		expect(loadQuickDraftPrefs('nobody')).toBeNull()
		expect(loadQuickDraftPrefs('')).toBeNull()
	})

	it('expires preferences older than 90 days', () => {
		saveQuickDraftPrefs('cust-1', { glAccount: '8000' })
		const raw = JSON.parse(
			globalThis.localStorage.getItem('shillinq:invoice-quick-draft:cust-1'),
		)
		raw.savedAt = Date.now() - 91 * 24 * 60 * 60 * 1000
		globalThis.localStorage.setItem(
			'shillinq:invoice-quick-draft:cust-1',
			JSON.stringify(raw),
		)
		expect(loadQuickDraftPrefs('cust-1')).toBeNull()
	})

	it('never throws when localStorage is unavailable', () => {
		globalThis.localStorage = undefined
		expect(() =>
			saveQuickDraftPrefs('cust-1', { glAccount: '8000' }),
		).not.toThrow()
		expect(loadQuickDraftPrefs('cust-1')).toBeNull()
	})
})
