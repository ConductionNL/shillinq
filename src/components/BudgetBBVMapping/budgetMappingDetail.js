/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What the budget-mapping detail page decides without a component: which
 * envelope of a response holds the record, whether the form may be saved, and
 * how much one other mapping's allocation counts towards the GL total.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

/**
 * The first of the values that is neither null nor undefined.
 *
 * @param {Array<unknown>} values The candidates, in order.
 * @return {unknown} The first present value, undefined when none is.
 */
export function firstPresent(values) {
	return values.find((value) => value !== null && value !== undefined)
}

/**
 * The mapping record a detail response carries (object, result, or the bare
 * body).
 *
 * @param {object} response The axios response.
 * @return {object} The record.
 * @throws {Error} When the response carries no record object.
 */
export function mappingRecordBody(response) {
	const data = response.data
	const body = firstPresent([data?.object, data?.result, data]) ?? null
	if (!body || typeof body !== 'object') {
		throw new Error('Empty response')
	}
	return body
}

/**
 * Whether the detail form may be saved: not saving, GL account and programme
 * chosen, allocation within 0..100, a valid effective window and no error
 * feedback.
 *
 * @param {object} vm The BudgetBBVMappingDetail instance.
 * @return {boolean}
 */
export function isSavableMapping(vm) {
	if (vm.saving || !hasMappingLinks(vm.form)) {
		return false
	}
	if (!allocationWithinBounds(vm.form.allocationPercentage)) {
		return false
	}
	if (!hasValidWindow(vm.form)) {
		return false
	}
	return vm.allocationFeedback.severity !== 'error'
}

/**
 * Both the GL account and the programme are chosen.
 *
 * @param {object} form The detail form.
 * @return {boolean}
 */
function hasMappingLinks(form) {
	return Boolean(form.glAccountNumber && form.programmeCode)
}

/**
 * The allocation is filled in and not below 0 or above 100.
 *
 * @param {unknown} value The allocation field.
 * @return {boolean}
 */
function allocationWithinBounds(value) {
	return !(value === null || value === undefined || value === '' || Number(value) < 0 || Number(value) > 100)
}

/**
 * The window has a start and does not end before it.
 *
 * @param {object} form The detail form.
 * @return {boolean}
 */
function hasValidWindow(form) {
	return Boolean(form.effectiveFrom) && !(form.effectiveTo && form.effectiveTo < form.effectiveFrom)
}

/**
 * A mapping's allocation percentage as a number, 0 when absent or not finite.
 *
 * @param {object} row The mapping.
 * @return {number}
 */
export function allocationValue(row) {
	const value = Number(row.allocationPercentage ?? 0)
	return Number.isFinite(value) ? value : 0
}
