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

/** The draft checks, in the order their errors are listed: [passes(form), error]. */
const PROFILE_CHECKS = [
	[hasName, 'A profile name is required.'],
	[hasCustomer, 'A customer is required.'],
	[hasPricedLine, 'At least one line with a description and a price is required.'],
	[hasStartDate, 'A valid start date is required.'],
	[validInvoiceDay, 'Invoice day must be between 1 and 31.'],
]

/**
 * Validate a profile draft. Returns an array of human-readable error
 * strings; empty array means valid enough to save as a draft.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 * @param {object} form The form state.
 * @return {Array<string>} Validation errors.
 */
export function validateProfile(form) {
	return PROFILE_CHECKS.filter(([passes]) => !passes(form)).map(
		([, message]) => message,
	)
}

/**
 * Whether a text field holds more than whitespace.
 *
 * @param {string|undefined|null} value The field.
 * @return {boolean}
 */
function filled(value) {
	return (value || '').trim().length > 0
}

/**
 * Whether the draft is named.
 *
 * @param {object} form The form state.
 * @return {boolean}
 */
function hasName(form) {
	return filled(form.name)
}

/**
 * Whether the draft names a customer.
 *
 * @param {object} form The form state.
 * @return {boolean}
 */
function hasCustomer(form) {
	return filled(form.customerReference)
}

/**
 * Whether the start date reads as YYYY-MM-DD.
 *
 * @param {object} form The form state.
 * @return {boolean}
 */
function hasStartDate(form) {
	return Boolean((form.startDate || '').match(/^\d{4}-\d{2}-\d{2}$/))
}

/**
 * Whether the draft has a line with a description and a positive price.
 *
 * @param {object} form The form state.
 * @return {boolean}
 */
function hasPricedLine(form) {
	return (form.lines || []).some(
		(l) => filled(l.description) && Number(l.unitPrice) > 0,
	)
}

/**
 * Whether the invoice day is a whole day of the month, 1 to 31.
 *
 * @param {object} form The form state.
 * @return {boolean}
 */
function validInvoiceDay(form) {
	const day = Number(form.invoiceDay)
	return Number.isInteger(day) && day >= 1 && day <= 31
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
	return {
		name: (form.name || '').trim(),
		customerReference: (form.customerReference || '').trim(),
		lines: payloadLines(form.lines),
		frequency: form.frequency || 'monthly',
		interval: Number(form.interval) || 1,
		startDate: form.startDate,
		invoiceDay: Number(form.invoiceDay) || 1,
		issueMode: form.issueMode || 'draft-for-review',
		deliveryChannel: form.deliveryChannel || 'email',
		paymentTermsDays: Number(form.paymentTermsDays) || 30,
		currency: form.currency || 'EUR',
		status: form.status || 'draft',
		...indexationFields(form),
		...endConditionFields(form),
	}
}

/**
 * The payload lines: lines with a description or a positive price,
 * normalised (quantity defaults to 1).
 *
 * @param {Array<object>|undefined} lines The form lines.
 * @return {Array<object>} The payload lines.
 */
function payloadLines(lines) {
	return (lines || [])
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
}

/**
 * The indexationPercent field, present only when the form sets one.
 *
 * @param {object} form The form state.
 * @return {object} `{ indexationPercent }` or an empty object.
 */
function indexationFields(form) {
	const percent = form.indexationPercent
	if (percent === undefined || percent === null || percent === '') {
		return {}
	}
	return { indexationPercent: Number(percent) }
}

/**
 * The end condition: an end date wins over an occurrence count, which also
 * sets remainingOccurrences. Neither set gives an empty object.
 *
 * @param {object} form The form state.
 * @return {object} The endCondition (and remainingOccurrences) fields.
 */
function endConditionFields(form) {
	if (form.endDate) {
		return { endCondition: { endDate: form.endDate } }
	}
	if (form.occurrenceCount) {
		const count = Number(form.occurrenceCount)
		return {
			endCondition: { occurrenceCount: count },
			remainingOccurrences: count,
		}
	}
	return {}
}
