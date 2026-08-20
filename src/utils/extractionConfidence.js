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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
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
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {object} record The OR record.
 * @param {string} field The field name.
 * @return {boolean}
 */
export function isFieldCorrected(record, field) {
	return (
		Array.isArray(record?.humanCorrected)
		&& record.humanCorrected.includes(field)
	)
}

/**
 * Whether the review step must be shown (rather than allowing a one-click
 * commit) for the draft's overall confidence (REQ-RXC-002). Per REQ-RXC-006
 * this only affects how much review the UI demands — commit is always an
 * explicit human action either way.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
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
	const overall =
		typeof r.overallConfidence === 'number' ? r.overallConfidence : null
	return { id: String(r.id ?? ''), label, overallConfidence: overall }
}

// ---------------------------------------------------------------------------
// gl-account-suggestion-consume (REQ-GAC-001/003/006) — shared GL-account
// suggestion helpers. Schema-agnostic (used by both BillImportModal and
// ReceiptCapture) so the two surfaces render/reuse a suggestion identically.
// ---------------------------------------------------------------------------

/**
 * Whether a draft has a known docudesk extraction id — the ONLY signal that
 * a GL-account suggestion can be requested for it (REQ-GAC-001). Absence is
 * NOT an error — it is the honest "docudesk was never asked for this draft"
 * case that degrades to plain manual booking (REQ-GAC-006).
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @param {object} record The OR record.
 * @return {boolean}
 */
export function hasKnownExtractionId(record) {
	return (
		typeof record?.docudeskExtractionId === 'string'
		&& record.docudeskExtractionId.trim().length > 0
	)
}

/**
 * Normalise a `{suggestion: {...}|null, reason?: string}` proxy response
 * into a renderable summary, or null when there is nothing to show
 * (REQ-GAC-006 — absence, not an error).
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @param {object} response The `suggestGlAccount` proxy response body.
 * @return {{code: string, label: string, confidence: number|null, rationale: string, source: string}|null}
 */
export function glAccountSuggestionSummary(response) {
	const suggestion = response?.suggestion
	if (!suggestion || typeof suggestion !== 'object') {
		return null
	}

	const code = String(suggestion.code ?? '').trim()
	if (!code) {
		return null
	}

	return {
		code,
		label: String(suggestion.label ?? ''),
		confidence:
			typeof suggestion.confidence === 'number' ? suggestion.confidence : null,
		rationale: String(suggestion.rationale ?? ''),
		source: String(suggestion.source ?? 'none'),
	}
}
