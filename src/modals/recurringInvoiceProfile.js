// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure helpers for RecurringInvoiceProfileModal (recurring-invoicing).
// Side-effect free so they are independently unit-testable. The modal
// itself only collects input and POSTs/PUTs the payload built here to
// the OpenRegister object API (ADR-022).

/**
 * A fresh, empty recurring line. quantity 1, 21% VAT.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 * @return {object} A new line.
 */
export function defaultRecurringLine() {
	return {
		description: '',
		quantity: 1,
		unitPrice: 0,
		vatCode: 21,
		revenueAccount: '',
	}
}

/**
 * Per-period net total of the lines (sum of quantity * unitPrice).
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 * @param {Array<object>} lines The recurring lines.
 * @return {number} Net total (2 decimals).
 */
export function perPeriodNet(lines) {
	let net = 0
	for (const line of lines || []) {
		net += (Number(line.quantity) || 0) * (Number(line.unitPrice) || 0)
	}
	return Math.round((net + Number.EPSILON) * 100) / 100
}

/**
 * Validate a profile draft. Returns an array of human-readable error
 * strings; empty array means valid enough to save as a draft.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 * @param {object} form The form state.
 * @return {Array<string>} Validation errors.
 */
export function validateProfile(form) {
	const errors = []
	if (!(form.name || '').trim()) {
		errors.push('A profile name is required.')
	}
	if (!(form.customerReference || '').trim()) {
		errors.push('A customer is required.')
	}
	const lines = form.lines || []
	const hasPricedLine = lines.some(
		(l) => (l.description || '').trim().length > 0 && Number(l.unitPrice) > 0,
	)
	if (!hasPricedLine) {
		errors.push('At least one line with a description and a price is required.')
	}
	if (!(form.startDate || '').match(/^\d{4}-\d{2}-\d{2}$/)) {
		errors.push('A valid start date is required.')
	}
	const day = Number(form.invoiceDay)
	if (!Number.isInteger(day) || day < 1 || day > 31) {
		errors.push('Invoice day must be between 1 and 31.')
	}
	return errors
}

/**
 * Build the RecurringInvoiceProfile payload from the form state. New
 * profiles are created in status `draft`.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 * @param {object} form The form state.
 * @return {object} The profile payload.
 */
export function buildProfilePayload(form) {
	const lines = (form.lines || [])
		.filter(
			(l) =>
				(l.description || '').trim().length > 0 || Number(l.unitPrice) > 0,
		)
		.map((l) => ({
			description: (l.description || '').trim(),
			quantity: Number(l.quantity) || 1,
			unitPrice: Number(l.unitPrice) || 0,
			vatCode: Number(l.vatCode) || 0,
			revenueAccount: l.revenueAccount || '',
		}))

	const payload = {
		name: (form.name || '').trim(),
		customerReference: (form.customerReference || '').trim(),
		lines,
		frequency: form.frequency || 'monthly',
		interval: Number(form.interval) || 1,
		startDate: form.startDate,
		invoiceDay: Number(form.invoiceDay) || 1,
		issueMode: form.issueMode || 'draft-for-review',
		deliveryChannel: form.deliveryChannel || 'email',
		paymentTermsDays: Number(form.paymentTermsDays) || 30,
		currency: form.currency || 'EUR',
		status: form.status || 'draft',
	}

	if (
		form.indexationPercent !== undefined
		&& form.indexationPercent !== null
		&& form.indexationPercent !== ''
	) {
		payload.indexationPercent = Number(form.indexationPercent)
	}
	if (form.endDate) {
		payload.endCondition = { endDate: form.endDate }
	} else if (form.occurrenceCount) {
		payload.endCondition = { occurrenceCount: Number(form.occurrenceCount) }
		payload.remainingOccurrences = Number(form.occurrenceCount)
	}

	return payload
}
