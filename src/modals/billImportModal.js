// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure logic helpers for BillImportModal (shillinq-bill-import-modal).
// Kept out of the .vue file so they are independently unit-testable
// (the repo's vitest cannot import the deep NcDialog SFC + its CSS).
//
// Covers: file-format detection, the multipart import payload shape, the
// review-form required-field gate (REQ-BIM-003), the honest PDF-OCR
// deferral notice (REQ-BIM-002), the 409 duplicate message mapping
// (REQ-BIM-005) and the dashboard refresh event payload (REQ-BIM-004).

/**
 * The widget id the dashboard payables tile listens on (REQ-BIM-004).
 *
 * @type {string}
 */
export const CREDITORS_WIDGET = 'widget-open-creditors'

/**
 * The honest, fixed message shown when a PDF is selected/uploaded —
 * there is no OCR engine bundled with this change (REQ-BIM-002).
 *
 * @type {string}
 */
export const PDF_DEFERRAL_MESSAGE =
	'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.'

/**
 * Detect the import format from a file name (falling back to a content
 * sniff). Returns 'ubl' | 'csv' | 'pdf' | '' (unknown).
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {string} fileName The file name (may be empty).
 * @param {string} [contents] Optional raw text for the sniff fallback.
 * @return {string} The detected format.
 */
export function detectFormat(fileName, contents = '') {
	const ext = String(fileName || '')
		.split('.')
		.pop()
		.toLowerCase()
	if (ext === 'xml' || ext === 'ubl') return 'ubl'
	if (ext === 'csv') return 'csv'
	if (ext === 'pdf') return 'pdf'

	const trimmed = String(contents || '').replace(/^\s+/, '')
	if (trimmed.startsWith('%PDF')) return 'pdf'
	if (trimmed.startsWith('<')) return 'ubl'
	if (trimmed && trimmed.includes(',')) return 'csv'
	return ''
}

/**
 * Whether a selected file is the honestly-deferred PDF-OCR path
 * (REQ-BIM-002). Used to short-circuit the upload and show the deferral
 * notice instead of POSTing a fabricated extraction.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {string} format The detected format.
 * @return {boolean} True when the format is pdf.
 */
export function isDeferredPdf(format) {
	return format === 'pdf'
}

/**
 * Build the multipart FormData posted to the import endpoint. The format
 * is always sent explicitly so the server does not have to re-sniff.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {File|Blob} file The selected file.
 * @param {string} format The detected format.
 * @param {function} [FormDataCtor] Optional FormData constructor (test seam).
 * @return {object} The FormData (or a plain shape when FormData is absent).
 */
export function buildImportFormData(file, format, FormDataCtor) {
	const Ctor = FormDataCtor || (typeof FormData !== 'undefined' ? FormData : null)
	if (Ctor) {
		const fd = new Ctor()
		fd.append('file', file)
		fd.append('format', format)
		return fd
	}
	// Fallback for environments without FormData (kept testable).
	return { file, format }
}

/**
 * Map a parsed/extracted server record into the editable review form
 * shape (REQ-BIM-003). Monetary fields arrive as integer cents from the
 * server and are presented as decimal euros.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {object} record The persisted SupplierInvoice record.
 * @return {object} The review form values.
 */
export function reviewFormFromRecord(record) {
	const r = record || {}
	return {
		supplier: String(r.supplierId ?? r.supplier ?? ''),
		invoiceNumber: String(r.invoiceNumber ?? ''),
		invoiceDate: String(r.invoiceDate ?? ''),
		amount: centsToEuro(r.totalInclVat),
		vatAmount: centsToEuro(r.totalVat),
		glAccount: String(r.glAccount ?? ''),
	}
}

/**
 * Convert integer euro cents to a decimal-euro number (null/'' → 0).
 *
 * @param {number|null} cents Integer cents.
 * @return {number} Euros.
 */
function centsToEuro(cents) {
	if (cents === null || cents === undefined || cents === '') return 0
	const n = Number(cents)
	return Number.isFinite(n) ? n / 100 : 0
}

/**
 * Whether the review form satisfies the required fields before save
 * (REQ-BIM-003): supplier, invoiceNumber, invoiceDate and glAccount.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {object} form The review form values.
 * @return {boolean} True when all required fields are present.
 */
export function canSaveReview(form) {
	const f = form || {}
	return ['supplier', 'invoiceNumber', 'invoiceDate', 'glAccount'].every(
		(k) => String(f[k] ?? '').trim().length > 0,
	)
}

/**
 * Extract a user-facing error message from an axios error, mapping the
 * 409 duplicate response to the canonical inline warning (REQ-BIM-005).
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @param {object} error An axios-style error.
 * @return {string} The message to show inline.
 */
export function importErrorMessage(error) {
	const status = error?.response?.status
	if (status === 409) {
		return (
			error?.response?.data?.error
			|| 'This invoice number already exists for this supplier'
		)
	}
	return (
		error?.response?.data?.error
		|| error?.response?.data?.message
		|| error?.message
		|| 'Import failed.'
	)
}

/**
 * The event-bus payload the dashboard payables widget listens for
 * (REQ-BIM-004).
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @return {{widget: string}} The refresh event payload.
 */
export function refreshEventPayload() {
	return { widget: CREDITORS_WIDGET }
}

// ---------------------------------------------------------------------------
// receipt-extraction-consume (REQ-RXC-002) — extraction prefill, per-field
// confidence display, and correction-flow helpers. The schema-agnostic
// pieces live in ../utils/extractionConfidence.js (shared with
// ../views/receiptCapture.js); re-exported here so existing BillImportModal
// imports/tests keep working from one place.
// ---------------------------------------------------------------------------

export {
	confidenceForField,
	glAccountSuggestionSummary,
	hasKnownExtractionId,
	isExtractionDraft,
	isFieldCorrected,
	ONE_CLICK_CONFIDENCE_GATE,
	requiresExplicitReview,
	REVIEW_THRESHOLD,
} from '../utils/extractionConfidence.js'

/**
 * Map a pending SupplierInvoice extraction draft into the review-form shape
 * (REQ-RXC-002) — the same shape as `reviewFormFromRecord`, extraction drafts
 * and manually-parsed UBL/CSV records share one review step.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {object} record The extraction-draft SupplierInvoice record.
 * @return {object} The review form values.
 */
export function reviewFormFromDraft(record) {
	return reviewFormFromRecord(record)
}

/**
 * Summarise a pending SupplierInvoice extraction draft for the "pending
 * reviews" list (invoice-number/supplier label + overall confidence).
 *
 * @param {object} record The draft record.
 * @return {{id: string, label: string, overallConfidence: number|null}}
 */
export function pendingDraftSummary(record) {
	const r = record || {}
	const label = String(r.invoiceNumber || r.supplierId || r.id || '')
	const overall =
		typeof r.overallConfidence === 'number' ? r.overallConfidence : null
	return { id: String(r.id ?? ''), label, overallConfidence: overall }
}
