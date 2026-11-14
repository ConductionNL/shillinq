/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetGrid's template wiring: the handlers its compiled render function
 * binds to the period controls, the granularity select, the expand toggle and
 * the trend toggle.
 * The view is server-rendered with its page shell calling the default slot as
 * a plain function, so the compiled slot takes its client branch and returns
 * vnodes with their listeners; those are fired against the live instance and
 * the view rendered again, so the test checks which method each control
 * reaches and what it changes, without a DOM.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-001
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString, ssrRenderSlot, ssrRenderVNode } from 'vue/server-renderer'

let slotVnodes = []
let slotMode = 'vnode'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	// The page shell calls its default slot as a plain function, so the
	// compiled slot takes its client branch and hands back the vnodes,
	// listeners included; in 'ssr' mode it renders the slot the server way.
	NcAppContent: {
		ssrRender(ctx, push, parent) {
			if (slotMode === 'ssr') {
				push('<main>')
				ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
				push('</main>')
				return
			}
			const vnodes = [].concat(ctx.$slots.default?.() ?? [])
			slotVnodes = vnodes
			ssrRenderVNode(push, h('main', vnodes), parent)
		},
	},
	NcEmptyContent: { render() { return h('div') } },
	NcLoadingIcon: { render() { return h('span') } },
}))
const stub = (name) => ({ default: { name, render() { return h('div') } } })
vi.mock('vue-material-design-icons/ChartLine.vue', () => stub('ChartLine'))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => stub('ChevronDown'))
vi.mock('vue-material-design-icons/ChevronRight.vue', () => stub('ChevronRight'))
vi.mock('../../src/components/budget-charts/BudgetTrendChart.vue', () => stub('BudgetTrendChart'))
vi.mock('../../src/components/BudgetGridCell.vue', () => stub('BudgetGridCell'))
vi.mock('../../src/components/BudgetLinesEditor.vue', () => stub('BudgetLinesEditor'))
vi.mock('../../src/components/MultiYearBudget.vue', () => stub('MultiYearBudget'))

const { default: BudgetGrid } = await import('../../src/views/BudgetGrid.vue')

const columns = [
	{ key: '2026-01', label: 'Jan 2026', isTotal: false },
	{ key: 'total', label: 'Total', isTotal: true },
]
const cells = { '2026-01': { budget: 10 }, total: { budget: 10 } }
const rows = [
	{
		id: 'lg-1',
		kind: 'ledgerGroup',
		label: 'Revenue',
		depth: 0,
		hasChildren: true,
		cells,
		children: [
			{ id: 'acc-1', kind: 'account', label: 'Sales', accountNumber: '8000', depth: 1, route: { name: 'GL' }, cells, children: [] },
		],
	},
	{ id: 'lg-2', kind: 'ledgerGroup', label: 'Other', depth: 0, hasChildren: false, cells, children: [] },
]

let live = null

/**
 * A keyup event as Vue's key modifiers read it.
 */
class KeyboardEventLike {
	/**
	 * @param {string} key The key.
	 */
	constructor(key) {
		this.key = key
		this.type = 'keyup'
	}
}
const loadGrid = vi.fn()

/**
 * Server-render the view from the given state and hand back the live
 * instance and every vnode its page slot returned.
 *
 * @param {object} state Data on top of the defaults.
 * @return {Promise<{vm: object, vnodes: Array<object>}>} The instance and vnodes.
 */
async function mountView(state = {}) {
	const View = {
		extends: BudgetGrid,
		ssrRender: BudgetGrid.ssrRender,
		data: () => ({ ...state }),
		created() {
			live = this
		},
		methods: { loadGrid },
	}
	const app = createSSRApp(View)
	app.config.globalProperties.t = (appId, text, params = {}) => text.replace(/\{(\w+)\}/g, (m, key) => params[key] ?? m)
	app.component('router-link', { render() { return h('a', this.$slots.default?.()) } })
	const html = await renderToString(app)
	return { vm: live, vnodes: flatten(slotVnodes), html }
}

/**
 * Render again from the instance's current state.
 *
 * @param {object} vm The instance.
 * @return {Promise<Array<object>>} The vnodes.
 */
async function rerender(vm) {
	const keys = Object.keys(BudgetGrid.data())
	const state = Object.fromEntries(keys.map((key) => [key, vm[key]]))
	return (await mountView(state)).vnodes
}

/**
 * Every vnode in the trees, depth first.
 *
 * @param {Array<object>} trees The trees.
 * @return {Array<object>} The vnodes.
 */
function flatten(trees) {
	const out = []
	const walk = (vnode) => {
		if (Array.isArray(vnode)) {
			vnode.forEach(walk)
			return
		}
		if (!vnode || typeof vnode !== 'object') {
			return
		}
		out.push(vnode)
		if (Array.isArray(vnode.children)) {
			vnode.children.forEach(walk)
		}
	}
	walk(trees)
	return out
}

/**
 * The vnodes carrying a data-testid.
 *
 * @param {Array<object>} vnodes The vnodes.
 * @param {string} testid The data-testid.
 * @return {Array<object>} The matches.
 */
function byTestId(vnodes, testid) {
	return vnodes.filter((vnode) => vnode.props?.['data-testid'] === testid)
}

describe('BudgetGrid template handlers', () => {
	beforeEach(() => {
		loadGrid.mockReset()
		slotMode = 'vnode'
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('writes the period inputs and the granularity through v-model and reloads on change', async () => {
		const { vm, vnodes } = await mountView({ loading: false, columns, rows })
		const start = byTestId(vnodes, 'budget-grid-start-period')[0]
		const end = byTestId(vnodes, 'budget-grid-end-period')[0]
		const granularity = byTestId(vnodes, 'budget-grid-granularity')[0]

		start.props['onUpdate:modelValue']('2026-02')
		end.props['onUpdate:modelValue']('2026-09')
		granularity.props['onUpdate:modelValue']('quarter')
		start.props.onChange()
		end.props.onChange()
		granularity.props.onChange()

		expect(vm.startPeriod).toBe('2026-02')
		expect(vm.endPeriod).toBe('2026-09')
		expect(vm.granularity).toBe('quarter')
		expect(loadGrid).toHaveBeenCalledTimes(3)
	})

	it('shows the loading icon while loading and the empty state without rows', async () => {
		const loading = await mountView({ loading: true })
		expect(byTestId(loading.vnodes, 'budget-grid-row')).toHaveLength(0)
		expect(loading.vnodes.some((vnode) => vnode.props?.name === 'Loading budget grid')).toBe(true)

		const empty = await mountView({ loading: false, rows: [] })
		expect(empty.vnodes.some((vnode) => vnode.props?.name === 'No ledger groups')).toBe(true)
	})

	it('expands a ledger group from its toggle, and only groups with children get one', async () => {
		const { vm, vnodes } = await mountView({ loading: false, columns, rows })
		const toggles = byTestId(vnodes, 'budget-grid-expand-toggle')
		expect(toggles).toHaveLength(1)
		expect(toggles[0].props['aria-expanded']).toBe(false)
		expect(byTestId(vnodes, 'budget-grid-account-link')).toHaveLength(0)

		toggles[0].props.onClick()
		expect(vm.expandedIds.has('lg-1')).toBe(true)

		const after = await rerender(vm)
		expect(byTestId(after, 'budget-grid-expand-toggle')[0].props['aria-expanded']).toBe(true)
		expect(byTestId(after, 'budget-grid-row')).toHaveLength(3)
		expect(byTestId(after, 'budget-grid-account-link')).toHaveLength(1)
	})

	it('toggles a group from the keyboard with enter and space', async () => {
		const { vm, vnodes } = await mountView({ loading: false, columns, rows })
		const keyup = [].concat(byTestId(vnodes, 'budget-grid-expand-toggle')[0].props.onKeyup)

		keyup.forEach((handler) => handler(new KeyboardEventLike('Enter')))
		expect(vm.expandedIds.has('lg-1')).toBe(true)
		keyup.forEach((handler) => handler(new KeyboardEventLike(' ')))
		expect(vm.expandedIds.has('lg-1')).toBe(false)
	})

	it('opens and closes a row trend chart from its toggle', async () => {
		const { vm, vnodes } = await mountView({ loading: false, columns, rows })
		const trend = byTestId(vnodes, 'budget-grid-view-trend-toggle')
		expect(trend).toHaveLength(2)
		expect(trend[1].props['aria-label']).toBe('View trend of Other')

		trend[1].props.onClick()
		expect(vm.openChartRowId).toBe('lg-2')
		expect(byTestId(await rerender(vm), 'budget-grid-trend-row')).toHaveLength(1)

		trend[1].props.onClick()
		expect(vm.openChartRowId).toBe(null)

		const keyup = [].concat(trend[0].props.onKeyup)
		keyup.forEach((handler) => handler(new KeyboardEventLike('Enter')))
		expect(vm.openChartRowId).toBe('lg-1')
		keyup.forEach((handler) => handler(new KeyboardEventLike(' ')))
		expect(vm.openChartRowId).toBe(null)
	})

	it('renders the computed footer rows and the error alert', async () => {
		const { vnodes } = await mountView({
			loading: false,
			columns,
			rows,
			computedRows: [{ code: 'result', label: 'Result', cells }],
			errorMessage: 'Failed to load the budget grid.',
		})

		const footer = vnodes.filter((vnode) => vnode.props?.['data-row-kind'] === 'computed')
		expect(footer).toHaveLength(1)
		const alert = vnodes.find((vnode) => vnode.props?.role === 'alert')
		expect(alert.children).toContain('Failed to load the budget grid.')
	})

	it('renders the loaded table, the loading state and the empty state as markup', async () => {
		slotMode = 'ssr'
		const loaded = await mountView({
			loading: false,
			columns,
			rows,
			expandedIds: new Set(['lg-1']),
			openChartRowId: 'acc-1',
			computedRows: [{ code: 'result', label: 'Result', cells }],
			errorMessage: 'Not logged in.',
		})
		expect(loaded.html.match(/data-testid="budget-grid-column-header"/g)).toHaveLength(2)
		expect(loaded.html).toMatch(/class="budget-grid__total-col"/)
		expect(loaded.html.match(/data-testid="budget-grid-row"/g)).toHaveLength(4)
		expect(loaded.html).toContain('data-row-kind="computed"')
		expect(loaded.html).toContain('aria-expanded="true"')
		expect(loaded.html).toContain('Sales (8000)')
		expect(loaded.html).toContain('padding-inline-start:28px')
		expect(loaded.html).toContain('data-testid="budget-grid-trend-row"')
		expect(loaded.html).toContain('colspan="3"')
		expect(loaded.html).toMatch(/<span[^>]*>Other<\/span>/)
		expect(loaded.html).toContain('role="alert"')
		expect(loaded.html).toContain('Not logged in.')

		const loading = await mountView({ loading: true })
		expect(loading.html).not.toContain('budget-grid__table')
		expect(loading.html).not.toContain('role="alert"')

		const empty = await mountView({ loading: false, rows: [] })
		expect(empty.html).not.toContain('budget-grid__table')
		expect(empty.html).not.toContain('data-testid="budget-grid-row"')
	})
})
