// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure computation + persistence helpers for BankStatementWizard
// (shillinq-bank-statement-wizard). Everything here is side-effect free
// except the localStorage helpers, which guard against a missing or throwing
// localStorage so the wizard works in any environment. Kept out of the .vue
// file so it is independently unit-testable (mirrors invoiceQuickDraft.js).

const IBAN_MAP_KEY = 'shillinq:bank-iban-map'
const IBAN_MAP_TTL_MS = 365 * 24 * 60 * 60 * 1000 // 1 year (REQ-BSW-006)

/**
 * The three supported statement formats with their picker labels + accept
 * hints. The `value` is the parser format key the import endpoint expects.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @return {Array<object>} Format option descriptors.
 */
export function formatOptions() {
	return [
		{ value: 'camt053', accept: '.xml,text/xml,application/xml' },
		{ value: 'mt940', accept: '.940,.mt940,.sta,.txt,text/plain' },
		{ value: 'csv', accept: '.csv,text/csv' },
	]
}

/**
 * Normalise an IBAN for use as a memory key: strip spaces, upper-case.
 *
 * @param {string} iban Raw IBAN.
 * @return {string} The normalised IBAN ('' when falsy).
 */
export function normalizeIban(iban) {
	return String(iban || '')
		.replace(/\s+/g, '')
		.toUpperCase()
}

/**
 * Read the full IBAN → glAccountId map from localStorage, dropping the whole
 * map when it is older than the 1-year TTL. Returns {} on any failure.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @return {object} The { savedAt, map } payload's map, or {}.
 */
function readIbanStore() {
	try {
		const raw = globalThis.localStorage?.getItem(IBAN_MAP_KEY)
		if (!raw) return {}
		const parsed = JSON.parse(raw)
		if (!parsed || typeof parsed !== 'object') return {}
		if (parsed.savedAt && Date.now() - parsed.savedAt > IBAN_MAP_TTL_MS) {
			globalThis.localStorage?.removeItem(IBAN_MAP_KEY)
			return {}
		}
		return parsed.map && typeof parsed.map === 'object' ? parsed.map : {}
	} catch (e) {
		return {}
	}
}

/**
 * Look up the remembered GL account for an IBAN. Returns null when absent,
 * expired or unreadable (REQ-BSW-006).
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @param {string} iban The statement IBAN.
 * @return {string|null} The stored glAccountId, or null.
 */
export function loadIbanMapping(iban) {
	const key = normalizeIban(iban)
	if (!key) return null
	const map = readIbanStore()
	return key in map && map[key] ? String(map[key]) : null
}

/**
 * Persist the IBAN → glAccountId mapping, refreshing the 1-year timestamp.
 * Never throws when localStorage is unavailable.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @param {string} iban The statement IBAN.
 * @param {string} glAccountId The mapped GL account id.
 * @return {void}
 */
export function saveIbanMapping(iban, glAccountId) {
	const key = normalizeIban(iban)
	if (!key || !glAccountId) return
	try {
		const map = readIbanStore()
		map[key] = String(glAccountId)
		globalThis.localStorage?.setItem(
			IBAN_MAP_KEY,
			JSON.stringify({ savedAt: Date.now(), map }),
		)
	} catch (e) {
		// Non-fatal: IBAN memory is a convenience, not a requirement.
	}
}

/**
 * Build the JSON import payload POSTed to /api/v1/bank-statements/import.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @param {object} input The collected wizard values.
 * @param {string} input.format Parser format key (camt053/mt940/csv).
 * @param {string} input.contents Raw file contents.
 * @param {string} input.glAccountId Mapped GL account id.
 * @return {object} The request body.
 */
export function buildImportPayload(input) {
	return {
		format: String(input.format || ''),
		contents: String(input.contents || ''),
		glAccountId: String(input.glAccountId || ''),
		encoding: 'utf8',
	}
}

/** sessionStorage breadcrumb flag the reconciliation page reads (REQ-BSW-005). */
export const BREADCRUMB_FLAG = 'shillinq:bank-import-breadcrumb'

/**
 * Set the "came from the dashboard import wizard" breadcrumb so the
 * reconciliation page can offer a Back-to-overview link (REQ-BSW-005).
 * Never throws.
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 * @param {string} statementId The just-imported statement id.
 * @return {void}
 */
export function setReturnBreadcrumb(statementId) {
	try {
		globalThis.sessionStorage?.setItem(
			BREADCRUMB_FLAG,
			JSON.stringify({
				from: 'financial-overview',
				statementId: String(statementId || ''),
			}),
		)
	} catch (e) {
		// Non-fatal.
	}
}
