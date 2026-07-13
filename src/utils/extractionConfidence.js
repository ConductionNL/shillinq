// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure, schema-agnostic helpers for rendering docudesk extraction confidence
// on a SupplierInvoice or Receipt draft (receipt-extraction-consume,
// REQ-RXC-001/002/003/004/006). Shared between BillImportModal
// (src/modals/billImportModal.js) and ReceiptCapture
// (src/views/receiptCapture.js) so the confidence/review-gate/correction
// semantics stay identical across both surfaces.

/**
 * Below this per-field confidence a field is flagged for review (REQ-RXC-002).
 *
 * @type {number}
 */
export const REVIEW_THRESHOLD = 0.8

/**
 * At/above this overall confidence, commit MAY be one-click; below it the
 * operator MUST review before commit (REQ-RXC-002). Confidence never
 * bypasses the explicit human confirmation itself (REQ-RXC-006).
 *
 * @type {number}
 */
export const ONE_CLICK_CONFIDENCE_GATE = 0.9

/**
 * Whether a SupplierInvoice/Receipt record is an uncommitted extraction
 * draft awaiting operator review (REQ-RXC-001).
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-001
 * @param {object} record The OR record.
 * @return {boolean}
 */
export function isExtractionDraft(record) {
	return !!record && record.extractionStatus === 'pending-review'
}

/**
 * Read a field's extraction confidence, or null when unavailable (e.g. an
 * operator-only field docudesk never extracts).
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-002
 * @param {object} record The OR record.
 * @param {string} field The field name.
 * @return {number|null}
 */
export function confidenceForField(record, field) {
	const value = record?.fieldConfidence?.[field]
	return typeof value === 'number' && Number.isFinite(value) ? value : null
}

/**
 * Whether a field was already human-corrected (REQ-RXC-004).
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-004
 * @param {object} record The OR record.
 * @param {string} field The field name.
 * @return {boolean}
 */
export function isFieldCorrected(record, field) {
	return Array.isArray(record?.humanCorrected) && record.humanCorrected.includes(field)
}

/**
 * Whether the review step must be shown (rather than allowing a one-click
 * commit) for the draft's overall confidence (REQ-RXC-002). Per REQ-RXC-006
 * this only affects how much review the UI demands — commit is always an
 * explicit human action either way.
 *
 * @spec openspec/changes/receipt-extraction-consume/specs/receipt-extraction-consume/spec.md#req-rxc-002
 * @param {object} record The OR record.
 * @return {boolean}
 */
export function requiresExplicitReview(record) {
	const overall = record?.overallConfidence
	if (typeof overall !== 'number' || !Number.isFinite(overall)) {
		return false
	}
	return overall < ONE_CLICK_CONFIDENCE_GATE
}

/**
 * Summarise a pending extraction draft for a "pending reviews" list.
 *
 * @param {object} record The draft record.
 * @param {string} labelField The field to use as the row label (e.g. 'invoiceNumber' or 'receiptNumber').
 * @return {{id: string, label: string, overallConfidence: number|null}}
 */
export function pendingDraftSummary(record, labelField = 'id') {
	const r = record || {}
	const label = String(r[labelField] || r.id || '')
	const overall = typeof r.overallConfidence === 'number' ? r.overallConfidence : null
	return { id: String(r.id ?? ''), label, overallConfidence: overall }
}
