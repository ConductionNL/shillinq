// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for BudgetLineCommitments.vue (REQ-VPL-011).
 *
 * Normalises the `committedVsRealisedPerBudgetLine` aggregation response
 * (CommitmentLine buckets grouped by programme/cost_centre/financial_year/
 * general_ledger_account, joined to CommitmentBudget.authorised_amount /
 * CommitmentBudget.realised_amount — the aggregation response buckets its
 * joined fields as `<join.through>.<field>`, so the `CommitmentBudget` rename
 * of `join.through` changes this response key, not just the register JSON;
 * see `openspec/changes/budget-core-schema/design.md` §2a) into display rows
 * with the four BBV columns (geautoriseerd / mandatory / gerealiseerd / vrij).
 * `vrij` is computed client-side from the three declared figures — display
 * arithmetic, not a parallel PHP reporting service (ADR-031 / REQ-VPL-011).
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

/**
 * Normalise the raw aggregation payload into budget-line rows.
 *
 * @param {object} payload Raw response from the aggregation endpoint.
 * @return {Array<object>} Rows with programme/cost_centre/financial_year/general_ledger_account + the four amount columns (minor units).
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-002
 */
export function normaliseBudgetLineRows(payload) {
	const buckets = Array.isArray(payload?.buckets)
		? payload.buckets
		: Array.isArray(payload)
			? payload
			: []

	return buckets.map((bucket) => {
		const geautoriseerd = Number(
			bucket?.['CommitmentBudget.authorised_amount']
				?? bucket?.geautoriseerd
				?? 0,
		)
		const mandatory = Number(
			bucket?.remaining_committed ?? bucket?.verplicht ?? 0,
		)
		const gerealiseerd = Number(
			bucket?.invoiced_amount ?? bucket?.gerealiseerd ?? 0,
		)
		const vrij = geautoriseerd - mandatory - gerealiseerd

		return {
			key: [
				bucket?.programme,
				bucket?.cost_centre,
				bucket?.financial_year,
				bucket?.general_ledger_account,
			].join('|'),
			programme: String(bucket?.programme ?? ''),
			cost_centre: String(bucket?.cost_centre ?? ''),
			financial_year: bucket?.financial_year ?? null,
			general_ledger_account: String(bucket?.general_ledger_account ?? ''),
			geautoriseerd,
			mandatory,
			gerealiseerd,
			vrij,
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
	if (row.cost_centre) {
		filters.cost_centre = row.cost_centre
	}
	if (row.financial_year !== null && row.financial_year !== undefined) {
		filters.financial_year = row.financial_year
	}
	if (row.general_ledger_account) {
		filters.general_ledger_account = row.general_ledger_account
	}
	return filters
}
