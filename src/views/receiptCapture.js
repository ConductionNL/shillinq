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
		receiptNumber: String(r.receiptNumber ?? ''),
		photoUri: String(r.photoUri ?? ''),
		amount: typeof r.amount === 'number' ? r.amount : Number(r.amount) || 0,
		currency: String(r.currency ?? 'EUR'),
		amountInBaseCurrency:
			typeof r.amountInBaseCurrency === 'number'
				? r.amountInBaseCurrency
				: Number(r.amountInBaseCurrency) || 0,
		exchangeRate:
			r.exchangeRate === null || r.exchangeRate === undefined
				? null
				: Number(r.exchangeRate),
		receiptDate: String(r.receiptDate ?? ''),
		category: String(r.category ?? ''),
		extractedText: String(r.extractedText ?? ''),
		vendorName: String(r.vendorName ?? ''),
		description: String(r.description ?? ''),
		costCentreCode: String(r.costCentreCode ?? ''),
		claimId: String(r.claimId ?? ''),
		// gl-account-suggestion-consume (REQ-GAC-003) — the booking account,
		// mirroring SupplierInvoice.glAccount; never auto-filled from a
		// suggestion without an explicit operator action (REQ-GAC-004).
		glAccount: String(r.glAccount ?? ''),
	}
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
