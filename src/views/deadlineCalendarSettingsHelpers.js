/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pure helpers for the DeadlineCalendarSettings view
 * (compliance-deadline-calendar REQ-CDC-006).
 *
 * Kept free of Vue/axios imports so vitest can pin the normalisation and
 * payload logic offline (same pattern as budgetLineCommitmentsHelpers.js).
 */

/**
 * The deadline categories in display order. Labels are i18n KEYS
 * (English, ADR-005) — the view passes them through t('shillinq', …).
 * Defaults mirror the backend: filing / payment-run / contract ON,
 * AR due dates OFF (REQ-CDC-004), lead 10 days filing / 7 others.
 */
export const CATEGORY_META = [
	{
		id: 'filing',
		label: 'Filing deadlines (BTW / ICP / VPB)',
		description:
			'Publish BTW, ICP and VPB filing deadlines on your deadline calendar.',
		defaultEnabled: true,
		defaultLeadDays: 10,
	},
	{
		id: 'payment-run',
		label: 'Payment runs',
		description: 'Publish scheduled payment-run execution dates.',
		defaultEnabled: true,
		defaultLeadDays: 7,
	},
	{
		id: 'ar-due',
		label: 'Invoice due dates',
		description:
			'Publish open AR invoice due dates (off by default — these can be high-volume).',
		defaultEnabled: false,
		defaultLeadDays: 7,
	},
	{
		id: 'contract',
		label: 'Contract deadlines',
		description:
			'Publish contract renewal and notice-period (opzegtermijn) deadlines.',
		defaultEnabled: true,
		defaultLeadDays: 7,
	},
]

/**
 * Normalise the GET /api/deadline-calendar/settings response into the
 * per-category form model, falling back to the documented defaults for
 * anything absent or malformed.
 *
 * @param {object|null|undefined} data Raw response body ({categories: {…}}).
 * @return {Array<{id: string, label: string, description: string, enabled: boolean, leadDays: number}>}
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
export function normaliseSettings(data) {
	const categories = objectOrEmpty(objectOrEmpty(data).categories)
	return CATEGORY_META.map((meta) => {
		const raw = objectOrEmpty(categories[meta.id])
		return {
			id: meta.id,
			label: meta.label,
			description: meta.description,
			enabled:
				typeof raw.enabled === 'boolean' ? raw.enabled : meta.defaultEnabled,
			leadDays: leadDaysOr(raw.leadDays, meta.defaultLeadDays),
		}
	})
}

/**
 * The value when it is an object, else an empty object.
 *
 * @param {unknown} value The value.
 * @return {object}
 */
function objectOrEmpty(value) {
	return value && typeof value === 'object' ? value : {}
}

/**
 * A lead time as a non-negative integer, or the fallback.
 *
 * @param {unknown} value The raw lead days.
 * @param {number} fallback The days when the value is not usable.
 * @return {number}
 */
function leadDaysOr(value, fallback) {
	const leadDays = Number.parseInt(value, 10)
	return Number.isFinite(leadDays) && leadDays >= 0 ? leadDays : fallback
}

/**
 * Build the POST payload from the form model — only known categories,
 * enabled coerced to boolean, leadDays clamped to a non-negative integer.
 *
 * @param {Array<{id: string, enabled: boolean, leadDays: number}>} rows The form model.
 * @return {{categories: {[key: string]: {enabled: boolean, leadDays: number}}}}
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
export function buildSavePayload(rows) {
	const known = new Set(CATEGORY_META.map((meta) => meta.id))
	const categories = {}
	for (const row of Array.isArray(rows) ? rows : []) {
		if (row && known.has(row.id)) {
			categories[row.id] = {
				enabled: row.enabled === true,
				leadDays: leadDaysOr(row.leadDays, 0),
			}
		}
	}
	return { categories }
}
