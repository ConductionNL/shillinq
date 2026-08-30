// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure computation + persistence helpers for InvoiceQuickDraftModal
// (shillinq-invoice-quick-draft). Everything here is side-effect free
// except the localStorage helpers, which guard against a missing or
// throwing localStorage so the modal works in any environment. Kept out
// of the .vue file so it is independently unit-testable.

const PREFS_KEY_PREFIX = 'shillinq:invoice-quick-draft:'
const PREFS_TTL_MS = 90 * 24 * 60 * 60 * 1000 // 90 days (REQ-IQD-004)

/**
 * A fresh, empty draft line. quantity defaults to 1, VAT to the Dutch
 * high rate (21%).
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @return {object} A new line object.
 */
export function defaultDraftLine() {
	return {
		description: '',
		quantity: 1,
		unitPrice: 0,
		vatRate: 21,
	}
}

/**
 * Round a number to 2 decimals (currency cents), avoiding binary
 * floating-point drift.
 *
 * @param {number} value Raw value.
 * @return {number} Rounded to 2 decimals.
 */
function round2(value) {
	return Math.round((Number(value) + Number.EPSILON) * 100) / 100
}

/**
 * Compute net, VAT and gross totals from the line items. Each line's
 * net is quantity * unitPrice; VAT applies its vatRate percentage.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @param {Array<object>} lines The draft lines.
 * @return {{net: number, vat: number, gross: number}} The totals.
 */
export function computeTotals(lines) {
	let net = 0
	let vat = 0
	for (const line of lines || []) {
		const qty = Number(line.quantity) || 0
		const price = Number(line.unitPrice) || 0
		const lineNet = qty * price
		const rate = Number(line.vatRate) || 0
		net += lineNet
		vat += lineNet * (rate / 100)
	}
	net = round2(net)
	vat = round2(vat)
	return { net, vat, gross: round2(net + vat) }
}

/**
 * Parse a payment-terms string (`net14` / `net 30` / `30` / ...) into a
 * day count. Falls back to 30 when nothing parseable is found.
 *
 * @param {string} terms The payment-terms string.
 * @return {number} Net days.
 */
export function paymentTermDays(terms) {
	const match = String(terms || '').match(/(\d+)/)
	return match ? parseInt(match[1], 10) : 30
}

/**
 * Compute a due date string (YYYY-MM-DD) from an invoice date plus the
 * customer's payment terms.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @param {string} invoiceDate ISO date (YYYY-MM-DD).
 * @param {string} terms Payment terms (e.g. `net30`).
 * @return {string} The due date, or '' when invoiceDate is invalid.
 */
export function dueDateFromTerms(invoiceDate, terms) {
	if (!invoiceDate) return ''
	// Parse as a UTC calendar date so the day-arithmetic below is free of
	// local-timezone DST/offset drift (toISOString() reads UTC fields).
	const m = String(invoiceDate).match(/^(\d{4})-(\d{2})-(\d{2})/)
	if (!m) return ''
	const base = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])))
	if (Number.isNaN(base.getTime())) return ''
	base.setUTCDate(base.getUTCDate() + paymentTermDays(terms))
	return base.toISOString().slice(0, 10)
}

/**
 * Derive the fiscal-period id (`YYYY-MM`) an invoice date falls in. The
 * ARInvoice schema requires a periodId; it is stored as a free-text
 * period key (no FiscalPeriod FK is enforced), so the year-month bucket
 * of the invoice date is a safe, deterministic default.
 *
 * @param {string} invoiceDate ISO date (YYYY-MM-DD).
 * @return {string} The period id, or '' when invoiceDate is invalid.
 */
export function periodIdFromDate(invoiceDate) {
	const m = String(invoiceDate || '').match(/^(\d{4})-(\d{2})/)
	return m ? `${m[1]}-${m[2]}` : ''
}

/**
 * Provisional invoice number for a quick draft. The backend does not
 * auto-assign one and the schema requires it, so a draft gets a unique,
 * clearly-provisional number (`DRAFT-<invoiceDate>-<HHmmss>`). The final
 * sequential number is assigned when the draft is posted.
 *
 * @param {string} invoiceDate ISO date (YYYY-MM-DD).
 * @param {Date} [now] Injected clock (testability).
 * @return {string} A unique provisional invoice number.
 */
export function provisionalInvoiceNumber(invoiceDate, now = new Date()) {
	const datePart =
		String(invoiceDate || '')
			.slice(0, 10)
			.replace(/-/g, '') || 'DRAFT'
	const hh = String(now.getHours()).padStart(2, '0')
	const mm = String(now.getMinutes()).padStart(2, '0')
	const ss = String(now.getSeconds()).padStart(2, '0')
	return `DRAFT-${datePart}-${hh}${mm}${ss}`
}

/**
 * Build the ARInvoice payload posted to the OpenRegister object API.
 * The invoice is always created in lifecycleState `draft` (REQ-IQD-003).
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @param {object} input The collected form values.
 * @param {string} input.customerId Selected customer id.
 * @param {string} input.invoiceDate Invoice date.
 * @param {string} input.dueDate Due date.
 * @param {string} input.reference Optional reference / PO number.
 * @param {string} input.glAccount Default GL account for the lines.
 * @param {string} input.administrationId Owning administration id (required by schema).
 * @param {string} [input.invoiceNumber] Explicit invoice number; a provisional one is generated when absent.
 * @param {string} [input.periodId] Explicit fiscal period; derived from invoiceDate when absent.
 * @param {Array<object>} input.lines Draft lines.
 * @return {object} The ARInvoice payload.
 */
export function buildInvoicePayload(input) {
	const totals = computeTotals(input.lines)
	const lines = (input.lines || [])
		.filter(
			(l) =>
				(l.description || '').trim().length > 0 || Number(l.unitPrice) > 0,
		)
		.map((l, idx) => ({
			lineNumber: idx + 1,
			description: (l.description || '').trim(),
			quantity: Number(l.quantity) || 0,
			unitPrice: Number(l.unitPrice) || 0,
			vatRate: Number(l.vatRate) || 0,
			glAccount: input.glAccount || '',
		}))
	return {
		invoiceNumber:
			input.invoiceNumber || provisionalInvoiceNumber(input.invoiceDate),
		administrationId: String(input.administrationId || ''),
		periodId: input.periodId || periodIdFromDate(input.invoiceDate),
		customerId: String(input.customerId),
		invoiceDate: input.invoiceDate,
		dueDate: input.dueDate,
		currency: 'EUR',
		netAmount: totals.net,
		vatAmount: totals.vat,
		grossAmount: totals.gross,
		lifecycleState: 'draft',
		customerReference: input.reference || '',
		lines,
	}
}

/**
 * localStorage key for a given customer's quick-draft preferences.
 *
 * @param {string} customerId Customer id.
 * @return {string} The namespaced key.
 */
function prefsKey(customerId) {
	return `${PREFS_KEY_PREFIX}${customerId}`
}

/**
 * Load the last-used quick-draft preferences for a customer. Returns
 * null when absent, expired (>90 days) or unreadable.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @param {string} customerId Customer id.
 * @return {object|null} The stored prefs, or null.
 */
export function loadQuickDraftPrefs(customerId) {
	if (!customerId) return null
	try {
		const raw = globalThis.localStorage?.getItem(prefsKey(customerId))
		if (!raw) return null
		const parsed = JSON.parse(raw)
		if (!parsed || typeof parsed !== 'object') return null
		if (parsed.savedAt && Date.now() - parsed.savedAt > PREFS_TTL_MS) {
			globalThis.localStorage?.removeItem(prefsKey(customerId))
			return null
		}
		return parsed
	} catch (e) {
		return null
	}
}

/**
 * Persist the last-used quick-draft preferences for a customer.
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
 * @param {string} customerId Customer id.
 * @param {object} prefs The preferences (glAccount, vatCode, description, unitPrice).
 * @return {void}
 */
export function saveQuickDraftPrefs(customerId, prefs) {
	if (!customerId) return
	try {
		globalThis.localStorage?.setItem(
			prefsKey(customerId),
			JSON.stringify({ ...prefs, savedAt: Date.now() }),
		)
	} catch (e) {
		// Non-fatal: preferences are a convenience, not a requirement.
	}
}
