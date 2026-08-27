// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure series-shaping helpers for BudgetTrendChart.vue (design.md §3, §5,
// §9). Split out from the component so the "does the component correctly
// split a typed result into the Actual/Projected gap-series pair" /
// "does the unprojectable month render a gap, never a zero" vitest coverage
// (tasks.md task group 4/7) exercises plain functions over fixed,
// already-computed inputs — never the growth-rate arithmetic that produced
// them (that stays BudgetProjectionCalculator's own, already-tested,
// territory, per design.md §11).
//
// @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
// @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006

/** Account types whose cumulative series equals their trend (REQ-BPE-008). */
export const STOCK_ACCOUNT_TYPES = ['assets', 'liabilities', 'equity']

/**
 * EUR cents -> EUR float, for chart/table display.
 *
 * @param {number|null|undefined} cents Amount in EUR cents.
 * @return {number|null} EUR float, or null when cents is nullish.
 */
export function centsToEur(cents) {
	if (cents === null || cents === undefined) return null
	return cents / 100
}

/**
 * Whether every given account type is stock-typed (REQ-BCH-005) — an
 * account/LedgerGroup whose Cumulative toggle must render disabled because
 * cumulative would render identically to trend.
 *
 * @param {string[]} accountTypes One or more account types.
 * @return {boolean} True when every type is stock-typed, and at least one type is present.
 */
export function isAllStock(accountTypes) {
	if (!Array.isArray(accountTypes) || accountTypes.length === 0) return false
	return accountTypes.every((t) => STOCK_ACCOUNT_TYPES.includes(t))
}

/**
 * Split a combined typed trend series (REQ-BPE-006's seam) into the
 * Actual/Projected gap-series pair `BudgetTrendChart` plots (design.md §5):
 * two arrays, same length as `months`, whose non-null ranges never overlap.
 * An `unprojectable` month is `null` in BOTH — a genuine gap, never a
 * fabricated zero (REQ-BCH-004).
 *
 * @param {string[]} months Chronological `YYYY-MM` months.
 * @param {Object<string,{kind:string,amount?:number}>} trend Month => typed result.
 * @return {{actual: (number|null)[], projected: (number|null)[]}} The two EUR-valued series.
 */
export function splitActualProjected(months, trend) {
	const actual = []
	const projected = []
	for (const month of months) {
		const entry = trend?.[month]
		const kind = entry?.kind
		actual.push(kind === 'actual' ? centsToEur(entry.amount) : null)
		projected.push(kind === 'projected' ? centsToEur(entry.amount) : null)
	}
	return { actual, projected }
}

/**
 * The months, within `months`, whose typed trend result is `unprojectable`
 * — REQ-BCH-004's "genuine gap PLUS a distinct marker" months.
 *
 * @param {string[]} months Chronological `YYYY-MM` months.
 * @param {Object<string,{kind:string}>} trend Month => typed result.
 * @return {string[]} The unprojectable months, in order.
 */
export function unprojectableMonths(months, trend) {
	return months.filter((month) => trend?.[month]?.kind === 'unprojectable')
}

/**
 * The months, within `months`, whose typed trend result carries
 * `partial: true` (REQ-BPE-007's group roll-up, relayed unchanged).
 *
 * @param {string[]} months Chronological `YYYY-MM` months.
 * @param {Object<string,{partial?:boolean}>} trend Month => typed result.
 * @return {string[]} The partial months, in order.
 */
export function partialMonths(months, trend) {
	return months.filter((month) => trend?.[month]?.partial === true)
}

/**
 * A flat cents-per-month map (`budgeted`) as an ordered EUR array aligned
 * with `months` — a real `0` for any month absent from the map (an
 * honest "no plan entered" figure, never a fabricated one, per the
 * class docblock distinguishing this from the unprojectable case).
 *
 * @param {string[]} months Chronological `YYYY-MM` months.
 * @param {Object<string,number>} budgeted Month => cents.
 * @return {number[]} The EUR-valued series, same length as `months`.
 */
export function flatSeries(months, budgeted) {
	return months.map((month) => centsToEur(budgeted?.[month] ?? 0))
}

/**
 * Localised cell text for one (series, month) pair in the accessible
 * data-table fallback (REQ-BCH-004/REQ-BCH-009): an `unprojectable` month
 * reads the literal, localised "Cannot project yet" string — never a blank
 * cell and never a fabricated "€0".
 *
 * @param {(app: string, key: string, vars?: object) => string} t Translation function, `t(app, key, vars?)`.
 * @param {{kind?: string, amount?: number}|null} trendEntry The month's typed trend result (Actual/Projected columns), or null for the Begroot row.
 * @param {number|null} flatValue The EUR value for a flat series (Begroot), when `trendEntry` is not used.
 * @return {string} The cell's display text.
 */
export function cellText(t, trendEntry, flatValue) {
	if (trendEntry) {
		if (trendEntry.kind === 'unprojectable') {
			return t('shillinq', 'Cannot project yet')
		}
		return formatEurCents(trendEntry.amount)
	}
	return formatEurCents(
		flatValue === null || flatValue === undefined
			? null
			: Math.round(flatValue * 100),
	)
}

/**
 * Format EUR cents as a localised currency string, or an em dash for a
 * genuinely absent (not unprojectable) value.
 *
 * @param {number|null|undefined} cents Amount in EUR cents.
 * @return {string} The formatted string.
 */
export function formatEurCents(cents) {
	if (cents === null || cents === undefined) return '—'
	return new Intl.NumberFormat('nl-NL', {
		style: 'currency',
		currency: 'EUR',
	}).format(cents / 100)
}

/**
 * A sensible default `{ from, to }` YYYY-MM range for a placement (the
 * ChartOfAccountsDetail sidebar tab) that has no page-level date range of
 * its own to pass in: the trailing 12 months through the current month,
 * plus 3 months of projection headroom.
 *
 * @param {Date} [now] Injectable "now", for deterministic tests.
 * @return {{from: string, to: string}} The default range.
 */
export function defaultRange(now = new Date()) {
	const toDate = new Date(now.getFullYear(), now.getMonth() + 3, 1)
	const fromDate = new Date(now.getFullYear(), now.getMonth() - 11, 1)
	const format = (d) =>
		`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
	return { from: format(fromDate), to: format(toDate) }
}
