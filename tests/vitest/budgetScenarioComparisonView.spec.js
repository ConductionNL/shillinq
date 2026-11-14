/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetScenarioComparison: the picker and the base/scenario/delta table it
 * renders in each state, how it resolves the administration context, which
 * scenario it pre-selects, and the request it sends to evaluate one. The
 * template is rendered with Vue's server renderer, so no DOM is needed; the
 * methods and computed properties run bound to a plain context seeded from
 * the real `data()`.
 *
 * `loadLedgerGroupNames()` is pinned separately by
 * tests/vitest/budgetScenarioLedgerGroups.spec.js.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
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

const { default: Comparison } = await import(
	'../../src/views/BudgetScenarioComparison.vue'
)

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

/**
 * Render the page with its data overridden. `mounted()` is dropped so the
 * render does not load over the state under test.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data) {
	const app = createSSRApp({
		...Comparison,
		mounted() {},
		data: () => ({ ...Comparison.data(), ...data }),
	})
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * The page's methods and computed properties bound to a plain context seeded
 * from its data.
 *
 * @param {object} [state] The state to seed on top of the defaults.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { ...Comparison.data(), t, ...state }
	for (const [name, fn] of Object.entries(Comparison.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(Comparison.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx), configurable: true })
	}
	return ctx
}

const CELLS = [
	{ ledgerGroupId: 'g2', month: '2026-01', base: 10000, scenario: 12000, delta: 2000 },
	{ ledgerGroupId: 'g1', month: '2026-12', base: 500, scenario: 400, delta: -100 },
]

beforeEach(() => {
	http.get.mockReset()
})

describe('BudgetScenarioComparison rendering', () => {
	it('shows only the loading icon while loading', async () => {
		const html = await render({})

		expect(html).toContain('Loading comparison')
		expect(html).not.toContain('data-testid="budget-scenario-comparison-table"')
		expect(html).not.toContain('data-stub="NcEmptyContent"')
	})

	it('explains an administration without scenarios', async () => {
		const html = await render({ loading: false })

		expect(html).toContain('No scenarios yet')
		expect(html).toContain(
			'Create a BudgetScenario and at least one BudgetScenarioModifier to see a comparison here.',
		)
	})

	it('asks for a scenario when none is selected', async () => {
		const html = await render({ loading: false, scenarios: [{ id: 's1', name: 'Krimp' }] })

		expect(html).toContain('Select a scenario to compare')
		expect(html).not.toContain('data-testid="budget-scenario-comparison-table"')
	})

	it('renders the pickers with the administration options and scenario labels', async () => {
		const html = await render({
			loading: false,
			administrationOptions: [
				{ value: 'adm-1', label: 'Gemeente A' },
				{ value: 'adm-2', label: 'adm-2' },
			],
			administrationId: 'adm-1',
			fiscalYear: 2027,
			scenarios: [
				{ id: 's1', name: 'Basis', isDefault: true },
				{ id: 's2', name: 'Krimp' },
			],
			scenarioId: 's1',
		})

		expect(html).toContain('Gemeente A')
		expect(html).toContain('Basis (default)')
		expect(html).toContain('Krimp')
		expect(html).toContain('value="2027"')
	})

	it('shows the error instead of the table', async () => {
		const html = await render({
			loading: false,
			scenarios: [{ id: 's1' }],
			scenarioId: 's1',
			errorMessage: 'Failed to evaluate this scenario',
		})

		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to evaluate this scenario/)
		expect(html).not.toContain('data-testid="budget-scenario-comparison-table"')
	})

	it('renders three rows per ledger group over twelve month columns', async () => {
		const html = await render({
			loading: false,
			scenarios: [{ id: 's1' }],
			scenarioId: 's1',
			ledgerGroupNames: { g1: 'Apparaat', g2: 'Personeel' },
			cells: CELLS,
		})

		expect(html).toContain('data-testid="budget-scenario-comparison-table"')
		expect(html.match(/Jan|Dec/g)).toBeTruthy()
		expect(html.match(/rowspan="3"/g)).toHaveLength(2)
		expect(html.match(/<th scope="row"[^>]*>\s*Base/g)).toHaveLength(2)
		expect(html.match(/budget-scenario-comparison__row--delta/g)).toHaveLength(2)
		// Alphabetical by name: Apparaat (g1) before Personeel (g2).
		expect(html.indexOf('Apparaat')).toBeLessThan(html.indexOf('Personeel'))
		expect(html.match(/budget-scenario-comparison__amount/g)).toHaveLength(72)
	})
})

describe('BudgetScenarioComparison derived state', () => {
	it('labels the twelve months in calendar order', () => {
		expect(context().monthLabels).toEqual([
			'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
			'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
		])
	})

	it('groups the flat cells per ledger group into twelve-long rows, sorted by name', () => {
		const rows = context({
			ledgerGroupNames: { g1: 'Apparaat', g2: 'Personeel' },
			cells: CELLS,
		}).groupedRows

		expect(rows.map((r) => r.ledgerGroupId)).toEqual(['g1', 'g2'])
		expect(rows[0].base).toHaveLength(12)
		expect(rows[0].base[11]).toBe(500)
		expect(rows[0].delta[11]).toBe(-100)
		expect(rows[0].base[0]).toBe(0)
		expect(rows[1].scenario[0]).toBe(12000)
	})

	it('falls back to the ledger group id as its own name', () => {
		const rows = context({ cells: [CELLS[0]] }).groupedRows

		expect(rows[0].name).toBe('g2')
	})

	it('ignores a cell whose month is outside the year', () => {
		const rows = context({
			cells: [{ ledgerGroupId: 'g1', month: '2026-13', base: 9, scenario: 9, delta: 0 }],
		}).groupedRows

		expect(rows).toHaveLength(1)
		expect(rows[0].base.every((amount) => amount === 0)).toBe(true)
	})

	it('words the row and scenario labels and formats cents as euros', () => {
		const vm = context()

		expect([vm.rowLabel('base'), vm.rowLabel('scenario'), vm.rowLabel('delta')])
			.toEqual(['Base', 'Scenario', 'Delta'])
		expect(vm.scenarioLabel({ name: 'Krimp' })).toBe('Krimp')
		expect(vm.scenarioLabel({ id: 's9' })).toBe('s9')
		expect(vm.scenarioLabel({ name: 'Basis', isDefault: true })).toBe('Basis (default)')
		expect(vm.formatCents(123456)).toContain('1,234.56')
		expect(vm.formatCents(null)).toContain('0.00')
	})
})

describe('BudgetScenarioComparison administration context', () => {
	it('lists the accessible administrations, takes the active one and loads its scenarios', async () => {
		http.get.mockImplementation((url) => {
			if (url.endsWith('/administrations/context')) {
				return Promise.resolve({
					data: {
						administrations: [
							{ administrationId: 'adm-1', name: 'Gemeente A' },
							{ administrationId: 'adm-2', administrationCode: 'B' },
							{ administrationId: 'adm-3' },
						],
						activeAdministrationId: 'adm-2',
					},
				})
			}
			return Promise.resolve({ data: { results: [] } })
		})
		const vm = context()

		await vm.loadAdministrationContext()

		expect(vm.administrationOptions).toEqual([
			{ value: 'adm-1', label: 'Gemeente A' },
			{ value: 'adm-2', label: 'B' },
			{ value: 'adm-3', label: 'adm-3' },
		])
		expect([vm.administrationId, vm.loading]).toEqual(['adm-2', false])
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/BudgetScenario',
			{ params: { administrationId: 'adm-2', limit: 500 } },
		)
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/LedgerGroup',
			{ params: { administrationId: 'adm-2', limit: 500 } },
		)
	})

	it('falls back to the first administration when none is active', async () => {
		http.get.mockResolvedValue({
			data: { administrations: [{ administrationId: 'adm-7' }] },
		})
		const vm = context()

		await vm.loadAdministrationContext()

		expect(vm.administrationId).toBe('adm-7')
	})

	it('words a failed context and loads nothing further', async () => {
		http.get.mockRejectedValue(new Error('no session'))
		const vm = context()

		await vm.loadAdministrationContext()

		expect([vm.errorMessage, vm.administrationId, vm.loading]).toEqual([
			'Failed to load administration context',
			'',
			false,
		])
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('resets the selection and re-reads both lists for a newly-picked administration', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const vm = context({
			administrationId: 'adm-2',
			scenarioId: 's1',
			cells: CELLS,
		})

		await vm.onAdministrationChange()

		expect([vm.scenarioId, vm.cells, vm.loading]).toEqual(['', [], false])
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('re-evaluates on a fiscal-year change only with a scenario selected', () => {
		const withScenario = context({ scenarioId: 's1' })
		withScenario.loadComparison = vi.fn()
		withScenario.onFiscalYearChange()

		const without = context()
		without.loadComparison = vi.fn()
		without.onFiscalYearChange()

		expect(withScenario.loadComparison).toHaveBeenCalledTimes(1)
		expect(without.loadComparison).not.toHaveBeenCalled()
	})
})

describe('BudgetScenarioComparison scenarios', () => {
	it('pre-selects the administration default scenario and compares it', async () => {
		http.get.mockImplementation((url) =>
			url.endsWith('/BudgetScenario')
				? Promise.resolve({
					data: { results: [{ id: 's1', name: 'Basis' }, { id: 's2', isDefault: true }] },
				})
				: Promise.resolve({ data: { data: { cells: CELLS } } }),
		)
		const vm = context({ administrationId: 'adm-1' })

		await vm.loadScenarios()

		expect(vm.scenarios).toHaveLength(2)
		expect(vm.scenarioId).toBe('s2')
		expect(vm.cells).toEqual(CELLS)
	})

	it('selects nothing when no scenario is the default, and compares nothing', async () => {
		http.get.mockResolvedValue({ data: { objects: [{ id: 's1' }] } })
		const vm = context({ administrationId: 'adm-1' })

		await vm.loadScenarios()

		expect([vm.scenarioId, vm.cells]).toEqual(['', []])
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('reads a bare list body and refuses a non-list one', async () => {
		http.get.mockResolvedValue({ data: [{ id: 's1' }] })
		const bare = context({ administrationId: 'adm-1' })
		await bare.loadScenarios()

		http.get.mockResolvedValue({ data: { results: 'none' } })
		const broken = context({ administrationId: 'adm-1' })
		await broken.loadScenarios()

		expect(bare.scenarios).toEqual([{ id: 's1' }])
		expect(broken.scenarios).toEqual([])
	})

	it('words a failed scenario list and keeps none', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const vm = context({ administrationId: 'adm-1', scenarios: [{ id: 'stale' }] })

		await vm.loadScenarios()

		expect([vm.scenarios, vm.errorMessage]).toEqual([
			[],
			'Failed to load scenarios',
		])
	})
})

describe('BudgetScenarioComparison evaluation', () => {
	it('evaluates the selected scenario for the selected fiscal year', async () => {
		http.get.mockResolvedValue({ data: { data: { cells: CELLS } } })
		const vm = context({
			administrationId: 'adm-1',
			scenarioId: 's1',
			fiscalYear: 2027,
			errorMessage: 'stale',
		})

		await vm.loadComparison()

		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/budget-scenarios/s1/evaluate',
			{ params: { administration_id: 'adm-1', fiscal_year: 2027 } },
		)
		expect([vm.cells, vm.loading, vm.errorMessage]).toEqual([CELLS, false, ''])
	})

	it('reads no cells from a response without them', async () => {
		http.get.mockResolvedValue({ data: {} })
		const vm = context({ scenarioId: 's1', cells: CELLS })

		await vm.loadComparison()

		expect(vm.cells).toEqual([])
	})

	it('sends nothing without a selected scenario', async () => {
		const vm = context({ cells: CELLS })

		await vm.loadComparison()

		expect(http.get).not.toHaveBeenCalled()
		expect(vm.cells).toEqual([])
	})

	it('words a failed evaluation and clears the table', async () => {
		http.get.mockRejectedValue(new Error('evaluator down'))
		const vm = context({ scenarioId: 's1', cells: CELLS })

		await vm.loadComparison()

		expect([vm.cells, vm.errorMessage, vm.loading]).toEqual([
			[],
			'Failed to evaluate this scenario',
			false,
		])
	})
})
