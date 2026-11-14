/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetScenarioComparison driven through its controls: picking another
 * administration reloads its scenarios and ledger-group names, a new fiscal
 * year re-evaluates only when a scenario is picked, picking a scenario
 * evaluates it, and every state — loading, no scenarios, none picked,
 * failed, the comparison table — renders whichever way the components render
 * their slots. Only HTTP and the @nextcloud/vue components are replaced.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcAppContent: standIn('NcAppContent'),
		NcEmptyContent: standIn('NcEmptyContent'),
		NcLoadingIcon: standIn('NcLoadingIcon'),
	}
})

const { default: Comparison } = await import('../../src/views/BudgetScenarioComparison.vue')

const globals = { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => vars[k] ?? m) }
const BASE = '/index.php/apps/'

const SCENARIOS = [
	{ id: 'sc-1', name: 'Austerity', isDefault: true },
	{ id: 'sc-2', name: 'Growth' },
]
const CELLS = [
	{ ledgerGroupId: 'lg-z', month: '2026-01', base: 1000, scenario: 900, delta: -100 },
	{ ledgerGroupId: 'lg-a', month: '2026-02', base: 500, scenario: 700, delta: 200 },
]
const READY = {
	loading: false,
	administrationOptions: [{ value: 'adm-1', label: 'One' }, { value: 'adm-2', label: 'Two' }],
	administrationId: 'adm-1',
	fiscalYear: 2026,
	scenarios: SCENARIOS,
	scenarioId: 'sc-1',
	ledgerGroupNames: { 'lg-a': 'Apparatus', 'lg-z': 'Zoning' },
	cells: CELLS,
}

/**
 * Answer each GET by the path it asks for.
 *
 * @param {string} url The request URL.
 * @return {Promise<object>} The response.
 */
function answer(url) {
	if (url.endsWith('/BudgetScenario')) {
		return Promise.resolve({ data: { results: [{ id: 'sc-9', name: 'Other', isDefault: true }] } })
	}
	if (url.endsWith('/LedgerGroup')) {
		return Promise.resolve({ data: [{ id: 'lg-a', name: 'Apparatus 2' }] })
	}
	return Promise.resolve({ data: { data: { cells: [CELLS[1]] } } })
}

beforeEach(() => {
	http.get.mockReset().mockImplementation(answer)
})

/**
 * The control vnode carrying a test id.
 *
 * @param {object} view The render.
 * @param {string} id The test id suffix.
 * @return {object} The vnode.
 */
function control(view, id) {
	return view.byTestId('budget-scenario-comparison-' + id)
}

describe('BudgetScenarioComparison template events', () => {
	it('reloads the scenarios and ledger groups of a newly picked administration', async () => {
		const view = await renderWithStandIns(Comparison, { data: READY, globals })
		const select = control(view, 'administration')

		select.props['onUpdate:modelValue']('adm-2')
		await select.props.onChange()

		const urls = http.get.mock.calls.map(([url, options]) => [url.replace(BASE, ''), options?.params])
		expect(urls).toContainEqual(['openregister/api/objects/shillinq/BudgetScenario', { administrationId: 'adm-2', limit: 500 }])
		expect(urls).toContainEqual(['openregister/api/objects/shillinq/LedgerGroup', { administrationId: 'adm-2', limit: 500 }])
		expect(urls).toContainEqual(['shillinq/api/v1/budget-scenarios/sc-9/evaluate', { administration_id: 'adm-2', fiscal_year: 2026 }])
		expect(view.vm.scenarioId).toBe('sc-9')
		expect(view.vm.ledgerGroupNames).toEqual({ 'lg-a': 'Apparatus 2' })
		expect(view.vm.loading).toBe(false)
	})

	it('re-evaluates for a new fiscal year only when a scenario is picked', async () => {
		const none = await renderWithStandIns(Comparison, { data: { ...READY, scenarioId: '' }, globals })
		control(none, 'fiscal-year').props['onUpdate:modelValue'](2027)
		control(none, 'fiscal-year').props.onChange()
		expect(http.get).not.toHaveBeenCalled()

		const view = await renderWithStandIns(Comparison, { data: READY, globals })
		control(view, 'fiscal-year').props['onUpdate:modelValue'](2027)
		await control(view, 'fiscal-year').props.onChange()
		expect(http.get).toHaveBeenCalledWith(`${BASE}shillinq/api/v1/budget-scenarios/sc-1/evaluate`, {
			params: { administration_id: 'adm-1', fiscal_year: 2027 },
		})
	})

	it('evaluates the scenario picked in the scenario select', async () => {
		const view = await renderWithStandIns(Comparison, { data: READY, globals })
		const select = control(view, 'scenario')

		expect(view.find((vnode) => vnode.type === 'option').map(vnodeText)).toEqual([
			'One', 'Two', 'Select a scenario…', 'Austerity (default)', 'Growth',
		])
		select.props['onUpdate:modelValue']('sc-2')
		await select.props.onChange()

		expect(http.get).toHaveBeenCalledWith(`${BASE}shillinq/api/v1/budget-scenarios/sc-2/evaluate`, {
			params: { administration_id: 'adm-1', fiscal_year: 2026 },
		})
		expect(view.vm.cells).toEqual([CELLS[1]])
	})

	it('renders every state in the vnode branch', async () => {
		const loading = await renderWithStandIns(Comparison, { globals })
		expect(loading.standIns('NcLoadingIcon')).toHaveLength(1)

		const noScenarios = await renderWithStandIns(Comparison, { data: { ...READY, scenarios: [] }, globals })
		expect(noScenarios.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['No scenarios yet'])

		const unpicked = await renderWithStandIns(Comparison, { data: { ...READY, scenarioId: '' }, globals })
		expect(unpicked.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['Select a scenario to compare'])

		const failed = await renderWithStandIns(Comparison, { data: { ...READY, errorMessage: 'Nope.' }, globals })
		expect(failed.find((vnode) => vnode.props?.role === 'alert').map(vnodeText)).toEqual(['Nope.'])

		const table = await renderWithStandIns(Comparison, { data: READY, globals })
		const groups = table.find((vnode) => vnode.props?.scope === 'rowgroup').map(vnodeText)
		expect(groups).toEqual(['Apparatus', 'Zoning'])
		expect(table.find((vnode) => vnode.props?.scope === 'row').map(vnodeText)).toEqual([
			'Base', 'Scenario', 'Delta', 'Base', 'Scenario', 'Delta',
		])
	})

	it('renders every state when the components render their slots the server way', async () => {
		const loading = await renderWithStandIns(Comparison, { globals, slots: 'ssr' })
		expect(loading.standIns('NcLoadingIcon')).toEqual([{ size: 32, name: 'Loading comparison' }])

		const noScenarios = await renderWithStandIns(Comparison, { data: { ...READY, scenarios: [] }, globals, slots: 'ssr' })
		expect(noScenarios.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['No scenarios yet'])

		const unpicked = await renderWithStandIns(Comparison, { data: { ...READY, scenarioId: '' }, globals, slots: 'ssr' })
		expect(unpicked.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['Select a scenario to compare'])

		const failed = await renderWithStandIns(Comparison, { data: { ...READY, errorMessage: 'Nope.' }, globals, slots: 'ssr' })
		expect(failed.html).toMatch(/role="alert"[^>]*>\s*Nope\.\s*</)
		expect(failed.html).not.toContain('budget-scenario-comparison-table')

		const table = await renderWithStandIns(Comparison, { data: READY, globals, slots: 'ssr' })
		expect(table.html).toContain('data-testid="budget-scenario-comparison-table"')
		expect(table.html.indexOf('Apparatus')).toBeLessThan(table.html.indexOf('Zoning'))
		expect(table.html.match(/budget-scenario-comparison__row--delta/g)).toHaveLength(2)
		expect(table.html).toContain('Austerity (default)')
		expect(table.html).toContain('>Dec<')
	})
})
