/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * budget-charts task group 5: a "view trend" button on every budget grid
 * row opens BudgetTrendChart inline beneath that row. One chart is open at
 * a time; the chart reads the row's own group or account and the grid's
 * own period range.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-001
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	nextOpenChartRow,
	trendChartProps,
} from '../../src/views/budgetGridHelpers.js'

const grid = fs.readFileSync(
	path.resolve(__dirname, '../../src/views/BudgetGrid.vue'),
	'utf8',
)

describe('Budget grid trend toggle', () => {
	it('opens one chart at a time and closes it on a second press', () => {
		expect(nextOpenChartRow(null, 'g1')).toBe('g1')
		expect(nextOpenChartRow('g1', 'a-4000')).toBe('a-4000')
		expect(nextOpenChartRow('a-4000', 'a-4000')).toBeNull()
	})

	it('scopes a ledger group row by its id and an account row by its number', () => {
		const range = { startPeriod: '2026-01', endPeriod: '2026-12' }
		expect(
			trendChartProps(
				{ id: 'g1', kind: 'ledgerGroup', label: 'Personeel' },
				'adm-1',
				range,
			),
		).toEqual({
			scope: 'ledgerGroup',
			id: 'g1',
			name: 'Personeel',
			administrationId: 'adm-1',
			range: { from: '2026-01', to: '2026-12' },
		})
		expect(
			trendChartProps(
				{
					id: 'acc-1',
					kind: 'account',
					label: 'Lonen',
					accountNumber: '4000',
				},
				'adm-1',
				range,
			),
		).toEqual({
			scope: 'account',
			id: '4000',
			name: 'Lonen',
			administrationId: 'adm-1',
			range: { from: '2026-01', to: '2026-12' },
		})
	})

	it('gives a computed row no chart', () => {
		expect(
			trendChartProps({ code: 'result', kind: 'computed' }, 'adm-1', {
				startPeriod: '2026-01',
				endPeriod: '2026-12',
			}),
		).toBeNull()
	})

	it('renders the toggle per row and mounts the chart beneath the open row', () => {
		expect(grid).toContain('data-testid="budget-grid-view-trend-toggle"')
		expect(grid).toMatch(/:aria-expanded="\s*openChartRowId === row\.id\s*"/)
		expect(grid).toContain('@keyup.space')
		expect(grid).toMatch(
			/<BudgetTrendChart[\s\S]*v-bind="trendChartProps\(row\)"/,
		)
		expect(grid).toContain(
			"import BudgetTrendChart from '../components/budget-charts/BudgetTrendChart.vue'",
		)
	})
})
