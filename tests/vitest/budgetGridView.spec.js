/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetGrid as it renders: the period controls, the loading and empty
 * states, the flat row list its expanded ids produce, the expand and trend
 * toggles per row, the computed footer rows and the error. Plus the parts of
 * its behaviour not already pinned by budgetGridLoad / budgetGridTrend: how
 * it resolves the active administration, what mounting does, and what the
 * two toggles leave behind.
 *
 * The template is rendered with Vue's server renderer, so no DOM is needed.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-001
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const administrationApi = vi.hoisted(() => ({ fetchAdministrationContext: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/api/administrationApi.js', () => administrationApi)
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag) => ({
		name,
		props: ['name', 'description', 'size'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, 'data-name': this.name },
				[this.description, this.$slots.default?.()],
			)
		},
	})
	return {
		NcAppContent: stub('NcAppContent', 'main'),
		NcEmptyContent: stub('NcEmptyContent', 'p'),
		NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	}
})

/**
 * A stub module for one vue-material-design-icons icon.
 *
 * @param {string} name The icon's component name.
 * @return {object} The module.
 */
function icon(name) {
	return {
		default: {
			name,
			props: ['size'],
			render() {
				return h('i', { class: name })
			},
		},
	}
}
vi.mock('vue-material-design-icons/ChartLine.vue', () => icon('ChartLine'))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => icon('ChevronDown'))
vi.mock('vue-material-design-icons/ChevronRight.vue', () => icon('ChevronRight'))
vi.mock('../../src/components/budget-charts/BudgetTrendChart.vue', () => ({
	default: {
		name: 'BudgetTrendChart',
		props: ['scope', 'id', 'name', 'administrationId', 'range'],
		render() {
			return h('div', {
				'data-testid': 'trend-chart',
				'data-scope': this.scope,
				'data-chart-id': this.id,
			})
		},
	},
}))
vi.mock('../../src/components/BudgetGridCell.vue', () => ({
	default: {
		name: 'BudgetGridCell',
		props: ['cell', 'isAccount'],
		render() {
			return h(
				'span',
				{ 'data-stub': 'BudgetGridCell', 'data-account': String(this.isAccount) },
				String(this.cell?.budget ?? '—'),
			)
		},
	},
}))
vi.mock('../../src/components/BudgetLinesEditor.vue', () => ({
	default: { name: 'BudgetLinesEditor', props: ['administrationId'], render() { return h('div', { 'data-stub': 'BudgetLinesEditor' }) } },
}))
vi.mock('../../src/components/MultiYearBudget.vue', () => ({
	default: { name: 'MultiYearBudget', props: ['administrationId'], render() { return h('div', { 'data-stub': 'MultiYearBudget' }) } },
}))

const { default: BudgetGrid } = await import('../../src/views/BudgetGrid.vue')

/**
 * A `t()` that returns the English source with its placeholders filled in.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} [vars] The placeholder values.
 * @return {string} The rendered string.
 */
function t(app, text, vars) {
	return String(text).replace(/\{(\w+)\}/g, (whole, key) =>
		vars && key in vars ? String(vars[key]) : whole,
	)
}

const COLUMNS = [
	{ key: '2026-01', label: 'Jan' },
	{ key: 'total', label: 'Cumulative', isTotal: true },
]

const ROWS = [
	{
		id: 'g1',
		kind: 'ledgerGroup',
		label: 'Personeel',
		hasChildren: true,
		cells: { '2026-01': { budget: 100 }, total: { budget: 100 } },
		children: [
			{
				id: 'a-4000',
				kind: 'account',
				label: 'Lonen',
				accountNumber: '4000',
				route: { name: 'Account', params: { id: 'a-4000' } },
				cells: { '2026-01': { budget: 60 }, total: { budget: 60 } },
			},
		],
	},
]

/**
 * Render the grid with its data overridden. `mounted()` is dropped so the
 * render does not load over the state under test.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data) {
	const app = createSSRApp({
		...BudgetGrid,
		mounted() {},
		data: () => ({ ...BudgetGrid.data(), ...data }),
	})
	app.config.globalProperties.t = t
	app.component('router-link', {
		props: ['to'],
		render() {
			// The component's own attributes (class, data-testid) land on this
			// anchor, so assertions read the real `budget-grid-account-link`.
			return h('a', null, this.$slots.default?.())
		},
	})
	return renderToString(app)
}

/**
 * The grid's methods and computed properties bound to a plain context seeded
 * from its data.
 *
 * @param {object} [state] The state to seed on top of the defaults.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { ...BudgetGrid.data(), t, ...state }
	for (const [name, fn] of Object.entries(BudgetGrid.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(BudgetGrid.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	http.get.mockReset()
	administrationApi.fetchAdministrationContext.mockReset()
})

describe('BudgetGrid rendering', () => {
	it('renders the period controls and only the loading icon while loading', async () => {
		const html = await render({})

		expect(html).toContain('data-testid="budget-grid-start-period"')
		expect(html).toContain('data-testid="budget-grid-end-period"')
		expect(html).toContain('data-testid="budget-grid-granularity"')
		expect(html).toContain('Loading budget grid')
		expect(html).not.toContain('data-testid="budget-grid-row"')
	})

	it('explains an administration without ledger groups', async () => {
		const html = await render({ loading: false })

		expect(html).toContain('No ledger groups')
		expect(html).toContain(
			'No LedgerGroup records exist yet for this administration. Create ledger groups to build a budget.',
		)
	})

	it('renders one row per visible row, with the total column flagged', async () => {
		const html = await render({ loading: false, columns: COLUMNS, rows: ROWS })

		expect(html.match(/data-testid="budget-grid-row"/g)).toHaveLength(1)
		expect(html.match(/data-testid="budget-grid-column-header"/g)).toHaveLength(2)
		expect(html).toContain('budget-grid__total-col')
		expect(html).toContain('data-testid="budget-grid-expand-toggle"')
		expect(html).toMatch(/aria-expanded="false"[^>]*>?/)
		expect(html).toContain('ChevronRight')
		expect(html).not.toContain('data-testid="budget-grid-account-link"')
	})

	it('reveals the children of an expanded row and links an account row out', async () => {
		const html = await render({
			loading: false,
			columns: COLUMNS,
			rows: ROWS,
			expandedIds: new Set(['g1']),
		})

		expect(html.match(/data-testid="budget-grid-row"/g)).toHaveLength(2)
		expect(html).toContain('ChevronDown')
		expect(html).toContain('data-testid="budget-grid-account-link"')
		expect(html).toContain('Lonen (4000)')
		expect(html).toContain('data-account="true"')
	})

	it('mounts the trend chart beneath the row whose chart is open', async () => {
		const html = await render({
			loading: false,
			columns: COLUMNS,
			rows: ROWS,
			administrationId: 'adm-1',
			openChartRowId: 'g1',
		})

		expect(html.match(/data-testid="budget-grid-trend-row"/g)).toHaveLength(1)
		expect(html).toMatch(/data-scope="ledgerGroup"[^>]*data-chart-id="g1"/)
		expect(html).toContain('colspan="3"')
	})

	it('renders the computed rows in the footer and never an expand toggle for them', async () => {
		const html = await render({
			loading: false,
			columns: COLUMNS,
			rows: ROWS,
			computedRows: [
				{ id: 'c1', kind: 'computed', label: 'Resultaat', cells: { '2026-01': { budget: 40 }, total: { budget: 40 } } },
			],
		})

		expect(html).toContain('Resultaat')
		expect(html).toContain('data-account="false"')
		expect(html.match(/data-testid="budget-grid-expand-toggle"/g)).toHaveLength(1)
	})

	it('shows the error as an alert and keeps the editors on the page', async () => {
		const html = await render({ loading: false, errorMessage: 'No accessible administration.' })

		expect(html).toMatch(/role="alert"[^>]*>\s*No accessible administration\./)
		expect(html).toContain('data-stub="BudgetLinesEditor"')
		expect(html).toContain('data-stub="MultiYearBudget"')
	})
})

describe('BudgetGrid administration', () => {
	it('takes the active administration from the shared context', async () => {
		administrationApi.fetchAdministrationContext.mockResolvedValue({
			activeAdministrationId: 'adm-1',
		})
		const vm = context()

		await vm.resolveAdministration()

		expect(vm.administrationId).toBe('adm-1')
	})

	it('resolves to none when the context has no active administration or fails', async () => {
		administrationApi.fetchAdministrationContext.mockResolvedValue({})
		const empty = context({ administrationId: 'stale' })
		await empty.resolveAdministration()

		administrationApi.fetchAdministrationContext.mockRejectedValue(new Error('no session'))
		const failed = context({ administrationId: 'stale' })
		await failed.resolveAdministration()

		expect([empty.administrationId, failed.administrationId]).toEqual([null, null])
	})

	it('resolves the administration before loading the grid on mount', async () => {
		const order = []
		const vm = context()
		vm.resolveAdministration = vi.fn(async () => {
			order.push('resolve')
		})
		vm.loadGrid = vi.fn(async () => {
			order.push('load')
		})

		await BudgetGrid.mounted.call(vm)

		expect(order).toEqual(['resolve', 'load'])
	})
})

describe('BudgetGrid row state', () => {
	it('flattens only the expanded branches, with their depth', () => {
		const collapsed = context({ rows: ROWS }).visibleRows
		const expanded = context({ rows: ROWS, expandedIds: new Set(['g1']) }).visibleRows

		expect(collapsed.map((row) => [row.id, row.depth])).toEqual([['g1', 0]])
		expect(expanded.map((row) => [row.id, row.depth])).toEqual([
			['g1', 0],
			['a-4000', 1],
		])
	})

	it('toggles a row open and shut without touching the network', () => {
		const vm = context({ rows: ROWS })

		vm.toggleRow('g1')
		const opened = vm.expandedIds
		vm.toggleRow('g1')

		expect([...opened]).toEqual(['g1'])
		expect([...vm.expandedIds]).toEqual([])
		expect(http.get).not.toHaveBeenCalled()
		// A new Set each time, so the computed row list re-evaluates.
		expect(vm.expandedIds).not.toBe(opened)
	})

	it('keeps one chart open at a time and closes it on a second press', () => {
		const vm = context()

		vm.toggleChart('g1')
		expect(vm.openChartRowId).toBe('g1')
		vm.toggleChart('a-4000')
		expect(vm.openChartRowId).toBe('a-4000')
		vm.toggleChart('a-4000')
		expect(vm.openChartRowId).toBeNull()
	})

	it('scopes a row chart by the row and the grid own period range', () => {
		const vm = context({
			administrationId: 'adm-1',
			startPeriod: '2026-03',
			endPeriod: '2026-06',
		})

		expect(vm.trendChartProps(ROWS[0])).toEqual({
			scope: 'ledgerGroup',
			id: 'g1',
			name: 'Personeel',
			administrationId: 'adm-1',
			range: { from: '2026-03', to: '2026-06' },
		})
		expect(vm.trendChartProps({ kind: 'computed' })).toBeNull()
	})
})
