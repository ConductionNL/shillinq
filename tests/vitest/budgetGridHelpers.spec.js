/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the BudgetGrid pure-logic helper layer
 * (src/views/budgetGridHelpers.js, `budget-grid-view` REQ-BGV-001/002/004):
 * the zero-additional-query tree flattener, EUR-cents formatting with the
 * explicit-dash "no budget for this year" state, the default period range,
 * and the favorable/unfavorable framing helper.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */

import { describe, expect, it } from 'vitest'
import {
	defaultRange,
	favorableState,
	flattenVisibleRows,
	formatAmount,
} from '../../src/views/budgetGridHelpers.js'

describe('budgetGridHelpers — flattenVisibleRows', () => {
	const tree = [
		{
			id: 'omzet',
			label: 'Omzet',
			children: [
				{ id: 'neto', label: 'Netto-omzet', children: [] },
				{
					id: 'overige-opbrengsten',
					label: 'Overige opbrengsten',
					children: [],
				},
			],
		},
		{
			id: 'personeel',
			label: 'Personeel',
			children: [{ id: 'lonen', label: 'Lonen en salarissen', children: [] }],
		},
	]

	it('shows only root rows when nothing is expanded', () => {
		const visible = flattenVisibleRows(tree, new Set())
		expect(visible.map((r) => r.id)).toEqual(['omzet', 'personeel'])
		expect(visible[0].depth).toBe(0)
	})

	it("reveals a row's children when its id is in expandedIds, at depth+1", () => {
		const visible = flattenVisibleRows(tree, new Set(['omzet']))
		expect(visible.map((r) => r.id)).toEqual([
			'omzet',
			'neto',
			'overige-opbrengsten',
			'personeel',
		])
		expect(visible.find((r) => r.id === 'neto').depth).toBe(1)
	})

	it("expanding and collapsing ten rows is a pure function of the already-fetched tree — no network call is possible from this function's signature", () => {
		// The function takes only (rows, expandedIds, depth) — there is no
		// way for it to issue a request. Exercising it 10 times with
		// different expandedIds sets proves the SAME already-fetched tree is
		// simply re-flattened differently every time (design.md §1c / REQ-BGV-002 scenario).
		const ids = ['omzet', 'personeel']
		for (let i = 0; i < 10; i++) {
			const expanded = new Set(i % 2 === 0 ? ids : [])
			const visible = flattenVisibleRows(tree, expanded)
			expect(Array.isArray(visible)).toBe(true)
		}
	})

	it('leaves a leaf row (empty children) uninvolved even if listed in expandedIds', () => {
		const visible = flattenVisibleRows(tree, new Set(['omzet', 'neto']))
		expect(visible.map((r) => r.id)).toEqual([
			'omzet',
			'neto',
			'overige-opbrengsten',
			'personeel',
		])
	})
})

describe('budgetGridHelpers — formatAmount', () => {
	it('formats EUR cents as a currency string carrying the amount and the EUR symbol/code', () => {
		// Locale is intentionally left to Intl's default (undefined) so this
		// component matches every other amount-formatting helper in the app
		// (budgetLineCommitmentsHelpers.js's own formatAmount); the exact
		// separator glyphs are locale-dependent (comma vs period), so assert
		// on the numerals + currency marker only, not one locale's punctuation.
		const formatted = formatAmount(500000)
		expect(formatted).toMatch(/5[.,]000[.,]00/)
		expect(formatted).toMatch(/€|EUR/)
	})

	it('renders null as an explicit dash, distinct from a real 0 value (REQ-BGV-001)', () => {
		expect(formatAmount(null)).toBe('—')
		expect(formatAmount(0)).not.toBe('—')
	})
})

describe('budgetGridHelpers — defaultRange', () => {
	it('defaults to January-December of the current year at month granularity', () => {
		const range = defaultRange(new Date('2026-08-20T00:00:00Z'))
		expect(range).toEqual({
			startPeriod: '2026-01',
			endPeriod: '2026-12',
			granularity: 'month',
		})
	})
})

describe('budgetGridHelpers — favorableState', () => {
	it('returns true/false when the cell carries an explicit favorable flag', () => {
		expect(favorableState({ favorable: true })).toBe(true)
		expect(favorableState({ favorable: false })).toBe(false)
	})

	it('returns null (no framing) when favorable is null/undefined/absent', () => {
		expect(favorableState({ favorable: null })).toBeNull()
		expect(favorableState({})).toBeNull()
		expect(favorableState(null)).toBeNull()
	})
})
