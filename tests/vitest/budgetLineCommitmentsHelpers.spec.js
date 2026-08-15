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
					'Budget.authorised_amount': 50000000,
					restant_verplicht: 7500000,
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
				restant_verplicht: 100,
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
					'Budget.authorised_amount': 1000,
					restant_verplicht: 800,
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
	it('builds exact-match filters for a complete row', () => {
		const filters = drilldownFilters({
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '4400',
		})
		expect(filters).toEqual({
			programme: '5.1',
			cost_centre: 'FAC-2026',
			financial_year: 2026,
			general_ledger_account: '4400',
		})
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
			cost_centre: 'FAC-2026',
			financial_year: 2026,
		})
	})
})
