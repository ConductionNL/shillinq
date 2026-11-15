// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure logic helpers for ReceiptCapture (receipt-extraction-consume,
// REQ-RXC-003). Kept out of the .vue file so they are independently
// unit-testable, mirroring src/modals/billImportModal.js.

/**
 * Map a Receipt OR record into the editable review form shape (REQ-RXC-003).
 *
 * Covers the same field set the native `type: "detail"` ReceiptDetail page
 * rendered (receiptNumber, photoUri, exchangeRate, costCentreCode, claimId)
 * plus the extraction-review fields, so overlaying this custom component
 * (REQ-RXC-003/004/005) does not drop any field the operator could
 * previously see/edit — only the Files/Audit-trail sidebar tabs are not
 * reproduced here (documented limitation; the OR audit trail itself is
 * unaffected — it is recorded server-side regardless of which UI renders
 * the object).
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {object} record The Receipt record.
 * @return {object} The review form values.
 */
export function reviewFormFromReceipt(record) {
	const r = record || {}
	return {
		receiptNumber: textOf(r.receiptNumber),
		photoUri: textOf(r.photoUri),
		amount: amountOf(r.amount),
		currency: textOf(r.currency, 'EUR'),
		amountInBaseCurrency: amountOf(r.amountInBaseCurrency),
		exchangeRate: optionalNumber(r.exchangeRate),
		receiptDate: textOf(r.receiptDate),
		category: textOf(r.category),
		extractedText: textOf(r.extractedText),
		vendorName: textOf(r.vendorName),
		description: textOf(r.description),
		costCentreCode: textOf(r.costCentreCode),
		claimId: textOf(r.claimId),
		// gl-account-suggestion-consume (REQ-GAC-003) — the booking account,
		// mirroring SupplierInvoice.glAccount; never auto-filled from a
		// suggestion without an explicit operator action (REQ-GAC-004).
		glAccount: textOf(r.glAccount),
	}
}

/**
 * A record field as text, the fallback when it is null or undefined.
 *
 * @param {unknown} value The field.
 * @param {string} [fallback] The text for a missing field.
 * @return {string}
 */
function textOf(value, fallback = '') {
	return String(value ?? fallback)
}

/**
 * A record amount as a number: a number as is, anything else coerced, 0 when
 * that is not a number.
 *
 * @param {unknown} value The field.
 * @return {number}
 */
function amountOf(value) {
	return typeof value === 'number' ? value : Number(value) || 0
}

/**
 * A record number that may be absent: null when null or undefined, else
 * coerced.
 *
 * @param {unknown} value The field.
 * @return {number|null}
 */
function optionalNumber(value) {
	return value === null || value === undefined ? null : Number(value)
}

/**
 * Whether the review form satisfies the Receipt schema's required fields
 * (amount, currency, receiptDate, category — REQ-EC-002) before commit.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {object} form The review form values.
 * @return {boolean} True when all required fields are present.
 */
export function canSaveReceipt(form) {
	const f = form || {}
	if (Number.isFinite(Number(f.amount)) === false || Number(f.amount) < 0) {
		return false
	}
	return ['currency', 'receiptDate', 'category'].every(
		(k) => String(f[k] ?? '').trim().length > 0,
	)
}

/**
 * Build the correction-commit payload PUT to the extraction confirm
 * endpoint (REQ-RXC-004). Only the operator-editable Receipt fields are
 * included — confidence/provenance bookkeeping fields are server-computed.
 *
 * @param {object} form The review form values.
 * @return {object} The payload to PUT.
 */
export function buildReceiptConfirmPayload(form) {
	const f = form || {}
	return {
		receiptNumber: f.receiptNumber,
		photoUri: f.photoUri,
		amount: Number(f.amount) || 0,
		currency: f.currency,
		amountInBaseCurrency: Number(f.amountInBaseCurrency) || 0,
		exchangeRate:
			f.exchangeRate === null
			|| f.exchangeRate === undefined
			|| f.exchangeRate === ''
				? null
				: Number(f.exchangeRate),
		receiptDate: f.receiptDate,
		category: f.category,
		extractedText: f.extractedText,
		vendorName: f.vendorName,
		description: f.description,
		costCentreCode: f.costCentreCode,
		claimId: f.claimId,
		glAccount: f.glAccount,
	}
}

/**
 * Extract a user-facing error message from an axios error.
 *
 * @param {object} error An axios-style error.
 * @return {string} The message to show inline.
 */
export function receiptErrorMessage(error) {
	return (
		error?.response?.data?.error
		|| error?.response?.data?.message
		|| error?.message
		|| 'Failed to load or save the receipt.'
	)
}
