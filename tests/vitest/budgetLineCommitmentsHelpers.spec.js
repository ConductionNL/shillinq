/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the BudgetLineCommitments pure-logic helper layer
 * (src/views/budgetLineCommitmentsHelpers.js, REQ-VPL-011): aggregation
 * response normalisation (authorised/committed/realised/free), currency
 * formatting, and the drilldown filter builder.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import { describe, it, expect } from 'vitest'
import {
	normaliseBudgetLineRows,
	formatAmount,
	drilldownFilters,
} from '../../src/views/budgetLineCommitmentsHelpers.js'

describe('budgetLineCommitmentsHelpers — normaliseBudgetLineRows', () => {
	it('computes free = authorised - committed - realised from the joined bucket', () => {
		const payload = {
			buckets: [
				{
					programme: '5.1',
					costCentre: 'FAC-2026',
					fiscalYear: 2026,
					glAccount: '4400',
					'Budget.authorisedAmount': 50000000,
					remainingCommitted: 7500000,
					invoicedAmount: 2500000,
				},
			],
		}

		const rows = normaliseBudgetLineRows(payload)

		expect(rows).toHaveLength(1)
		expect(rows[0].authorised).toBe(50000000)
		expect(rows[0].committed).toBe(7500000)
		expect(rows[0].realised).toBe(2500000)
		expect(rows[0].free).toBe(40000000)
	})

	it('accepts a bare array payload (no buckets wrapper)', () => {
		const rows = normaliseBudgetLineRows([
			{ programme: '5.1', costCentre: 'FAC-2026', fiscalYear: 2026, glAccount: '4400', remainingCommitted: 100, invoicedAmount: 0 },
		])
		expect(rows).toHaveLength(1)
	})

	it('returns an empty array for a malformed payload', () => {
		expect(normaliseBudgetLineRows(null)).toEqual([])
		expect(normaliseBudgetLineRows({})).toEqual([])
		expect(normaliseBudgetLineRows(undefined)).toEqual([])
	})

	it('builds a stable composite key per budget coding combination', () => {
		const rows = normaliseBudgetLineRows({
			buckets: [
				{ programme: '5.1', costCentre: 'FAC-2026', fiscalYear: 2026, glAccount: '4400' },
			],
		})
		expect(rows[0].key).toBe('5.1|FAC-2026|2026|4400')
	})

	it('may report a negative free amount when committed+realised exceed authorised (over-commitment)', () => {
		const rows = normaliseBudgetLineRows({
			buckets: [
				{
					programme: '5.1',
					costCentre: 'FAC-2026',
					fiscalYear: 2026,
					glAccount: '4400',
					'Budget.authorisedAmount': 1000,
					remainingCommitted: 800,
					invoicedAmount: 500,
				},
			],
		})
		expect(rows[0].free).toBe(-300)
	})

	it('does not read the pre-rename Dutch keys', () => {
		// The rename (shillinq#485 follow-up) moved every field to English. A
		// bucket still carrying the OLD keys must NOT be silently understood —
		// otherwise a stale producer would keep working and the rename would
		// look complete while half the system spoke the old vocabulary.
		const rows = normaliseBudgetLineRows({
			buckets: [
				{
					programma: '5.1',
					kostenplaats: 'FAC-2026',
					boekjaar: 2026,
					grootboekrekening: '4400',
					'Budget.geautoriseerd_bedrag': 50000000,
					restant_verplicht: 7500000,
					gefactureerd_bedrag: 2500000,
				},
			],
		})

		expect(rows[0].authorised).toBe(0)
		expect(rows[0].committed).toBe(0)
		expect(rows[0].realised).toBe(0)
		expect(rows[0].programme).toBe('')
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
			costCentre: 'FAC-2026',
			fiscalYear: 2026,
			glAccount: '4400',
		})
		expect(filters).toEqual({
			programme: '5.1',
			costCentre: 'FAC-2026',
			fiscalYear: 2026,
			glAccount: '4400',
		})
	})

	it('omits empty/blank dimensions (e.g. a Contract-sourced line with no glAccount)', () => {
		const filters = drilldownFilters({
			programme: '5.1',
			costCentre: 'FAC-2026',
			fiscalYear: 2026,
			glAccount: '',
		})
		expect(filters).toEqual({
			programme: '5.1',
			costCentre: 'FAC-2026',
			fiscalYear: 2026,
		})
	})
})
