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

import { describe, expect, it } from 'vitest'
import {
	buildImportFormData,
	canSaveReview,
	confidenceForField,
	CREDITORS_WIDGET,
	detectFormat,
	glAccountSuggestionSummary,
	hasKnownExtractionId,
	importErrorMessage,
	isDeferredPdf,
	isExtractionDraft,
	isFieldCorrected,
	ONE_CLICK_CONFIDENCE_GATE,
	PDF_DEFERRAL_MESSAGE,
	pendingDraftSummary,
	refreshEventPayload,
	requiresExplicitReview,
	REVIEW_THRESHOLD,
	reviewFormFromRecord,
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
		expect(PDF_DEFERRAL_MESSAGE).toContain(
			'PDF OCR extraction is not yet available',
		)
		expect(PDF_DEFERRAL_MESSAGE).toContain('UBL/e-invoice XML or CSV')
	})
})

describe('billImportModal — import payload', () => {
	it('builds a FormData carrying file + explicit format', () => {
		const calls = []
		class FakeFormData {
			append(k, v) {
				calls.push([k, v])
			}
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
			response: {
				status: 409,
				data: {
					error: 'This invoice number already exists for this supplier',
				},
			},
		})
		expect(msg).toBe('This invoice number already exists for this supplier')
	})

	it('falls back to the canonical text when 409 carries no body', () => {
		expect(importErrorMessage({ response: { status: 409, data: {} } })).toBe(
			'This invoice number already exists for this supplier',
		)
	})

	it('surfaces other server errors verbatim', () => {
		expect(
			importErrorMessage({
				response: { status: 422, data: { error: 'UBL is malformed' } },
			}),
		).toBe('UBL is malformed')
	})
})

describe('billImportModal — dashboard refresh (REQ-BIM-004)', () => {
	it('emits the open-creditors widget payload', () => {
		expect(CREDITORS_WIDGET).toBe('widget-open-creditors')
		expect(refreshEventPayload()).toEqual({ widget: 'widget-open-creditors' })
	})
})

describe('billImportModal — extraction confidence (REQ-RXC-001/002/004/006)', () => {
	it('recognises an uncommitted extraction draft', () => {
		expect(isExtractionDraft({ extractionStatus: 'pending-review' })).toBe(true)
		expect(isExtractionDraft({ extractionStatus: 'confirmed' })).toBe(false)
		expect(isExtractionDraft(null)).toBe(false)
	})

	it('reads per-field confidence, null when absent', () => {
		const record = { fieldConfidence: { invoiceNumber: 0.97 } }
		expect(confidenceForField(record, 'invoiceNumber')).toBe(0.97)
		expect(confidenceForField(record, 'glAccount')).toBeNull()
		expect(confidenceForField(null, 'invoiceNumber')).toBeNull()
	})

	it('flags a field as human-corrected only when listed', () => {
		const record = { humanCorrected: ['glAccount'] }
		expect(isFieldCorrected(record, 'glAccount')).toBe(true)
		expect(isFieldCorrected(record, 'invoiceNumber')).toBe(false)
		expect(isFieldCorrected(null, 'glAccount')).toBe(false)
	})

	it('gates explicit review on overallConfidence below the one-click threshold', () => {
		expect(REVIEW_THRESHOLD).toBe(0.8)
		expect(ONE_CLICK_CONFIDENCE_GATE).toBe(0.9)
		expect(requiresExplicitReview({ overallConfidence: 0.61 })).toBe(true)
		expect(requiresExplicitReview({ overallConfidence: 0.93 })).toBe(false)
		expect(requiresExplicitReview({ overallConfidence: 0.9 })).toBe(false)
		expect(requiresExplicitReview({})).toBe(false)
	})

	it('summarises a pending draft for the review list', () => {
		expect(
			pendingDraftSummary({
				id: 'd1',
				invoiceNumber: 'F-88',
				overallConfidence: 0.93,
			}),
		).toEqual({ id: 'd1', label: 'F-88', overallConfidence: 0.93 })
		expect(pendingDraftSummary({ id: 'd2', supplierId: 'ACME' }).label).toBe(
			'ACME',
		)
	})
})

describe('billImportModal — GL-account suggestion (REQ-GAC-001/003/006)', () => {
	it('recognises a draft with a known docudesk extraction id', () => {
		expect(hasKnownExtractionId({ docudeskExtractionId: 'ext-123' })).toBe(true)
		expect(hasKnownExtractionId({ docudeskExtractionId: '' })).toBe(false)
		expect(hasKnownExtractionId({ docudeskExtractionId: '   ' })).toBe(false)
		expect(hasKnownExtractionId({})).toBe(false)
		expect(hasKnownExtractionId(null)).toBe(false)
	})

	it('summarises a history-backed suggestion response', () => {
		const summary = glAccountSuggestionSummary({
			suggestion: {
				code: '4300',
				label: 'Kantoorkosten',
				confidence: 0.8,
				rationale:
					'Booked to 4300 in 8 of the last 10 invoices from this supplier',
				source: 'history',
			},
		})
		expect(summary).toEqual({
			code: '4300',
			label: 'Kantoorkosten',
			confidence: 0.8,
			rationale:
				'Booked to 4300 in 8 of the last 10 invoices from this supplier',
			source: 'history',
		})
	})

	it('degrades to null for a graceful "no suggestion" response (REQ-GAC-006)', () => {
		expect(
			glAccountSuggestionSummary({
				suggestion: null,
				reason: 'extraction-id-unknown',
			}),
		).toBeNull()
		expect(
			glAccountSuggestionSummary({
				suggestion: null,
				reason: 'provider-unavailable',
			}),
		).toBeNull()
		expect(glAccountSuggestionSummary({})).toBeNull()
		expect(glAccountSuggestionSummary(null)).toBeNull()
	})

	it('degrades to null when the suggestion carries no usable code', () => {
		expect(glAccountSuggestionSummary({ suggestion: { code: '' } })).toBeNull()
		expect(glAccountSuggestionSummary({ suggestion: {} })).toBeNull()
	})
})
