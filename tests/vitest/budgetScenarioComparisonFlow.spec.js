/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetScenarioComparison: the administration context load and its
 * defaults, the scenario load that picks the default scenario and evaluates
 * it, the comparison request and its failure, the change handlers, the
 * grouping of flat cells into per-ledger-group month rows and the labels.
 * The ledger-group name load lives in budgetScenarioLedgerGroups.spec.js.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BudgetScenarioComparison from '../../src/views/BudgetScenarioComparison.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

const originalGet = axios.get

afterEach(() => {
	axios.get = originalGet
})

const T = (app, text, vars) => (vars ? text.replace('{name}', vars.name) : text)

/**
 * A fake instance: data, a translator and bound methods.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function view(state = {}) {
	const vm = { ...BudgetScenarioComparison.data.call({}), t: T, ...state }
	for (const [name, fn] of Object.entries(BudgetScenarioComparison.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('loadAdministrationContext', () => {
	it('maps the administrations, takes the active one and loads its scenarios and names', async () => {
		const vm = view()
		vm.loadScenarios = vi.fn()
		vm.loadLedgerGroupNames = vi.fn()
		axios.get = vi.fn(async () => ({
			data: {
				activeAdministrationId: 'a2',
				administrations: [
					{ administrationId: 'a1', name: 'One' },
					{ administrationId: 'a2', administrationCode: 'TWO' },
					{ administrationId: 'a3' },
				],
			},
		}))
		await vm.loadAdministrationContext()
		expect(axios.get).toHaveBeenCalledWith('/apps/shillinq/api/administrations/context')
		expect(vm.administrationOptions).toEqual([
			{ value: 'a1', label: 'One' },
			{ value: 'a2', label: 'TWO' },
			{ value: 'a3', label: 'a3' },
		])
		expect(vm.administrationId).toBe('a2')
		expect(vm.loadScenarios).toHaveBeenCalledTimes(1)
		expect(vm.loadLedgerGroupNames).toHaveBeenCalledTimes(1)
		expect(vm.loading).toBe(false)
	})

	it('falls back to the first administration when none is active', async () => {
		const vm = view()
		vm.loadScenarios = vi.fn()
		vm.loadLedgerGroupNames = vi.fn()
		axios.get = vi.fn(async () => ({ data: { administrations: [{ administrationId: 'a1' }] } }))
		await vm.loadAdministrationContext()
		expect(vm.administrationId).toBe('a1')
	})

	it('shows the failure and loads nothing further without an administration', async () => {
		const vm = view()
		vm.loadScenarios = vi.fn()
		vm.loadLedgerGroupNames = vi.fn()
		axios.get = vi.fn(async () => { throw new Error('down') })
		await vm.loadAdministrationContext()
		expect(vm.errorMessage).toBe('Failed to load administration context')
		expect(vm.loadScenarios).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
	})
})

describe('loadScenarios', () => {
	it('asks for the administration scenarios, picks the default one and evaluates it', async () => {
		const vm = view({ administrationId: 'adm-1' })
		vm.loadComparison = vi.fn()
		axios.get = vi.fn(async () => ({ data: { results: [{ id: 's1' }, { id: 's2', isDefault: true }] } }))
		await vm.loadScenarios()
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/shillinq/BudgetScenario',
			{ params: { administrationId: 'adm-1', limit: 500 } },
		)
		expect(vm.scenarios).toHaveLength(2)
		expect(vm.scenarioId).toBe('s2')
		expect(vm.loadComparison).toHaveBeenCalledTimes(1)
	})

	it('evaluates nothing without a default and accepts objects or a non-array', async () => {
		const vm = view({ administrationId: 'adm-1' })
		vm.loadComparison = vi.fn()
		axios.get = vi.fn(async () => ({ data: { objects: [{ id: 's1' }] } }))
		await vm.loadScenarios()
		expect(vm.scenarios).toEqual([{ id: 's1' }])
		expect(vm.scenarioId).toBe('')
		expect(vm.loadComparison).not.toHaveBeenCalled()

		axios.get = vi.fn(async () => ({ data: { results: 'nope' } }))
		await vm.loadScenarios()
		expect(vm.scenarios).toEqual([])
	})

	it('empties the scenarios and shows the failure', async () => {
		const vm = view({ administrationId: 'adm-1', scenarios: [{ id: 'old' }] })
		axios.get = vi.fn(async () => { throw new Error('x') })
		await vm.loadScenarios()
		expect(vm.scenarios).toEqual([])
		expect(vm.errorMessage).toBe('Failed to load scenarios')
	})
})

describe('loadComparison', () => {
	it('clears the cells without a scenario', async () => {
		const vm = view({ scenarioId: '', cells: [{ x: 1 }] })
		axios.get = vi.fn()
		await vm.loadComparison()
		expect(vm.cells).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('evaluates the scenario for the administration and year', async () => {
		const vm = view({ scenarioId: 's1', administrationId: 'adm-1', fiscalYear: 2027, errorMessage: 'old' })
		axios.get = vi.fn(async () => ({ data: { data: { cells: [{ ledgerGroupId: 'g' }] } } }))
		await vm.loadComparison()
		expect(axios.get).toHaveBeenCalledWith(
			'/apps/shillinq/api/v1/budget-scenarios/s1/evaluate',
			{ params: { administration_id: 'adm-1', fiscal_year: 2027 } },
		)
		expect(vm.cells).toEqual([{ ledgerGroupId: 'g' }])
		expect(vm.errorMessage).toBe('')
		expect(vm.loading).toBe(false)

		axios.get = vi.fn(async () => ({ data: {} }))
		await vm.loadComparison()
		expect(vm.cells).toEqual([])
	})

	it('empties the cells and shows the failure', async () => {
		const vm = view({ scenarioId: 's1', cells: [{ x: 1 }] })
		axios.get = vi.fn(async () => { throw new Error('x') })
		await vm.loadComparison()
		expect(vm.cells).toEqual([])
		expect(vm.errorMessage).toBe('Failed to evaluate this scenario')
		expect(vm.loading).toBe(false)
	})
})

describe('change handlers', () => {
	it('resets the selection and reloads on an administration change', async () => {
		const vm = view({ scenarioId: 's1', cells: [{ x: 1 }] })
		vm.loadScenarios = vi.fn()
		vm.loadLedgerGroupNames = vi.fn()
		await vm.onAdministrationChange()
		expect(vm.scenarioId).toBe('')
		expect(vm.cells).toEqual([])
		expect(vm.loadScenarios).toHaveBeenCalledTimes(1)
		expect(vm.loadLedgerGroupNames).toHaveBeenCalledTimes(1)
		expect(vm.loading).toBe(false)
	})

	it('re-evaluates on a fiscal-year change only with a scenario', () => {
		const vm = view({ scenarioId: '' })
		vm.loadComparison = vi.fn()
		vm.onFiscalYearChange()
		expect(vm.loadComparison).not.toHaveBeenCalled()
		vm.scenarioId = 's1'
		vm.onFiscalYearChange()
		expect(vm.loadComparison).toHaveBeenCalledTimes(1)
	})
})

describe('grouping and labels', () => {
	it('groups cells per ledger group into calendar-ordered months, sorted by name', () => {
		const ctx = {
			ledgerGroupNames: { g1: 'Zeta', g2: 'Alpha' },
			cells: [
				{ ledgerGroupId: 'g1', month: '2027-03', base: 1, scenario: 2, delta: 1 },
				{ ledgerGroupId: 'g2', month: '2027-12', base: 5, scenario: 4, delta: -1 },
				{ ledgerGroupId: 'g3', month: '2027-13', base: 9, scenario: 9, delta: 0 },
			],
		}
		const rows = BudgetScenarioComparison.computed.groupedRows.call(ctx)
		expect(rows.map((r) => r.name)).toEqual(['Alpha', 'g3', 'Zeta'])
		expect(rows[2].base[2]).toBe(1)
		expect(rows[2].scenario[2]).toBe(2)
		expect(rows[0].delta[11]).toBe(-1)
		expect(rows[1].base).toEqual(new Array(12).fill(0))
	})

	it('has twelve month labels', () => {
		const labels = BudgetScenarioComparison.computed.monthLabels.call({ t: T })
		expect(labels).toHaveLength(12)
		expect(labels[0]).toBe('Jan')
		expect(labels[11]).toBe('Dec')
	})

	it('flags the default scenario and falls back to the id', () => {
		const vm = view()
		expect(vm.scenarioLabel({ name: 'Plan', isDefault: true })).toBe('Plan (default)')
		expect(vm.scenarioLabel({ name: 'Plan' })).toBe('Plan')
		expect(vm.scenarioLabel({ id: 's9' })).toBe('s9')
	})

	it('labels the row kinds', () => {
		const vm = view()
		expect(vm.rowLabel('base')).toBe('Base')
		expect(vm.rowLabel('scenario')).toBe('Scenario')
		expect(vm.rowLabel('delta')).toBe('Delta')
	})

	it('formats cents as euros, zero for a non-number', () => {
		const vm = view()
		const digits = (s) => s.replace(/\D/g, '')
		expect(digits(vm.formatCents(123456))).toBe('123456')
		expect(vm.formatCents(123456)).toMatch(/€/)
		expect(digits(vm.formatCents('x'))).toBe('000')
	})
})
