/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetGrid view: it resolves the active administration, loads the grid in one
 * request, maps a failed load to a message by status, applies a loaded grid,
 * and toggles a row's expand state and its trend chart locally. The template is
 * rendered in its loading, empty and loaded states with Vue's server renderer.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const fetchAdministrationContext = vi.fn()
vi.mock('../../src/api/administrationApi.js', () => ({
	fetchAdministrationContext: () => fetchAdministrationContext(),
}))

vi.mock('@nextcloud/vue', () => ({
	NcAppContent: { render() { return h('main', this.$slots.default?.()) } },
	NcEmptyContent: { props: ['name'], render() { return h('div', { role: 'note' }, this.name) } },
	NcLoadingIcon: { render() { return h('span', { role: 'progressbar' }) } },
}))
const stub = (name) => ({ default: { render() { return h('div', { 'data-stub': name }) } } })
vi.mock('vue-material-design-icons/ChartLine.vue', () => stub('ChartLine'))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => stub('ChevronDown'))
vi.mock('vue-material-design-icons/ChevronRight.vue', () => stub('ChevronRight'))
vi.mock('../../src/components/budget-charts/BudgetTrendChart.vue', () => stub('BudgetTrendChart'))
vi.mock('../../src/components/BudgetGridCell.vue', () => stub('BudgetGridCell'))
vi.mock('../../src/components/BudgetLinesEditor.vue', () => stub('BudgetLinesEditor'))
vi.mock('../../src/components/MultiYearBudget.vue', () => stub('MultiYearBudget'))

const { default: BudgetGrid } = await import('../../src/views/BudgetGrid.vue')

/**
 * The view's methods bound to a plain context seeded from its data.
 *
 * @param {object} data Overrides on top of the default data.
 * @return {object} The context.
 */
function context(data = {}) {
	const ctx = { ...BudgetGrid.data(), ...data }
	ctx.t = globalThis.t
	for (const [name, method] of Object.entries(BudgetGrid.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * SSR-render the view with its data overridden.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data = {}) {
	const app = createSSRApp({ ...BudgetGrid, mounted() {}, data: () => ({ ...BudgetGrid.data(), ...data }) })
	app.config.globalProperties.t = globalThis.t
	return renderToString(app)
}

beforeEach(() => {
	axiosGet.mockReset()
	fetchAdministrationContext.mockReset()
})

describe('BudgetGrid.resolveAdministration', () => {
	it('takes the active administration from the session context', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'adm-1' })
		const ctx = context()
		await ctx.resolveAdministration()
		expect(ctx.administrationId).toBe('adm-1')
	})

	it('falls back to none when the context has no active administration or throws', async () => {
		fetchAdministrationContext.mockResolvedValue({})
		const ctx = context({ administrationId: 'x' })
		await ctx.resolveAdministration()
		expect(ctx.administrationId).toBeNull()

		fetchAdministrationContext.mockRejectedValue(new Error('no session'))
		const ctx2 = context({ administrationId: 'x' })
		await ctx2.resolveAdministration()
		expect(ctx2.administrationId).toBeNull()
	})
})

describe('BudgetGrid.loadGrid', () => {
	it('refuses without an administration and says so', async () => {
		const ctx = context({ administrationId: null, loading: true })
		await ctx.loadGrid()
		expect(ctx.loading).toBe(false)
		expect(ctx.errorMessage).toBe('No accessible administration.')
		expect(axiosGet).not.toHaveBeenCalled()
	})

	it('fetches the whole grid in one request and applies it', async () => {
		axiosGet.mockResolvedValue({ data: { columns: [{ id: 'c' }], rows: [{ id: 'r' }], computedRows: [{ id: 'cr' }] } })
		const ctx = context({ administrationId: 'adm-1', startPeriod: '2026-01', endPeriod: '2026-12', granularity: 'month' })
		await ctx.loadGrid()
		expect(axiosGet).toHaveBeenCalledTimes(1)
		const [, options] = axiosGet.mock.calls[0]
		expect(options.params).toEqual({ administrationId: 'adm-1', startPeriod: '2026-01', endPeriod: '2026-12', granularity: 'month' })
		expect(ctx.rows).toEqual([{ id: 'r' }])
		expect(ctx.columns).toEqual([{ id: 'c' }])
		expect(ctx.computedRows).toEqual([{ id: 'cr' }])
		expect(ctx.loading).toBe(false)
	})

	it('maps a failed load to a message by status and clears the grid', async () => {
		const cases = [
			[404, 'Administration not found.'],
			[401, 'Not logged in.'],
			[500, 'Failed to load the budget grid.'],
			[undefined, 'Failed to load the budget grid.'],
		]
		for (const [status, message] of cases) {
			axiosGet.mockRejectedValue({ response: status === undefined ? undefined : { status } })
			const ctx = context({ administrationId: 'adm-1', rows: [{ id: 'old' }], columns: [{ id: 'c' }] })
			await ctx.loadGrid()
			expect(ctx.errorMessage).toBe(message)
			expect(ctx.rows).toEqual([])
			expect(ctx.columns).toEqual([])
			expect(ctx.loading).toBe(false)
		}
	})
})

describe('BudgetGrid.applyGrid', () => {
	it('keeps only array payloads and resets expansion and the open chart', () => {
		const ctx = context({ expandedIds: new Set(['a']), openChartRowId: 'a' })
		ctx.applyGrid({ columns: 'nope', rows: [{ id: 'r' }], computedRows: null })
		expect(ctx.columns).toEqual([])
		expect(ctx.rows).toEqual([{ id: 'r' }])
		expect(ctx.computedRows).toEqual([])
		expect(ctx.expandedIds.size).toBe(0)
		expect(ctx.openChartRowId).toBeNull()
	})
})

describe('BudgetGrid.toggleRow and toggleChart', () => {
	it('adds and removes a row id without mutating the old set', () => {
		const ctx = context()
		const first = ctx.expandedIds
		ctx.toggleRow('r1')
		expect(ctx.expandedIds.has('r1')).toBe(true)
		expect(first.has('r1')).toBe(false)
		ctx.toggleRow('r1')
		expect(ctx.expandedIds.has('r1')).toBe(false)
	})

	it('opens a row chart, then closes it when toggled again', () => {
		const ctx = context()
		ctx.toggleChart('r1')
		expect(ctx.openChartRowId).toBe('r1')
		ctx.toggleChart('r1')
		expect(ctx.openChartRowId).toBeNull()
	})
})

describe('BudgetGrid.trendChartProps', () => {
	it('builds the trend chart props from the row, administration and range', () => {
		const ctx = context({ administrationId: 'adm-1', startPeriod: '2026-01', endPeriod: '2026-12' })
		const props = ctx.trendChartProps({ kind: 'account', accountNumber: 4000, label: 'Sales' })
		expect(props).toMatchObject({
			scope: 'account',
			id: '4000',
			name: 'Sales',
			administrationId: 'adm-1',
			range: { from: '2026-01', to: '2026-12' },
		})
		expect(ctx.trendChartProps({ kind: 'total', label: 'x' })).toBeNull()
	})
})

describe('BudgetGrid.mounted', () => {
	it('resolves the administration and then loads the grid, in order', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'adm-1' })
		axiosGet.mockResolvedValue({ data: { columns: [], rows: [{ id: 'r' }], computedRows: [] } })
		const ctx = context()
		await BudgetGrid.mounted.call(ctx)
		expect(ctx.administrationId).toBe('adm-1')
		expect(axiosGet).toHaveBeenCalledTimes(1)
		expect(ctx.rows).toEqual([{ id: 'r' }])
	})
})

describe('BudgetGrid markup', () => {
	it('shows a spinner while loading', async () => {
		const html = await render({ loading: true, rows: [] })
		expect(html).toContain('role="progressbar"')
	})

	it('shows the empty state when nothing loaded', async () => {
		const html = await render({ loading: false, rows: [] })
		expect(html).toContain('role="note"')
	})

	it('draws the table once rows are loaded', async () => {
		const html = await render({
			loading: false,
			columns: [{ id: 'c', label: 'Jan' }],
			rows: [{ id: 'r', kind: 'account', label: 'Sales', cells: [] }],
		})
		expect(html).toContain('budget-grid__table')
		expect(html).not.toContain('role="progressbar"')
	})

	it('draws an expanded ledger group with its open trend chart and the computed footer', async () => {
		const html = await render({
			loading: false,
			columns: [{ id: 'jan', label: 'Jan' }],
			rows: [{ id: 'g1', kind: 'ledgerGroup', label: 'Revenue', children: [{ id: 'a1', kind: 'account', accountNumber: 4000, label: 'Sales', cells: [{}] }], cells: [{}] }],
			computedRows: [{ id: 'total', label: 'Result', cells: [{}] }],
			expandedIds: new Set(['g1']),
			openChartRowId: 'g1',
		})
		expect(html).toContain('budget-grid__chart-row')
		expect(html).toContain('data-stub="BudgetTrendChart"')
		expect(html).toContain('data-row-kind="computed"')
	})

	it('shows the error line when a load failed but rows remain', async () => {
		const html = await render({ loading: false, rows: [{ id: 'r', kind: 'account', label: 'S', cells: [] }], columns: [{ id: 'c', label: 'J' }], errorMessage: 'Failed to load the budget grid.' })
		expect(html).toContain('role="alert"')
		expect(html).toContain('Failed to load the budget grid.')
	})
})
