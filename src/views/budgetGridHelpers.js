// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for BudgetGrid.vue (`budget-grid-view`, REQ-BGV-001/002/006).
 *
 * The whole row tree (LedgerGroup rows nested to child groups or resolved
 * Account leaves) and every column's already-computed value arrive in ONE
 * GET /api/budget-grid response (design.md §1c: expanding/collapsing a row
 * MUST cost zero further requests). This module is the client-side half of
 * that guarantee: flattening the nested tree into the currently-visible flat
 * row list is a pure, synchronous function of the already-fetched tree plus
 * a Set of expanded row ids — it never touches the network.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */

/**
 * Flatten a nested row tree into the currently-visible rows, in display
 * order, each annotated with its indentation `depth`. A row's children are
 * included only when the row's own id is present in `expandedIds` — this is
 * the ENTIRE expand/collapse mechanism; it never re-fetches anything.
 *
 * @param {Array<object>} rows The (possibly nested) row list from the API response.
 * @param {Set<string>} expandedIds Ids of rows currently expanded.
 * @param {number} depth Starting indentation depth (used for the recursive call).
 * @return {Array<object>} Flattened rows, each with an added `depth` field.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */
export function flattenVisibleRows(rows, expandedIds, depth = 0) {
	const out = []
	for (const row of rows || []) {
		out.push({ ...row, depth })
		const hasChildren = Array.isArray(row.children) && row.children.length > 0
		if (hasChildren && expandedIds.has(row.id)) {
			out.push(...flattenVisibleRows(row.children, expandedIds, depth + 1))
		}
	}
	return out
}

/**
 * Format a minor-units (cents) amount as an EUR currency string. `null`
 * renders as an explicit dash — REQ-BGV-001's "no default AnnualBudget for
 * this fiscal year" empty state is distinct from a real `0` value and MUST
 * NOT be silently coerced to it.
 *
 * @param {?number} cents Amount in minor units, or null for "no value".
 * @return {string} Formatted currency string, or '—' when cents is null.
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
 */
export function formatAmount(cents) {
	if (cents === null || cents === undefined) {
		return '—'
	}
	const value = (Number(cents) || 0) / 100
	try {
		return new Intl.NumberFormat(undefined, {
			style: 'currency',
			currency: 'EUR',
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(value)
	} catch {
		return value.toFixed(2)
	}
}

/**
 * Default displayed period range: January - December of the current
 * calendar year, at month granularity — matches the operator's own
 * spreadsheet's januari..december shape (proposal.md).
 *
 * @param {Date} [now] Injectable clock for testing.
 * @return {{startPeriod: string, endPeriod: string, granularity: string}}
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
 */
export function defaultRange(now = new Date()) {
	const year = now.getFullYear()
	return {
		startPeriod: `${year}-01`,
		endPeriod: `${year}-12`,
		granularity: 'month',
	}
}

/**
 * Whether a deviation cell should be flagged favorable/unfavorable in the
 * UI. Returns null when no framing applies (REQ-BGV-004: balance-sheet or
 * mixed-accountType rows compute a raw difference but carry no
 * favorable/unfavorable framing) so the caller can render the amount
 * WITHOUT the favorable/unfavorable text label rather than guessing one.
 *
 * @param {object} cell A cell object as returned by the API (`{budget, actual, deviation, favorable}`).
 * @return {?boolean} true/false when framed, null when not applicable.
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 */
export function favorableState(cell) {
	if (!cell || cell.favorable === null || cell.favorable === undefined) {
		return null
	}
	return Boolean(cell.favorable)
}
