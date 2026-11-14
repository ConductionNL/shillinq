/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The multi-year budget view: loading the years, rows and budgets, and
 * starting next year's budget from a chosen one.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import MultiYearBudget from '../../src/components/MultiYearBudget.vue'
import { loadMultiYear, startNextYear } from '../../src/utils/budgetEditingApi.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {} }))
vi.mock('../../src/utils/budgetEditingApi.js', () => ({
	loadMultiYear: vi.fn(),
	startNextYear: vi.fn(),
	refusal: (error, fallback) => `${error.message || 'none'}|${fallback}`,
}))

/**
 * A fake instance with the view's data and bound methods.
 *
 * @param {object} state Data overrides.
 * @return {object} The instance.
 */
function view(state = {}) {
	const vm = { ...MultiYearBudget.data.call({}), administrationId: 'adm-1', ...state }
	for (const [name, method] of Object.entries(MultiYearBudget.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('multi-year budget', () => {
	beforeEach(() => {
		loadMultiYear.mockReset()
		startNextYear.mockReset()
	})

	it('loads nothing without an administration', async () => {
		const vm = view({ administrationId: '' })
		await vm.load()
		expect(loadMultiYear).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
	})

	it('loads the view and picks the first budget as source', async () => {
		const vm = view({ loading: false })
		loadMultiYear.mockImplementation(async () => {
			expect(vm.loading).toBe(true)
			return { years: [2025, 2026], rows: [{ id: 'r' }], budgets: [{ annualBudgetId: 'b-1' }, { annualBudgetId: 'b-2' }] }
		})
		await vm.load()
		expect(loadMultiYear).toHaveBeenCalledWith('adm-1', new Date().getFullYear())
		expect(vm.years).toEqual([2025, 2026])
		expect(vm.rows).toEqual([{ id: 'r' }])
		expect(vm.sourceId).toBe('b-1')
		expect(vm.loading).toBe(false)
	})

	it('keeps a chosen source and defaults missing lists to empty', async () => {
		const vm = view({ sourceId: 'b-9', years: [1], rows: [1], budgets: [1] })
		loadMultiYear.mockResolvedValueOnce({ budgets: [{ annualBudgetId: 'b-1' }] })
		await vm.load()
		expect(vm.sourceId).toBe('b-9')
		expect(vm.years).toEqual([])
		expect(vm.rows).toEqual([])

		const empty = view()
		loadMultiYear.mockResolvedValueOnce({})
		await empty.load()
		expect(empty.sourceId).toBe('')
		expect(empty.budgets).toEqual([])
	})

	it('shows the refusal when loading fails and keeps the old view', async () => {
		const vm = view({ years: [2024] })
		loadMultiYear.mockRejectedValueOnce(new Error('denied'))
		await vm.load()
		expect(vm.message).toBe('denied|The budgets could not be loaded.')
		expect(vm.years).toEqual([2024])
		expect(vm.loading).toBe(false)
	})

	it('refuses a percentage that is not a number', async () => {
		const vm = view({ percentage: 'abc', message: '' })
		await vm.start()
		expect(startNextYear).not.toHaveBeenCalled()
		expect(vm.message).toBe('Enter a percentage, for example 3.')
		expect(vm.busy).toBe(false)
	})

	it('starts next year with a comma percentage, announces it and reloads', async () => {
		const vm = view({ percentage: '2,5', sourceId: 'b-1' })
		startNextYear.mockImplementation(async () => {
			expect(vm.busy).toBe(true)
			return { fiscalYear: 2027 }
		})
		loadMultiYear.mockResolvedValueOnce({ budgets: [] })
		await vm.start()
		expect(startNextYear).toHaveBeenCalledWith('adm-1', 'b-1', 2.5)
		expect(loadMultiYear).toHaveBeenCalledTimes(1)
		expect(vm.message).toBe('Draft budget 2027 started.')
		expect(vm.busy).toBe(false)
	})

	it('shows the refusal when starting fails and does not reload', async () => {
		const vm = view({ percentage: 3 })
		startNextYear.mockRejectedValueOnce(new Error('exists'))
		await vm.start()
		expect(vm.message).toBe('exists|The budget could not be started.')
		expect(loadMultiYear).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
	})
})
