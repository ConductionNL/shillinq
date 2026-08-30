/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the ReceiptCapture logic module
 * (src/views/receiptCapture.js): the review-form mapping, the required-field
 * save gate, and the correction-commit payload shape (REQ-RXC-003 / REQ-RXC-004).
 */

import { describe, it, expect } from 'vitest'
import {
	reviewFormFromReceipt,
	canSaveReceipt,
	buildReceiptConfirmPayload,
	receiptErrorMessage,
} from '../../src/views/receiptCapture.js'

describe('receiptCapture — review form (REQ-RXC-003)', () => {
	it('maps a Receipt record into the editable form', () => {
		const form = reviewFormFromReceipt({
			amount: 45,
			currency: 'EUR',
			receiptDate: '2026-02-10',
			category: 'meals',
			extractedText: 'LUNCH CAFE DE JONG',
			vendorName: 'Cafe de Jong',
		})
		expect(form.amount).toBe(45)
		expect(form.currency).toBe('EUR')
		expect(form.receiptDate).toBe('2026-02-10')
		expect(form.category).toBe('meals')
		expect(form.extractedText).toBe('LUNCH CAFE DE JONG')
		expect(form.vendorName).toBe('Cafe de Jong')
	})

	it('defaults currency to EUR and other fields to empty', () => {
		const form = reviewFormFromReceipt({})
		expect(form.currency).toBe('EUR')
		expect(form.amount).toBe(0)
		expect(form.category).toBe('')
	})
})

describe('receiptCapture — save gate (REQ-EC-002 required fields)', () => {
	const base = {
		amount: 45,
		currency: 'EUR',
		receiptDate: '2026-02-10',
		category: 'meals',
	}

	it('allows save when amount/currency/receiptDate/category are present', () => {
		expect(canSaveReceipt(base)).toBe(true)
	})

	it('rejects a negative amount', () => {
		expect(canSaveReceipt({ ...base, amount: -1 })).toBe(false)
	})

	it('rejects missing currency, receiptDate or category', () => {
		expect(canSaveReceipt({ ...base, currency: '' })).toBe(false)
		expect(canSaveReceipt({ ...base, receiptDate: '' })).toBe(false)
		expect(canSaveReceipt({ ...base, category: '   ' })).toBe(false)
	})

	it('rejects a null form', () => {
		expect(canSaveReceipt(null)).toBe(false)
	})
})

describe('receiptCapture — review form field parity with the native ReceiptDetail page', () => {
	it('maps receiptNumber, photoUri, amountInBaseCurrency, exchangeRate, costCentreCode and claimId too', () => {
		const form = reviewFormFromReceipt({
			receiptNumber: 'REC-1',
			photoUri: 'docudesk://attachments/x/receipt.jpg',
			amountInBaseCurrency: 41.4,
			exchangeRate: 0.92,
			costCentreCode: 'CC100',
			claimId: 'claim-1',
		})
		expect(form.receiptNumber).toBe('REC-1')
		expect(form.photoUri).toBe('docudesk://attachments/x/receipt.jpg')
		expect(form.amountInBaseCurrency).toBe(41.4)
		expect(form.exchangeRate).toBe(0.92)
		expect(form.costCentreCode).toBe('CC100')
		expect(form.claimId).toBe('claim-1')
	})

	it('leaves exchangeRate null when the record has none (already in base currency)', () => {
		expect(reviewFormFromReceipt({}).exchangeRate).toBeNull()
	})
})

describe('receiptCapture — correction commit payload (REQ-RXC-004)', () => {
	it('builds a plain field payload without confidence/provenance keys', () => {
		const payload = buildReceiptConfirmPayload({
			receiptNumber: 'REC-1',
			photoUri: 'docudesk://attachments/x/receipt.jpg',
			amount: 45,
			currency: 'EUR',
			amountInBaseCurrency: 45,
			exchangeRate: null,
			receiptDate: '2026-02-10',
			category: 'meals',
			extractedText: 'text',
			vendorName: 'Cafe de Jong',
			description: 'lunch',
			costCentreCode: 'CC100',
			claimId: '',
			glAccount: '4400',
		})
		expect(payload).toEqual({
			receiptNumber: 'REC-1',
			photoUri: 'docudesk://attachments/x/receipt.jpg',
			amount: 45,
			currency: 'EUR',
			amountInBaseCurrency: 45,
			exchangeRate: null,
			receiptDate: '2026-02-10',
			category: 'meals',
			extractedText: 'text',
			vendorName: 'Cafe de Jong',
			description: 'lunch',
			costCentreCode: 'CC100',
			claimId: '',
			glAccount: '4400',
		})
		expect(payload.fieldConfidence).toBeUndefined()
	})
})

describe('receiptCapture — GL account (gl-account-suggestion-consume)', () => {
	it('maps glAccount from the record, defaulting to empty', () => {
		expect(reviewFormFromReceipt({ glAccount: '4400' }).glAccount).toBe('4400')
		expect(reviewFormFromReceipt({}).glAccount).toBe('')
	})
})

describe('receiptCapture — error mapping', () => {
	it('prefers the server error message', () => {
		expect(
			receiptErrorMessage({
				response: { data: { error: 'Draft not found' } },
			}),
		).toBe('Draft not found')
	})

	it('falls back to a generic message', () => {
		expect(receiptErrorMessage({})).toBe('Failed to load or save the receipt.')
	})
})
