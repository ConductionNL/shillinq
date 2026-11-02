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
 * READS THE ENVELOPE OPENREGISTER ACTUALLY RETURNS. This previously looked for
 * `payload.buckets` holding flat rows (`bucket.programme`,
 * `bucket.remaining_committed`, ...). No such shape exists — the engine returns
 *
 *   { groups: [ { keys:   { programme, costCentre, financialYear,
 *                           generalLedgerAccount },
 *                 values: { sum_remaining_committed, sum_invoiced_amount },
 *                 joined: { 'CommitmentBudget.authorised_amount': n,
 *                           'CommitmentBudget.realised_amount':  n } } ] }
 *
 * so `buckets` was always undefined and this always returned []. Together with
 * the declaration being written in a grammar the engine did not read, that is
 * why the page rendered its empty state over live data (issue #1216): every
 * layer agreed on a shape none of them produced.
 *
 * The OUTPUT contract is unchanged — the template and drilldownFilters() read
 * snake_case row fields — so only the parsing moved.
 *
 * The legacy flat spelling is still accepted per field. An instance whose
 * OpenRegister predates the composite-groupBy work returns the single-key
 * shape, and falling back keeps this readable rather than blank there.
 *
 * @param {object} payload Raw response from the aggregation endpoint.
 * @return {Array<object>} Rows with programme/cost_centre/financial_year/general_ledger_account + the four amount columns (minor units).
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-002
 */
export function normaliseBudgetLineRows(payload) {
	return payloadGroups(payload).map((group) => budgetLineRow(group))
}

/**
 * The groups of an aggregation payload: the `groups` envelope, the legacy
 * `buckets` list, or a bare array.
 *
 * @param {object|Array} payload Raw response from the aggregation endpoint.
 * @return {Array<object>} The groups, or [] for a malformed payload.
 */
function payloadGroups(payload) {
	if (Array.isArray(payload?.groups)) {
		return payload.groups
	}
	if (Array.isArray(payload?.buckets)) {
		return payload.buckets
	}
	return Array.isArray(payload) ? payload : []
}

/**
 * One display row for an aggregation group, with `vrij` computed from the
 * three declared figures.
 *
 * @param {object} group One aggregation group (or legacy flat bucket).
 * @return {object} The budget-line row.
 */
function budgetLineRow(group) {
	const { programme, costCentre, financialYear, glAccount } =
		groupDimensions(group)
	const geautoriseerd = authorisedAmount(group)
	const mandatory = committedAmount(group)
	const gerealiseerd = invoicedAmount(group)

	return {
		key: [programme, costCentre, financialYear, glAccount].join('|'),
		programme: String(programme ?? ''),
		cost_centre: String(costCentre ?? ''),
		financial_year: financialYear ?? null,
		general_ledger_account: String(glAccount ?? ''),
		geautoriseerd,
		mandatory,
		gerealiseerd,
		vrij: geautoriseerd - mandatory - gerealiseerd,
	}
}

/**
 * The coding dimensions of a group, from its `keys` or the legacy flat shape.
 *
 * @param {object} group One aggregation group.
 * @return {object} programme, costCentre, financialYear and glAccount.
 */
function groupDimensions(group) {
	const keys = group?.keys ?? group ?? {}
	return {
		programme: keys?.programme ?? '',
		costCentre: firstPresent(keys, ['costCentre', 'cost_centre']) ?? '',
		financialYear:
			firstPresent(keys, ['financialYear', 'financial_year']) ?? null,
		glAccount:
			firstPresent(keys, ['generalLedgerAccount', 'general_ledger_account'])
			?? '',
	}
}

/**
 * The authorised amount joined from CommitmentBudget, or the legacy field.
 *
 * @param {object} group One aggregation group.
 * @return {number} The geautoriseerd amount (minor units).
 */
function authorisedAmount(group) {
	const joined = group?.joined ?? group ?? {}
	return Number(
		joined?.['CommitmentBudget.authorised_amount'] ?? group?.geautoriseerd ?? 0,
	)
}

/**
 * The remaining committed amount of a group, or the legacy field.
 *
 * @param {object} group One aggregation group.
 * @return {number} The verplicht amount (minor units).
 */
function committedAmount(group) {
	const values = group?.values ?? group ?? {}
	return Number(
		firstPresent(values, ['sum_remaining_committed', 'remaining_committed'])
			?? group?.verplicht
			?? 0,
	)
}

/**
 * The invoiced amount of a group, or the legacy field.
 *
 * @param {object} group One aggregation group.
 * @return {number} The gerealiseerd amount (minor units).
 */
function invoicedAmount(group) {
	const values = group?.values ?? group ?? {}
	return Number(
		firstPresent(values, ['sum_invoiced_amount', 'invoiced_amount'])
			?? group?.gerealiseerd
			?? 0,
	)
}

/**
 * The first of the named fields that is neither null nor undefined, read the
 * way `a?.x ?? a?.y` reads them.
 *
 * @param {unknown} source The object to read (any value; non-objects have no fields).
 * @param {Array<string>} names The field names, most preferred first.
 * @return {unknown} The value, or undefined when none is set.
 */
function firstPresent(source, names) {
	for (const name of names) {
		const value = source?.[name]
		if (value !== null && value !== undefined) {
			return value
		}
	}
	return undefined
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
 * KEYED BY SCHEMA PROPERTY NAME, NOT COLUMN NAME. OpenRegister filters on the
 * property as declared — `costCentre`, `financialYear`, `generalLedgerAccount`
 * — while the shard TABLE snake_cases them into columns. This used to emit the
 * column spelling, and a filter on a property that does not exist does not
 * error: it matches nothing and returns `{"results":[],"total":0}` with HTTP
 * 200, so the drilldown rendered "No underlying commitments found" over rows
 * that were plainly there. Measured on a live instance: `?programme=5.1&
 * costCentre=FAC-2026` returns 6, the same query with `cost_centre` returns 0.
 *
 * The ROW keeps its snake_case shape — the template and its tests read
 * `row.cost_centre` — so the mapping happens here, at the boundary where the
 * request is built.
 *
 * @param {object} row Normalised budget-line row.
 * @return {object} Filters keyed by CommitmentLine PROPERTY name.
 */
export function drilldownFilters(row) {
	const filters = {}
	if (row.programme) {
		filters.programme = row.programme
	}
	if (row.cost_centre) {
		filters.costCentre = row.cost_centre
	}
	if (row.financial_year !== null && row.financial_year !== undefined) {
		filters.financialYear = row.financial_year
	}
	if (row.general_ledger_account) {
		filters.generalLedgerAccount = row.general_ledger_account
	}
	return filters
}
