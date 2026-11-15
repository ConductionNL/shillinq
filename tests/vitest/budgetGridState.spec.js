/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetGrid view state: resolving the active administration, the mount
 * order, applying a grid payload (and resetting the expand/chart state),
 * expanding and collapsing rows, the one-open-chart toggle and the trend
 * chart props scoped to the grid's range. The grid request itself lives in
 * budgetGridLoad.spec.js and the pure helpers in budgetGridHelpers.spec.js.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchAdministrationContext } from '../../src/api/administrationApi.js'
import { flattenVisibleRows, trendChartProps } from '../../src/views/budgetGridHelpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn() }))
vi.mock('../../src/components/budget-charts/BudgetTrendChart.vue', () => ({ default: {} }))
vi.mock('../../src/components/BudgetLinesEditor.vue', () => ({ default: {} }))
vi.mock('../../src/components/MultiYearBudget.vue', () => ({ default: {} }))

const { default: BudgetGrid } = await import('../../src/views/BudgetGrid.vue')

/**
 * A fake instance with data and bound methods.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function grid(state = {}) {
	const vm = { ...BudgetGrid.data.call({}), t: (app, text) => text, ...state }
	for (const [name, fn] of Object.entries(BudgetGrid.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	fetchAdministrationContext.mockReset()
})

describe('BudgetGrid administration', () => {
	it('takes the active administration from the context', async () => {
		fetchAdministrationContext.mockResolvedValueOnce({ activeAdministrationId: 'adm-1' })
		const vm = grid()
		await vm.resolveAdministration()
		expect(vm.administrationId).toBe('adm-1')
	})

	it('has none when the context has none or cannot be read', async () => {
		fetchAdministrationContext.mockResolvedValueOnce({})
		const vm = grid({ administrationId: 'old' })
		await vm.resolveAdministration()
		expect(vm.administrationId).toBeNull()

		fetchAdministrationContext.mockRejectedValueOnce(new Error('x'))
		vm.administrationId = 'old'
		await vm.resolveAdministration()
		expect(vm.administrationId).toBeNull()
	})

	it('resolves the administration before loading the grid on mount', async () => {
		const order = []
		const vm = grid()
		vm.resolveAdministration = vi.fn(async () => order.push('resolve'))
		vm.loadGrid = vi.fn(async () => order.push('load'))
		await BudgetGrid.mounted.call(vm)
		expect(order).toEqual(['resolve', 'load'])
	})
})

describe('BudgetGrid payload', () => {
	it('takes the columns and rows and resets the expand and chart state', () => {
		const vm = grid({ expandedIds: new Set(['x']), openChartRowId: 'x' })
		vm.applyGrid({ columns: ['2026-01'], rows: [{ id: 'r' }], computedRows: [{ id: 'c' }] })
		expect(vm.columns).toEqual(['2026-01'])
		expect(vm.rows).toEqual([{ id: 'r' }])
		expect(vm.computedRows).toEqual([{ id: 'c' }])
		expect(vm.expandedIds.size).toBe(0)
		expect(vm.openChartRowId).toBeNull()
	})

	it('defaults every non-array part to empty', () => {
		const vm = grid()
		vm.applyGrid({ columns: 'x', rows: null })
		expect(vm.columns).toEqual([])
		expect(vm.rows).toEqual([])
		expect(vm.computedRows).toEqual([])
		vm.applyGrid(undefined)
		expect(vm.rows).toEqual([])
	})

	it('clears the grid', () => {
		const vm = grid({ columns: [1], rows: [1], computedRows: [1] })
		vm.clearGrid()
		expect([vm.columns, vm.rows, vm.computedRows]).toEqual([[], [], []])
	})
})

describe('BudgetGrid rows and charts', () => {
	it('expands and collapses a row with a fresh set each time', () => {
		const vm = grid()
		const before = vm.expandedIds
		vm.toggleRow('g1')
		expect(vm.expandedIds.has('g1')).toBe(true)
		expect(vm.expandedIds).not.toBe(before)
		vm.toggleRow('g1')
		expect(vm.expandedIds.has('g1')).toBe(false)
	})

	it('opens one chart at a time and closes it on a second press', () => {
		const vm = grid()
		vm.toggleChart('a')
		expect(vm.openChartRowId).toBe('a')
		vm.toggleChart('b')
		expect(vm.openChartRowId).toBe('b')
		vm.toggleChart('b')
		expect(vm.openChartRowId).toBeNull()
	})

	it('scopes the trend chart props to the administration and range', () => {
		const vm = grid({ administrationId: 'adm-1', startPeriod: '2026-01', endPeriod: '2026-12' })
		const row = { id: 'g1', type: 'ledgerGroup', name: 'Omzet' }
		expect(vm.trendChartProps(row)).toEqual(
			trendChartProps(row, 'adm-1', { startPeriod: '2026-01', endPeriod: '2026-12' }),
		)
	})

	it('shows the rows the expanded set opens', () => {
		const rows = [{ id: 'g1', children: [{ id: 'a1' }] }]
		const expandedIds = new Set(['g1'])
		expect(BudgetGrid.computed.visibleRows.call({ rows, expandedIds }))
			.toEqual(flattenVisibleRows(rows, expandedIds))
	})
})
