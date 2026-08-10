// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for BudgetLineCommitments.vue (REQ-VPL-011).
 *
 * Normalises the `committedVsRealisedPerBudgetLine` aggregation response
 * (CommitmentLine buckets grouped by programme/costCentre/fiscalYear/
 * glAccount, joined to Budget.authorisedAmount / Budget.realisedAmount) into
 * display rows with the four BBV columns (authorised / committed / realised /
 * free). `free` is computed client-side from the three declared figures —
 * display arithmetic, not a parallel PHP reporting service
 * (ADR-031 / REQ-VPL-011).
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

/**
 * Normalise the raw aggregation payload into budget-line rows.
 *
 * @param {object} payload Raw response from the aggregation endpoint.
 * @return {Array<object>} Rows with programme/costCentre/fiscalYear/glAccount + the four amount columns (minor units).
 */
export function normaliseBudgetLineRows(payload) {
	const buckets = Array.isArray(payload?.buckets)
		? payload.buckets
		: (Array.isArray(payload) ? payload : [])

	return buckets.map((bucket) => {
		const authorised = Number(bucket?.['Budget.authorisedAmount'] ?? bucket?.authorised ?? 0)
		const committed = Number(bucket?.remainingCommitted ?? bucket?.committed ?? 0)
		const realised = Number(bucket?.invoicedAmount ?? bucket?.realised ?? 0)
		const free = authorised - committed - realised

		return {
			key: [bucket?.programme, bucket?.costCentre, bucket?.fiscalYear, bucket?.glAccount].join('|'),
			programme: String(bucket?.programme ?? ''),
			costCentre: String(bucket?.costCentre ?? ''),
			fiscalYear: bucket?.fiscalYear ?? null,
			glAccount: String(bucket?.glAccount ?? ''),
			authorised,
			committed,
			realised,
			free,
		}
	})
}

/**
 * Format a minor-units (cents) amount as an EUR currency string.
 *
 * @param {number} cents Amount in minor units.
 * @return {string} Formatted currency string.
 */
export function formatAmount(cents) {
	const value = (Number(cents) || 0) / 100
	try {
		return new Intl.NumberFormat(undefined, {
			style: 'currency',
			currency: 'EUR',
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(value)
	} catch (e) {
		return value.toFixed(2)
	}
}

/**
 * Build the exact-match filter set to drill down from a budget-line row to
 * its underlying CommitmentLine records.
 *
 * @param {object} row Normalised budget-line row.
 * @return {object} Filters keyed by CommitmentLine field name.
 */
export function drilldownFilters(row) {
	const filters = {}
	if (row.programme) {
		filters.programme = row.programme
	}
	if (row.costCentre) {
		filters.costCentre = row.costCentre
	}
	if (row.fiscalYear !== null && row.fiscalYear !== undefined) {
		filters.fiscalYear = row.fiscalYear
	}
	if (row.glAccount) {
		filters.glAccount = row.glAccount
	}
	return filters
}
