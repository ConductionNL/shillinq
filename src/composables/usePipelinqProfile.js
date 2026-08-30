/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * usePipelinqProfile — pure-JS helpers for the slice-06 profile card +
 * klantbeeld timeline render path.
 *
 * The profile card consumes the controller response shape that slice 05
 * (BookingDetailController#show) produces:
 *
 *   {
 *     booking: { ..., pipelinqContactId },
 *     contact: { externalId, legalName, email, phone, address, kvkNumber, found } | null,
 *     klantbeeld: { transactions, limit, offset, unavailable, empty } | null,
 *     contactError: string | null,
 *     notLinkedToPipelinq: boolean
 *   }
 *
 * These helpers stay framework-free so they're testable with `node --test`
 * (matching the inventory-mobile-scanner-helpers.test.mjs pattern). The Vue
 * components import them and stay declarative.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

/**
 * Classify the contact as either an organization (has KvK) or an
 * individual. Returns `null` when the contact is absent or untrusted (not
 * found). The legal-name heuristic mirrors the slice-03 DTO contract:
 * organizations populate `kvkNumber`, individuals do not.
 *
 * @param {object|null} contact Slice-05 contact payload (or null).
 * @return {'organization'|'individual'|null}
 */
export function classifyContact(contact) {
	if (!contact || contact.found === false) {
		return null
	}
	const kvk = String(contact.kvkNumber || '').trim()
	if (kvk.length > 0) {
		return 'organization'
	}
	return 'individual'
}

/**
 * Compute the read-only profile fields, omitting any whose value is empty
 * after trimming. The output preserves declared order so the UI renders
 * deterministically. `mailto`/`tel` href values are prefixed here so the
 * template can stay logic-free.
 *
 * @param {object|null} contact Slice-05 contact payload.
 * @return {Array<{ key: string, label: string, value: string, href?: string }>}
 */
export function buildProfileFields(contact) {
	if (!contact || contact.found === false) {
		return []
	}
	const fields = []
	const legalName = trimmed(contact.legalName)
	if (legalName) {
		fields.push({
			key: 'legalName',
			label: 'Name',
			value: legalName,
			emphasis: true,
		})
	}
	const kvk = trimmed(contact.kvkNumber)
	if (kvk) {
		fields.push({ key: 'kvkNumber', label: 'KvK', value: kvk })
	}
	const email = trimmed(contact.email)
	if (email) {
		fields.push({
			key: 'email',
			label: 'Email',
			value: email,
			href: `mailto:${email}`,
		})
	}
	const phone = trimmed(contact.phone)
	if (phone) {
		fields.push({
			key: 'phone',
			label: 'Phone',
			value: phone,
			href: `tel:${phone.replace(/\s+/g, '')}`,
		})
	}
	const address = trimmed(contact.address)
	if (address) {
		fields.push({ key: 'address', label: 'Address', value: address })
	}
	return fields
}

/**
 * Pick which view state the profile card should render. The controller
 * encodes four mutually-exclusive states:
 *
 *  - `notLinkedToPipelinq` → 'unlinked'
 *  - `contactError`        → 'error'
 *  - `contact.found===false` → 'notfound'
 *  - otherwise              → 'ok'
 *
 * @param {object} payload Slice-05 response.
 * @return {'unlinked'|'error'|'notfound'|'ok'}
 */
export function selectProfileState(payload) {
	if (!payload) {
		return 'error'
	}
	if (payload.notLinkedToPipelinq === true) {
		return 'unlinked'
	}
	if (
		typeof payload.contactError === 'string'
		&& payload.contactError.length > 0
	) {
		return 'error'
	}
	if (!payload.contact || payload.contact.found === false) {
		return 'notfound'
	}
	return 'ok'
}

/**
 * Pick which view state the klantbeeld timeline should render.
 *
 * The history is hidden / replaced when:
 *  - the profile state is anything other than 'ok'           → 'hidden'
 *  - the klantbeeld envelope is missing                      → 'hidden'
 *  - the envelope marks itself unavailable                   → 'unavailable'
 *  - the envelope is available but empty                     → 'empty'
 *  - otherwise                                               → 'ok'
 *
 * @param {object} payload Slice-05 response.
 * @return {'hidden'|'unavailable'|'empty'|'ok'}
 */
export function selectHistoryState(payload) {
	const profileState = selectProfileState(payload)
	if (profileState !== 'ok') {
		return 'hidden'
	}
	const k = payload?.klantbeeld
	if (!k) {
		return 'hidden'
	}
	if (k.unavailable === true) {
		return 'unavailable'
	}
	const empty =
		k.empty === true
		|| (Array.isArray(k.transactions) && k.transactions.length === 0)
	if (empty) {
		return 'empty'
	}
	return 'ok'
}

/**
 * Format a klantbeeld transaction amount for display. Uses the row's
 * currency when present, falls back to EUR, and renders with two
 * decimals so a partial row never crashes the timeline.
 *
 * @param {object} row Slice-04 transaction row.
 * @return {string}
 */
export function formatTransactionAmount(row) {
	const currency = String(row?.currency || 'EUR').trim() || 'EUR'
	const amount = Number(row?.amount ?? 0)
	const safe = Number.isFinite(amount) ? amount : 0
	return `${currency} ${safe.toFixed(2)}`
}

/**
 * Format an ISO-ish date string for the timeline. Returns the original
 * string when parsing fails so a malformed row still renders something.
 *
 * @param {string} iso Date string.
 * @return {string}
 */
export function formatTransactionDate(iso) {
	const raw = String(iso || '').trim()
	if (!raw) {
		return ''
	}
	const parsed = new Date(raw)
	if (Number.isNaN(parsed.getTime())) {
		return raw
	}
	// YYYY-MM-DD is enough for the card surface; locale handles the rest.
	return parsed.toISOString().slice(0, 10)
}

/**
 * Build the next-page query parameters for the "Load more" control.
 *
 * @param {object} klantbeeld Envelope payload.
 * @param {number} defaultLimit Limit to fall back to when the envelope is
 *   missing one.
 * @return {{ limit: number, offset: number }}
 */
export function nextPageParams(klantbeeld, defaultLimit = 5) {
	const limit = Number.isFinite(Number(klantbeeld?.limit))
		? Number(klantbeeld.limit)
		: defaultLimit
	const offset = Number.isFinite(Number(klantbeeld?.offset))
		? Number(klantbeeld.offset)
		: 0
	return {
		limit: Math.max(1, limit),
		offset: Math.max(0, offset) + Math.max(1, limit),
	}
}

/**
 * Build a URL to the pipelinq Contact detail page in the external app.
 * Returns null when no base url is configured so the template can hide
 * the link entirely.
 *
 * @param {string} pipelinqBaseUrl Configured pipelinq base URL.
 * @param {string} externalId Contact external id.
 * @return {string|null}
 */
export function buildPipelinqLink(pipelinqBaseUrl, externalId) {
	const base = String(pipelinqBaseUrl || '').trim()
	const id = String(externalId || '').trim()
	if (!base || !id) {
		return null
	}
	const trimmedBase = base.replace(/\/+$/, '')
	return `${trimmedBase}/contacts/${encodeURIComponent(id)}`
}

/**
 * Trim a value, returning empty string when nullish or not a string.
 *
 * @param {unknown} value Source value.
 * @return {string}
 */
function trimmed(value) {
	if (typeof value !== 'string') {
		return ''
	}
	return value.trim()
}
