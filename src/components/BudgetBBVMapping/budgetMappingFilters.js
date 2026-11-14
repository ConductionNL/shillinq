/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Which budget-to-BBV mappings the index shows: the search term on account or
 * programme, the fiscal year the effective window overlaps, the allocation
 * bucket and the effective-from window. Each check passes when its filter is
 * not set.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

/**
 * The applied filters of the index, read from the component.
 *
 * @param {object} vm The BudgetBBVMappingIndex instance.
 * @return {{term: string, after: Date|null, before: Date|null, allocation: object|null, yearFilter: number|null}}
 */
export function mappingCriteria(vm) {
	return {
		term: (vm.searchTermApplied || '').trim().toLowerCase(),
		after: vm.effectiveFromAfter ? new Date(vm.effectiveFromAfter) : null,
		before: vm.effectiveFromBefore ? new Date(vm.effectiveFromBefore) : null,
		allocation: vm.parseAllocationBucket(vm.allocationBucket),
		yearFilter: vm.fiscalYearFilter ? Number(vm.fiscalYearFilter) : null,
	}
}

/**
 * Whether a mapping passes every filter, checked in index order.
 *
 * @param {object} row The mapping.
 * @param {object} criteria The applied filters (see mappingCriteria).
 * @return {boolean}
 */
export function mappingMatches(row, criteria) {
	return matchesSearchTerm(row, criteria.term)
		&& overlapsFiscalYear(row, criteria.yearFilter)
		&& withinAllocation(row, criteria.allocation)
		&& withinEffectiveWindow(row, criteria)
}

/**
 * The account number or programme code contains the (lower-cased) term.
 *
 * @param {object} row The mapping.
 * @param {string} term The search term, '' for none.
 * @return {boolean}
 */
function matchesSearchTerm(row, term) {
	if (!term) {
		return true
	}
	const account = String(row.glAccountNumber || '').toLowerCase()
	const programme = String(row.programmeCode || '').toLowerCase()
	return account.includes(term) || programme.includes(term)
}

/**
 * The mapping's effective window overlaps Jan 1 → Dec 31 of the year.
 *
 * @param {object} row The mapping.
 * @param {number|null} yearFilter The fiscal year, null for none.
 * @return {boolean}
 */
function overlapsFiscalYear(row, yearFilter) {
	if (yearFilter === null) {
		return true
	}
	const startYear = yearOf(row.effectiveFrom)
	const endYear = yearOf(row.effectiveTo)
	if (startYear !== null && startYear > yearFilter) {
		return false
	}
	return !(endYear !== null && endYear < yearFilter)
}

/**
 * The full year of a date value, null when there is none.
 *
 * @param {string|null} value The date.
 * @return {number|null}
 */
function yearOf(value) {
	return value ? new Date(value).getFullYear() : null
}

/**
 * The allocation percentage is a number within the bucket.
 *
 * @param {object} row The mapping.
 * @param {{min: number, max: number}|null} allocation The bucket, null for none.
 * @return {boolean}
 */
function withinAllocation(row, allocation) {
	if (!allocation) {
		return true
	}
	const value = Number(row.allocationPercentage)
	if (Number.isNaN(value)) {
		return false
	}
	return !(value < allocation.min || value > allocation.max)
}

/**
 * The effective-from date lies inside the after / before window.
 *
 * @param {object} row The mapping.
 * @param {{after: Date|null, before: Date|null}} window The window bounds.
 * @return {boolean}
 */
function withinEffectiveWindow(row, { after, before }) {
	if (!(after || before)) {
		return true
	}
	const eff = dateOf(row.effectiveFrom)
	if (!eff) {
		return false
	}
	return !startsBefore(eff, after) && !startsAfter(eff, before)
}

/**
 * A date value as a Date, null when there is none.
 *
 * @param {string|null} value The date.
 * @return {Date|null}
 */
function dateOf(value) {
	return value ? new Date(value) : null
}

/**
 * The effective-from date lies before the lower bound.
 *
 * @param {Date} eff The effective-from date.
 * @param {Date|null} after The lower bound, null for none.
 * @return {boolean}
 */
function startsBefore(eff, after) {
	return Boolean(after && eff < after)
}

/**
 * The effective-from date lies after the upper bound.
 *
 * @param {Date} eff The effective-from date.
 * @param {Date|null} before The upper bound, null for none.
 * @return {boolean}
 */
function startsAfter(eff, before) {
	return Boolean(before && eff > before)
}
