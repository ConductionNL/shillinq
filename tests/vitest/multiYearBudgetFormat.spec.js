/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * MultiYearBudget: the first budget becoming the source only when none is
 * chosen, the euro formatting of a cent amount and the state labels. The
 * load/start round-trips live in multiYearBudget.spec.js; this file covers
 * applyView's source default and the two formatting helpers.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
 */

import { describe, expect, it, vi } from 'vitest'
import MultiYearBudget from '../../src/components/MultiYearBudget.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {} }))
vi.mock('../../src/utils/budgetEditingApi.js', () => ({
	loadMultiYear: vi.fn(),
	startNextYear: vi.fn(),
	refusal: (error, fallback) => error?.response?.data?.message || fallback,
}))

/**
 * A fake instance with data and bound methods.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function budget(state = {}) {
	const vm = { ...MultiYearBudget.data.call({}), administrationId: 'adm-1', ...state }
	for (const [name, method] of Object.entries(MultiYearBudget.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('MultiYearBudget applyView', () => {
	it('defaults the years, rows and budgets to empty lists', () => {
		const vm = budget()
		vm.applyView({})
		expect(vm.years).toEqual([])
		expect(vm.rows).toEqual([])
		expect(vm.budgets).toEqual([])
	})

	it('picks the first budget as the source when none is chosen', () => {
		const vm = budget({ sourceId: '' })
		vm.applyView({ budgets: [{ annualBudgetId: 'b-1' }, { annualBudgetId: 'b-2' }] })
		expect(vm.sourceId).toBe('b-1')
	})

	it('keeps the chosen source', () => {
		const vm = budget({ sourceId: 'b-2' })
		vm.applyView({ budgets: [{ annualBudgetId: 'b-1' }] })
		expect(vm.sourceId).toBe('b-2')
	})

	it('leaves the source empty when there are no budgets', () => {
		const vm = budget({ sourceId: '' })
		vm.applyView({ budgets: [] })
		expect(vm.sourceId).toBe('')
	})
})

describe('MultiYearBudget formatting', () => {
	it('formats a cent amount as euros with no decimals', () => {
		const vm = budget()
		const digits = (s) => s.replace(/\D/g, '')
		expect(digits(vm.formatEuro(206000))).toBe('2060')
		expect(vm.formatEuro(206000)).toMatch(/€/)
		expect(digits(vm.formatEuro(0))).toBe('0')
		expect(digits(vm.formatEuro(null))).toBe('0')
	})

	it('labels a known budget state and passes an unknown one through', () => {
		const vm = budget()
		expect(vm.stateLabel('draft')).toBe('draft')
		expect(vm.stateLabel('active')).toBe('active')
		expect(vm.stateLabel('closed')).toBe('closed')
		expect(vm.stateLabel('archived')).toBe('archived')
	})
})
