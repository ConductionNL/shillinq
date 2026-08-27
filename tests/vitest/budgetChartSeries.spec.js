/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for src/components/budget-charts/budgetChartSeries.js — the
 * pure series-shaping helpers BudgetTrendChart.vue's computed properties
 * are built from.
 *
 * The single thing worth pinning hardest here (design.md §3, REQ-BCH-004):
 * an `unprojectable` month must NEVER render as a plotted zero. A zero
 * line would read as "the engine forecast a decline to nothing" — a
 * fabricated claim the projection engine explicitly refuses to make. Every
 * test below that touches an unprojectable month asserts BOTH halves: the
 * gap (null in the plotted array) AND that the SAME month is discoverable
 * separately (in `unprojectableMonths()`) so the component can still draw
 * its marker + tooltip — a null alone, with no way to tell "gap because
 * unprojectable" from "gap because this series doesn't cover this month at
 * all", would not be enough.
 *
 * The arithmetic that PRODUCED these typed results (growth-rate fitting,
 * the seam rule itself) is `BudgetProjectionCalculator`'s own,
 * already-tested territory (budget-projection-engine) — nothing here
 * re-verifies it; every fixture below is a fixed, already-computed typed
 * result, per design.md §11's `@e2e exclude` framing extended to vitest.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
 */

import { describe, expect, it } from 'vitest'
import {
	cellText,
	centsToEur,
	defaultRange,
	flatSeries,
	formatEurCents,
	isAllStock,
	partialMonths,
	splitActualProjected,
	unprojectableMonths,
} from '../../src/components/budget-charts/budgetChartSeries.js'

const MONTHS = ['2027-01', '2027-02', '2027-03', '2027-04']

describe('splitActualProjected — the Actual/Projected gap-series pair', () => {
	it('never plots the same month on both series (REQ-BCH-006)', () => {
		const trend = {
			'2027-01': { kind: 'actual', amount: 10000 },
			'2027-02': { kind: 'actual', amount: 11000 },
			'2027-03': { kind: 'projected', amount: 12000 },
			'2027-04': { kind: 'projected', amount: 13000 },
		}

		const { actual, projected } = splitActualProjected(MONTHS, trend)

		expect(actual).toEqual([100, 110, null, null])
		expect(projected).toEqual([null, null, 120, 130])
		// The negative half of "never both": for every index, at most one
		// of the two arrays is non-null.
		MONTHS.forEach((_, i) => {
			expect(actual[i] === null || projected[i] === null).toBe(true)
		})
	})

	it('renders an unprojectable month as a GAP (null) on BOTH series — never a zero (REQ-BCH-004)', () => {
		const trend = {
			'2027-01': { kind: 'actual', amount: 10000 },
			'2027-02': {
				kind: 'unprojectable',
				reason: 'insufficient-data',
				validSteps: 1,
			},
			'2027-03': {
				kind: 'unprojectable',
				reason: 'insufficient-data',
				validSteps: 1,
			},
			'2027-04': { kind: 'projected', amount: 13000 },
		}

		const { actual, projected } = splitActualProjected(MONTHS, trend)

		expect(actual[1]).toBeNull()
		expect(actual[2]).toBeNull()
		expect(projected[1]).toBeNull()
		expect(projected[2]).toBeNull()
		// The negative half: NEITHER array may contain a literal 0 for an
		// unprojectable month — a zero at that index would read as "the
		// engine forecast a decline to nothing", the fabricated claim
		// REQ-BCH-004 forbids.
		expect(actual[1]).not.toBe(0)
		expect(projected[1]).not.toBe(0)
	})

	it('handles a month missing from the trend map (no entry at all) as a gap, not a crash', () => {
		const { actual, projected } = splitActualProjected(MONTHS, {})

		expect(actual).toEqual([null, null, null, null])
		expect(projected).toEqual([null, null, null, null])
	})
})

describe('unprojectableMonths — the second, always-discoverable channel for the marker+tooltip', () => {
	it('lists exactly the months whose kind is unprojectable, in order', () => {
		const trend = {
			'2027-01': { kind: 'actual', amount: 100 },
			'2027-02': { kind: 'unprojectable', reason: 'insufficient-data' },
			'2027-03': { kind: 'projected', amount: 200 },
			'2027-04': { kind: 'unprojectable', reason: 'no-history' },
		}

		expect(unprojectableMonths(MONTHS, trend)).toEqual(['2027-02', '2027-04'])
	})

	it('is empty when every month is actual or projected', () => {
		const trend = {
			'2027-01': { kind: 'actual', amount: 100 },
			'2027-02': { kind: 'projected', amount: 200 },
			'2027-03': { kind: 'projected', amount: 200 },
			'2027-04': { kind: 'projected', amount: 200 },
		}

		expect(unprojectableMonths(MONTHS, trend)).toEqual([])
	})
})

describe('partialMonths — REQ-BPE-007 group roll-up tag, relayed unchanged', () => {
	it('lists months carrying partial: true', () => {
		const trend = {
			'2027-01': { kind: 'projected', amount: 100 },
			'2027-02': { kind: 'projected', amount: 200, partial: true },
			'2027-03': { kind: 'unprojectable' },
			'2027-04': { kind: 'projected', amount: 400, partial: false },
		}

		expect(partialMonths(MONTHS, trend)).toEqual(['2027-02'])
	})
})

describe('isAllStock — REQ-BCH-005 Cumulative-toggle disabling', () => {
	it('is true when every account type is stock-typed', () => {
		expect(isAllStock(['assets'])).toBe(true)
		expect(isAllStock(['assets', 'liabilities', 'equity'])).toBe(true)
	})

	it('is false when any account type is flow-typed (revenue/expenses)', () => {
		expect(isAllStock(['revenue'])).toBe(false)
		expect(isAllStock(['assets', 'revenue'])).toBe(false)
	})

	it('is false for an empty/unresolved type list — never disables the toggle by default', () => {
		expect(isAllStock([])).toBe(false)
		expect(isAllStock(undefined)).toBe(false)
	})
})

describe('flatSeries — the Begroot series: real absence is a real 0, never fabricated', () => {
	it('fills a month absent from the map with 0 — an honest "no plan entered" value', () => {
		const series = flatSeries(MONTHS, { '2027-01': 50000 })

		expect(series).toEqual([500, 0, 0, 0])
	})

	it('is entirely zero when the account has no owning LedgerGroup at all', () => {
		expect(flatSeries(MONTHS, {})).toEqual([0, 0, 0, 0])
	})
})

describe('cellText — the accessible data-table fallback (REQ-BCH-004/REQ-BCH-009)', () => {
	const t = (app, key) => key

	it('renders the literal "Cannot project yet" text for an unprojectable month — never blank, never "€0"', () => {
		const text = cellText(
			t,
			{ kind: 'unprojectable', reason: 'insufficient-data' },
			null,
		)

		expect(text).toBe('Cannot project yet')
		expect(text).not.toBe('')
		expect(text).not.toMatch(/€\s*0/)
	})

	it('renders a real formatted amount for an actual/projected month', () => {
		expect(cellText(t, { kind: 'actual', amount: 123456 }, null)).toContain(
			'1.234,56',
		)
	})
})

describe('centsToEur / formatEurCents', () => {
	it('converts cents to euros', () => {
		expect(centsToEur(12345)).toBe(123.45)
		expect(centsToEur(null)).toBeNull()
		expect(centsToEur(undefined)).toBeNull()
	})

	it('formats a null amount as an em dash, never "€0"', () => {
		expect(formatEurCents(null)).toBe('—')
	})

	it('formats a genuine zero as a real currency zero, not an em dash', () => {
		expect(formatEurCents(0)).not.toBe('—')
		expect(formatEurCents(0)).toContain('0,00')
	})
})

describe('defaultRange', () => {
	it('spans the trailing 12 months through 3 months of projection headroom', () => {
		const range = defaultRange(new Date(2027, 5, 15)) // June 2027 (0-indexed month 5)

		expect(range.from).toBe('2026-07')
		expect(range.to).toBe('2027-09')
	})
})
