/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the BillImportModal logic module
 * (src/modals/billImportModal.js): file-format detection, the honest
 * PDF-OCR deferral, the multipart payload shape, the review-form
 * required-field gate (REQ-BIM-003), the 409 duplicate message mapping
 * (REQ-BIM-005) and the dashboard refresh event payload (REQ-BIM-004).
 *
 * The deep NcDialog SFC + its CSS cannot be imported by the repo's
 * vitest, so the testable logic is extracted here (mirrors the
 * invoiceQuickDraft.js pattern).
 */

import { describe, it, expect } from 'vitest'
import {
	detectFormat,
	isDeferredPdf,
	buildImportFormData,
	reviewFormFromRecord,
	canSaveReview,
	importErrorMessage,
	refreshEventPayload,
	CREDITORS_WIDGET,
	PDF_DEFERRAL_MESSAGE,
} from '../../src/modals/billImportModal.js'

describe('billImportModal — format detection', () => {
	it('detects ubl from .xml / .ubl extensions', () => {
		expect(detectFormat('invoice.xml')).toBe('ubl')
		expect(detectFormat('invoice.ubl')).toBe('ubl')
	})

	it('detects csv and pdf from extensions', () => {
		expect(detectFormat('bills.csv')).toBe('csv')
		expect(detectFormat('scan.pdf')).toBe('pdf')
	})

	it('sniffs content when the name has no useful extension', () => {
		expect(detectFormat('blob', '<Invoice/>')).toBe('ubl')
		expect(detectFormat('blob', '%PDF-1.7')).toBe('pdf')
		expect(detectFormat('blob', 'a,b,c\n1,2,3')).toBe('csv')
		expect(detectFormat('blob', 'plain text')).toBe('')
	})
})

describe('billImportModal — honest PDF-OCR deferral (REQ-BIM-002)', () => {
	it('flags pdf as the deferred path', () => {
		expect(isDeferredPdf('pdf')).toBe(true)
		expect(isDeferredPdf('ubl')).toBe(false)
		expect(isDeferredPdf('csv')).toBe(false)
	})

	it('exposes the honest deferral message (no fake extraction)', () => {
		expect(PDF_DEFERRAL_MESSAGE).toContain('PDF OCR extraction is not yet available')
		expect(PDF_DEFERRAL_MESSAGE).toContain('UBL/e-invoice XML or CSV')
	})
})

describe('billImportModal — import payload', () => {
	it('builds a FormData carrying file + explicit format', () => {
		const calls = []
		class FakeFormData {
			append(k, v) { calls.push([k, v]) }
		}
		buildImportFormData({ name: 'invoice.xml' }, 'ubl', FakeFormData)
		expect(calls).toEqual([
			['file', { name: 'invoice.xml' }],
			['format', 'ubl'],
		])
	})
})

describe('billImportModal — review form (REQ-BIM-003)', () => {
	it('maps a server record into the editable form (cents -> euros)', () => {
		const form = reviewFormFromRecord({
			supplierId: 'SUP-1',
			invoiceNumber: 'INV-9',
			invoiceDate: '2026-02-01',
			totalInclVat: 12100,
			totalVat: 2100,
		})
		expect(form.supplier).toBe('SUP-1')
		expect(form.invoiceNumber).toBe('INV-9')
		expect(form.invoiceDate).toBe('2026-02-01')
		expect(form.amount).toBe(121)
		expect(form.vatAmount).toBe(21)
		expect(form.glAccount).toBe('')
	})

	it('gates save on supplier, invoiceNumber, invoiceDate and glAccount', () => {
		const base = {
			supplier: 'SUP-1',
			invoiceNumber: 'INV-9',
			invoiceDate: '2026-02-01',
			glAccount: '4000',
		}
		expect(canSaveReview(base)).toBe(true)
		expect(canSaveReview({ ...base, glAccount: '' })).toBe(false)
		expect(canSaveReview({ ...base, supplier: '   ' })).toBe(false)
		expect(canSaveReview({ ...base, invoiceNumber: '' })).toBe(false)
		expect(canSaveReview({ ...base, invoiceDate: '' })).toBe(false)
		expect(canSaveReview(null)).toBe(false)
	})
})

describe('billImportModal — duplicate 409 (REQ-BIM-005)', () => {
	it('maps a 409 to the canonical inline warning', () => {
		const msg = importErrorMessage({
			response: { status: 409, data: { error: 'This invoice number already exists for this supplier' } },
		})
		expect(msg).toBe('This invoice number already exists for this supplier')
	})

	it('falls back to the canonical text when 409 carries no body', () => {
		expect(importErrorMessage({ response: { status: 409, data: {} } }))
			.toBe('This invoice number already exists for this supplier')
	})

	it('surfaces other server errors verbatim', () => {
		expect(importErrorMessage({ response: { status: 422, data: { error: 'UBL is malformed' } } }))
			.toBe('UBL is malformed')
	})
})

describe('billImportModal — dashboard refresh (REQ-BIM-004)', () => {
	it('emits the open-creditors widget payload', () => {
		expect(CREDITORS_WIDGET).toBe('widget-open-creditors')
		expect(refreshEventPayload()).toEqual({ widget: 'widget-open-creditors' })
	})
})
