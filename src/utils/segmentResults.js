/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Segment results: turns OpenRegister's grouped aggregation envelope into
 * the rows of the segment P&L dashboard (reporting-segment-results
 * REQ-RSR-003). The declared aggregations answer `{ groups: [{ key, values:
 * { revenue, costs, result } }] }`; amounts are euros.
 *
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */

/**
 * The segment types the dashboard offers and the GLLine aggregation behind each.
 *
 * @type {Record<string, string>}
 */
export const SEGMENT_AGGREGATION = {
	costCenter: 'byCostCenter',
	costCenterHierarchy: 'byCostCenterHierarchy',
	costObject: 'byCostObject',
	project: 'byProject',
	analyticalDimension: 'byAnalyticalDimension',
}

/**
 * A figure as a number, 0 when absent.
 *
 * @param {*} value The raw value.
 * @return {number} The number.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
function figure(value) {
	const number = Number(value)
	return Number.isFinite(number) ? number : 0
}

/**
 * The first non-empty value among a group's candidate keys.
 *
 * @param {object} group The group.
 * @param {Array<string>} keys The candidate keys, in order.
 * @return {string} The value, or ''.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
function firstOf(group, keys) {
	for (const key of keys) {
		const value = group?.[key] ?? group?.joined?.[key]
		if (value !== undefined && value !== null && value !== '') {
			return String(value)
		}
	}
	return ''
}

/**
 * Normalise the aggregation envelope into dashboard rows.
 *
 * @param {object|Array} payload The aggregation response.
 * @return {Array<{key: string, name: string, parent: string, revenue: number, costs: number, result: number, depth: number}>} The rows.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
export function normaliseSegmentRows(payload) {
	let groups = []
	if (Array.isArray(payload?.groups)) {
		groups = payload.groups
	} else if (Array.isArray(payload)) {
		groups = payload
	}

	return groups
		.map((group) => {
			const values = group?.values ?? {}
			const key = Array.isArray(group?.keys) ? group.keys[0] : group?.key
			return {
				key: key === null || key === undefined ? '' : String(key),
				name: firstOf(group, ['name', 'AnalyticalDimension.name', 'Project.name']),
				parent: firstOf(group, ['parent', 'AnalyticalDimension.parentCode', 'Project.parentCode']),
				revenue: figure(values.revenue),
				costs: figure(values.costs),
				result: figure(values.result),
				depth: 0,
			}
		})
		.filter((row) => row.key !== '')
}

/**
 * The totals of a set of rows.
 *
 * @param {Array<{revenue: number, costs: number, result: number}>} rows The rows.
 * @return {{revenue: number, costs: number, result: number}} The totals.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
export function segmentTotals(rows) {
	return rows.reduce(
		(totals, row) => ({
			revenue: totals.revenue + row.revenue,
			costs: totals.costs + row.costs,
			result: totals.result + row.result,
		}),
		{ revenue: 0, costs: 0, result: 0 },
	)
}

/**
 * The query parameters of one aggregation call: the administration always, the period when chosen.
 *
 * @param {string} administrationId The active administration.
 * @param {string} periodId The chosen FiscalPeriod periodId (2026-M09, 2026-Q3), or ''.
 * @return {Record<string, string>} The parameters.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
export function segmentQuery(administrationId, periodId) {
	const params = { 'filter[administrationId]': administrationId }
	if (periodId) {
		params['filter[periodId]'] = periodId
	}
	return params
}

/**
 * The fiscal periods of an administration as choices for the period filter.
 *
 * GLLine.periodId holds the FiscalPeriod's own periodId, whose shape the
 * administration chooses (2026-M09, 2026-Q3), so the choices come from the
 * periods themselves, oldest first.
 *
 * @param {object|Array<object>|null} payload The FiscalPeriod list response.
 * @return {Array<{id: string, label: string}>} The choices.
 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
 */
export function periodOptions(payload) {
	let items = []
	if (Array.isArray(payload?.results)) {
		items = payload.results
	} else if (Array.isArray(payload)) {
		items = payload
	}

	return items
		.filter((item) => typeof item?.periodId === 'string' && item.periodId !== '')
		.sort((a, b) => String(a.startDate ?? a.periodId).localeCompare(String(b.startDate ?? b.periodId)))
		.map((item) => ({ id: item.periodId, label: item.name || item.periodId }))
}
