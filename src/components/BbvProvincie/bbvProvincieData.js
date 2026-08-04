// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Shared OpenRegister access + formatting helpers for the BBV provincie
 * pages (BBV Compliance Dashboard + Budget-to-Programme Linker).
 *
 * Every read and write here goes through OpenRegister's own object /
 * aggregation API (`/apps/openregister/api/objects/...`) — the same base
 * URL `@conduction/nextcloud-vue`'s object store uses. Shillinq owns no
 * parallel PHP reporting service for these pages (ADR-022).
 *
 * Roll-ups (spend / commitment per programme, per period) are requested
 * from OpenRegister's `grouped` aggregation endpoint so the numbers are
 * server-authoritative; this module never re-derives a total from raw GL
 * lines (ADR-031). What it does do is *project* the server-returned group
 * values (pick the programmes the user filtered to, sum the already-grouped
 * values into a headline KPI) — that is presentation, not accounting.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** OpenRegister object API root. */
const OR_OBJECTS = '/apps/openregister/api/objects'

/** OpenRegister aggregation API root. */
const OR_AGGREGATIONS = '/apps/openregister/api/objects/aggregations'

/**
 * Build the OpenRegister object-collection (or single-object) URL for a
 * register + schema pair.
 *
 * @param {string} register The register slug (manifest `config.register`).
 * @param {string} schema The schema slug (manifest `config.schema`).
 * @param {?string} [id] Optional object id / uuid for the single-object URL.
 * @return {string} The generated Nextcloud URL.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function objectsUrl(register, schema, id = null) {
	const base = `${OR_OBJECTS}/${register}/${schema}`
	return generateUrl(id ? `${base}/${id}` : base)
}

/**
 * Fetch a page of objects for a register + schema pair.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {object} [params] Query parameters (`_limit`, `_page`, `filters[...]`).
 * @return {Promise<Array<object>>} The returned rows (empty on a non-2xx).
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export async function fetchObjects(register, schema, params = {}) {
	const { data } = await axios.get(objectsUrl(register, schema), { params })
	if (Array.isArray(data)) {
		return data
	}
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * Fetch a single object by id.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {string} id The object id or uuid.
 * @return {Promise<object>} The object body.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export async function fetchObject(register, schema, id) {
	const { data } = await axios.get(objectsUrl(register, schema, id))
	return data ?? {}
}

/**
 * Run OpenRegister's ad-hoc `grouped` aggregation and return the group
 * rows as a `{ groupKey: number }` map.
 *
 * The endpoint answers `{ groups: [{ key, value }, ...] }` for a
 * single-field `groupBy`; a group whose value is not numeric is skipped
 * rather than coerced to zero, so "no data" never masquerades as "€0".
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug the aggregation runs over.
 * @param {object} options The aggregation request.
 * @param {string} options.groupBy Field to group by.
 * @param {string} [options.metric] Aggregation metric (default `sum`).
 * @param {string} [options.field] Numeric field the metric reduces.
 * @param {object} [options.filter] Equality filters, sent as `filter[key]=value`.
 * @return {Promise<object>} Map of group key → numeric value.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export async function fetchGroupedTotals(register, schema, options) {
	const params = {
		groupBy: options.groupBy,
		metric: options.metric || 'sum',
	}
	if (options.field) {
		params.field = options.field
	}
	Object.entries(options.filter || {}).forEach(([key, value]) => {
		if (value !== null && value !== undefined && value !== '') {
			params[`filter[${key}]`] = value
		}
	})

	const url = generateUrl(`${OR_AGGREGATIONS}/${register}/${schema}/grouped`)
	const { data } = await axios.get(url, { params })
	const groups = Array.isArray(data?.groups) ? data.groups : []

	const totals = {}
	groups.forEach((group) => {
		const key = group?.key
		const value = Number(group?.value)
		if (key === null || key === undefined || key === '' || Number.isNaN(value)) {
			return
		}
		totals[String(key)] = value
	})
	return totals
}

/**
 * Strip the keys OpenRegister rejects on a write.
 *
 * `@self` is server-owned metadata; writing it back trips OR's `$ref must
 * be a non-empty string` validation. `null` / `undefined` / `{}` values on
 * nested object properties are rejected outright, so the key is omitted
 * instead of sent empty.
 *
 * @param {object} object The object as read from OpenRegister.
 * @return {object} A write-safe shallow copy.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function sanitiseForWrite(object) {
	const out = {}
	Object.entries(object || {}).forEach(([key, value]) => {
		if (key === '@self' || key === 'id') {
			return
		}
		if (value === null || value === undefined) {
			return
		}
		if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0) {
			return
		}
		out[key] = value
	})
	return out
}

/**
 * Write a programme assignment onto a GL line.
 *
 * OpenRegister's save is PUT-semantic — an omitted property is cleared —
 * so the whole (sanitised) row is carried forward and only the assignment
 * fields are overwritten.
 *
 * @param {string} register The register slug.
 * @param {string} schema The schema slug.
 * @param {object} row The GL line as previously read.
 * @param {object} patch The fields to overwrite.
 * @return {Promise<object>} The saved object.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export async function saveAssignment(register, schema, row, patch) {
	const id = row?.id ?? row?.['@self']?.id ?? row?.['@self']?.uuid
	if (!id) {
		throw new Error('GL line has no id')
	}
	const body = { ...sanitiseForWrite(row), ...patch }
	const { data } = await axios.put(objectsUrl(register, schema, String(id)), body)
	return data ?? {}
}

/**
 * Coerce a value to a finite number, or `null` when it is not numeric.
 *
 * Distinguishing "not a number" from `0` matters for the KPI cards: an
 * absent roll-up must not render as a confident "€0".
 *
 * @param {string|number|null|undefined} value The raw value.
 * @return {?number} The number, or null.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function toNumber(value) {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const num = Number(value)
	return Number.isFinite(num) ? num : null
}

/**
 * Format a KPI / cell value for display according to the manifest's
 * `format` descriptor.
 *
 * @param {?number} value The numeric value (null renders as an en-dash).
 * @param {string} [format] `currency`, `percent`, or plain when omitted.
 * @param {string} [currency] ISO 4217 code for `currency` format.
 * @return {string} The formatted value.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function formatMetric(value, format = '', currency = 'EUR') {
	if (value === null || value === undefined || Number.isNaN(value)) {
		return '—'
	}
	if (format === 'currency') {
		try {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: currency || 'EUR',
				maximumFractionDigits: 0,
			}).format(value)
		} catch (e) {
			return String(value)
		}
	}
	if (format === 'percent') {
		return `${Math.round(value * 100)} %`
	}
	return new Intl.NumberFormat().format(value)
}

/**
 * Resolve the traffic-light band for a ratio against the manifest's
 * `trafficLight` thresholds (REQ-BBC-001: green ≥ 15 % remaining, yellow
 * 0–15 %, red below zero).
 *
 * @param {?number} ratio Remaining ÷ total budget.
 * @param {object} [thresholds] The manifest `trafficLight` block.
 * @return {string} `green`, `yellow`, `red`, or `unknown`.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function trafficLightBand(ratio, thresholds) {
	if (ratio === null || ratio === undefined || Number.isNaN(ratio) || !thresholds) {
		return 'unknown'
	}
	const matches = (band) => {
		if (!band) {
			return false
		}
		if (band.min !== undefined && ratio < band.min) {
			return false
		}
		if (band.max !== undefined && ratio >= band.max) {
			return false
		}
		return true
	}
	if (matches(thresholds.red)) {
		return 'red'
	}
	if (matches(thresholds.yellow)) {
		return 'yellow'
	}
	if (matches(thresholds.green)) {
		return 'green'
	}
	return 'unknown'
}

/**
 * Today as an ISO `YYYY-MM-DD` string, used for the `@today` sentinel the
 * manifest declares on the linker's Effective Date field.
 *
 * @return {string} The ISO date.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function todayIso() {
	const now = new Date()
	const month = String(now.getMonth() + 1).padStart(2, '0')
	const day = String(now.getDate()).padStart(2, '0')
	return `${now.getFullYear()}-${month}-${day}`
}

/**
 * Resolve a manifest field default, expanding the `@today` sentinel.
 *
 * @param {string|number|boolean|null|undefined} value The declared default.
 * @return {string|number|boolean|null|undefined} The resolved default.
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
export function resolveDefault(value) {
	return value === '@today' ? todayIso() : value
}
