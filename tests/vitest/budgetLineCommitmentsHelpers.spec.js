/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the BudgetLineCommitments pure-logic helper layer
 * (src/views/budgetLineCommitmentsHelpers.js, REQ-VPL-011): aggregation
 * response normalisation (geautoriseerd/verplicht/gerealiseerd/vrij),
 * currency formatting, and the drilldown filter builder.
 *
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011
 */

import { describe, it, expect } from 'vitest'
import {
	normaliseBudgetLineRows,
	formatAmount,
	drilldownFilters,
} from '../../src/views/budgetLineCommitmentsHelpers.js'

describe('budgetLineCommitmentsHelpers — normaliseBudgetLineRows', () => {
	it('computes vrij = geautoriseerd - verplicht - gerealiseerd from the joined bucket', () => {
		const payload = {
			buckets: [
				{
					programme: '5.1',
					cost_centre: 'FAC-2026',
					financial_year: 2026,
					general_ledger_account: '4400',
					'CommitmentBudget.authorised_amount': 50000000,
					remaining_committed: 7500000,
					invoiced_amount: 2500000,
				},
			],
		}

		const rows = normaliseBudgetLineRows(payload)

		expect(rows).toHaveLength(1)
		expect(rows[0].geautoriseerd).toBe(50000000)
		expect(rows[0].mandatory).toBe(7500000)
		expect(rows[0].gerealiseerd).toBe(2500000)
		expect(rows[0].vrij).toBe(40000000)
	})

	it('accepts a bare array payload (no buckets wrapper)', () => {
		const rows = normaliseBudgetLineRows([
			{
				programme: '5.1',
				cost_centre: 'FAC-2026',
				financial_year: 2026,
				general_ledger_account: '4400',
				remaining_committed: 100,
				invoiced_amount: 0,
			},
		])
		expect(rows).toHaveLength(1)
	})

	it('returns an empty array for a malformed payload', () => {
		expect(normaliseBudgetLineRows(null)).toEqual([])
		expect(normaliseBudgetLineRows({})).toEqual([])
		expect(normaliseBudgetLineRows(undefined)).toEqual([])
	})

	it('builds a stable composite key per coderingscombinatie', () => {
		const rows = normaliseBudgetLineRows({
			buckets: [
				{
					programme: '5.1',
					cost_centre: 'FAC-2026',
					financial_year: 2026,
					general_ledger_account: '4400',
				},
			],
		})
		expect(rows[0].key).toBe('5.1|FAC-2026|2026|4400')
	})

	it('may report a negative vrij when committed+realised exceed authorized (over-commitment)', () => {
		const rows = normaliseBudgetLineRows({
			buckets: [
				{
					programme: '5.1',
					cost_centre: 'FAC-2026',
					financial_year: 2026,
					general_ledger_account: '4400',
					'CommitmentBudget.authorised_amount': 1000,
					remaining_committed: 800,
					invoiced_amount: 500,
				},
			],
		})
		expect(rows[0].vrij).toBe(-300)
	})
})

describe('budgetLineCommitmentsHelpers — formatAmount', () => {
	it('formats minor units as EUR currency', () => {
		// Locale-agnostic: assert the numeric grouping/decimal digits and the
		// currency symbol are present, not a specific locale's separators.
		const formatted = formatAmount(50000000)
		expect(formatted).toContain('€')
		expect(formatted).toContain('500')
		expect(formatted).toContain('000')
		expect(formatted).toContain('00')
	})

	it('handles zero and negative amounts', () => {
		expect(formatAmount(0)).toBeTruthy()
		expect(formatAmount(-100)).toContain('1')
	})

	it('falls back gracefully for non-numeric input', () => {
		expect(formatAmount('not-a-number')).toBeTruthy()
	})
})

describe('budgetLineCommitmentsHelpers — drilldownFilters', () => {
	// The ROW keeps its snake_case shape (the template reads row.cost_centre);
	// the FILTER must use the schema PROPERTY names, which is what OpenRegister
	// matches on. A filter naming a property that does not exist does not
	// error — it returns {"results":[],"total":0} with HTTP 200 — so getting
	// this wrong renders "No underlying commitments found" over live rows.
	// Measured: ?programme=5.1&costCentre=FAC-2026 returns 6; the same query
	// with cost_centre returns 0.
	it('builds exact-match filters keyed by PROPERTY name, not column name', () => {
		const filters = drilldownFilters({
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '4400',
		})
		expect(filters).toEqual({
			programme: '5.1',
			costCentre: 'FAC-2026',
			financialYear: 2026,
			generalLedgerAccount: '4400',
		})
	})

	it('never emits the snake_case column spelling', () => {
		const filters = drilldownFilters({
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '4400',
		})
		expect(Object.keys(filters)).not.toContain('cost_centre')
		expect(Object.keys(filters)).not.toContain('financial_year')
		expect(Object.keys(filters)).not.toContain('general_ledger_account')
	})

	it('omits empty/blank dimensions (e.g. a Contract-sourced rule with no general_ledger_account)', () => {
		const filters = drilldownFilters({
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '',
		})
		expect(filters).toEqual({
			programme: '5.1',
			costCentre: 'FAC-2026',
			financialYear: 2026,
		})
	})

	it('keeps financialYear 0 rather than dropping it as falsy', () => {
		// A 0 fiscal year is not a real budget year, but dropping it would
		// silently WIDEN the drilldown to every year — a filter that quietly
		// matches more is the failure mode this whole page has been bitten by.
		const filters = drilldownFilters({
			programme: '5.1',
			financial_year: 0,
		})
		expect(filters.financialYear).toBe(0)
	})

	it('reads the envelope OpenRegister actually returns (groups/keys/values/joined)', () => {
		// Captured verbatim from a live instance. The previous implementation
		// looked for `payload.buckets` holding flat rows, which no version of
		// the engine emits — so it returned [] and the page rendered its empty
		// state over live data (issue #1216).
		const rows = normaliseBudgetLineRows({
			name: 'committedVsRealisedPerBudgetLine',
			backend: 'postgres',
			groups: [
				{
					keys: {
						programme: '5.1',
						costCentre: 'FAC-2026',
						financialYear: 2026,
						generalLedgerAccount: '4400',
					},
					values: {
						sum_remaining_committed: 15000000,
						sum_invoiced_amount: 2500000,
					},
					joined: {
						'CommitmentBudget.authorised_amount': 80000000,
						'CommitmentBudget.realised_amount': 2500000,
					},
				},
			],
		})

		expect(rows).toHaveLength(1)
		expect(rows[0]).toEqual({
			key: '5.1|FAC-2026|2026|4400',
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '4400',
			geautoriseerd: 80000000,
			mandatory: 15000000,
			gerealiseerd: 2500000,
			// 80000000 - 15000000 - 2500000
			vrij: 62500000,
		})
	})

	it('still reads the legacy flat shape, for an older OpenRegister', () => {
		const rows = normaliseBudgetLineRows({
			buckets: [
				{
					programme: '5.1',
					cost_centre: 'FAC-2026',
					financial_year: 2026,
					general_ledger_account: '4400',
					remaining_committed: 100,
					invoiced_amount: 25,
					'CommitmentBudget.authorised_amount': 500,
				},
			],
		})

		expect(rows[0].mandatory).toBe(100)
		expect(rows[0].gerealiseerd).toBe(25)
		expect(rows[0].geautoriseerd).toBe(500)
		expect(rows[0].vrij).toBe(375)
	})

	it('returns [] for an aggregation that produced no groups', () => {
		expect(normaliseBudgetLineRows({ groups: [] })).toEqual([])
		expect(normaliseBudgetLineRows({})).toEqual([])
	})
})
