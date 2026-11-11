/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLinesEditor: saving a typed cell and spreading a yearly amount, with
 * the amount the cell started from, the refusals and the reload on failure.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetLinesEditor from '../../src/components/BudgetLinesEditor.vue'
import { loadBudgetLines, saveBudgetCell, spreadBudgetRow } from '../../src/utils/budgetEditingApi.js'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/utils/budgetEditingApi.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		loadBudgetLines: vi.fn(),
		loadMultiYear: vi.fn(),
		saveBudgetCell: vi.fn(),
		spreadBudgetRow: vi.fn(),
	}
})

/**
 * A fake editor with data, two rows and bound methods.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function editor(state = {}) {
	const vm = {
		...BudgetLinesEditor.data.call({}),
		administrationId: 'adm-1',
		annualBudgetId: 'ab-1',
		rows: [
			{ ledgerGroupId: 'g-1', months: [100, 200, 300], total: 600 },
			{ ledgerGroupId: 'g-2', months: [0, 0, 0], total: 0 },
		],
		...state,
	}
	for (const [name, method] of Object.entries(BudgetLinesEditor.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('BudgetLinesEditor save and spread', () => {
	beforeEach(() => {
		loadBudgetLines.mockReset()
		saveBudgetCell.mockReset()
		spreadBudgetRow.mockReset()
	})

	it('does nothing for a cell without a draft', async () => {
		const vm = editor({ drafts: { '1-0': '5' }, message: 'kept' })
		await vm.save(0, 1)
		expect(saveBudgetCell).not.toHaveBeenCalled()
		expect(vm.drafts).toEqual({ '1-0': '5' })
		expect(vm.message).toBe('kept')
	})

	it('refuses an amount that is not euros and keeps the draft', async () => {
		const vm = editor({ drafts: { '0-1': 'abc' } })
		await vm.save(0, 1)
		expect(saveBudgetCell).not.toHaveBeenCalled()
		expect(vm.message).toBe('Enter an amount in euros, for example 206000.')
		expect(vm.drafts).toEqual({ '0-1': 'abc' })
	})

	it('drops the draft without saving an unchanged amount', async () => {
		const vm = editor({ drafts: { '0-1': '2,00', '1-2': '9' }, message: 'kept' })
		await vm.save(0, 1)
		expect(saveBudgetCell).not.toHaveBeenCalled()
		expect(vm.drafts).toEqual({ '1-2': '9' })
		expect(vm.message).toBe('kept')
	})

	it('saves a changed amount with the one it started from and merges the row', async () => {
		const vm = editor({ drafts: { '0-2': '4' }, message: 'old' })
		saveBudgetCell.mockImplementationOnce(async () => {
			expect(vm.drafts).toEqual({})
			return { months: [100, 200, 400], total: 700 }
		})
		await vm.save(0, 2)
		expect(saveBudgetCell).toHaveBeenCalledWith({
			administrationId: 'adm-1',
			annualBudgetId: 'ab-1',
			ledgerGroupId: 'g-1',
			month: 3,
			amount: 400,
			expected: 300,
		})
		expect(Object.keys(saveBudgetCell.mock.calls[0][0])).toEqual(['administrationId', 'annualBudgetId', 'ledgerGroupId', 'month', 'amount', 'expected'])
		expect(vm.rows[0]).toEqual({ ledgerGroupId: 'g-1', months: [100, 200, 400], total: 700 })
		expect(vm.message).toBe('')
	})

	it('shows the refusal and reloads when a save fails', async () => {
		const vm = editor({ drafts: { '1-0': '1' } })
		saveBudgetCell.mockRejectedValueOnce({ response: { data: { message: 'Changed meanwhile' } } })
		loadBudgetLines.mockResolvedValueOnce({ rows: [{ ledgerGroupId: 'g-9', months: [0] }] })
		await vm.save(1, 0)
		expect(vm.message).toBe('Changed meanwhile')
		expect(loadBudgetLines).toHaveBeenCalledWith('adm-1', 'ab-1')
		expect(vm.rows).toEqual([{ ledgerGroupId: 'g-9', months: [0] }])

		vm.drafts = { '0-0': '7' }
		saveBudgetCell.mockRejectedValueOnce(new Error('x'))
		loadBudgetLines.mockResolvedValueOnce({ rows: [{ ledgerGroupId: 'g-1', months: [1] }] })
		await vm.save(0, 0)
		expect(vm.message).toBe('The amount could not be saved.')
	})

	it('refuses a yearly amount that is not euros', async () => {
		const vm = editor({ spreadRow: 0, yearly: 'x' })
		await vm.spread()
		expect(spreadBudgetRow).not.toHaveBeenCalled()
		expect(vm.message).toBe('Enter an amount in euros, for example 206000.')
		expect(vm.spreadRow).toBe(0)
	})

	it('spreads a yearly amount with the months it started from and closes the form', async () => {
		const vm = editor({ spreadRow: 1, yearly: '1.200', message: 'old' })
		spreadBudgetRow.mockResolvedValueOnce({ months: [400, 400, 400], total: 1200 })
		await vm.spread()
		expect(spreadBudgetRow).toHaveBeenCalledWith({
			administrationId: 'adm-1',
			annualBudgetId: 'ab-1',
			ledgerGroupId: 'g-2',
			yearly: 120000,
			expected: [0, 0, 0],
		})
		expect(Object.keys(spreadBudgetRow.mock.calls[0][0])).toEqual(['administrationId', 'annualBudgetId', 'ledgerGroupId', 'yearly', 'expected'])
		expect(vm.rows[1]).toEqual({ ledgerGroupId: 'g-2', months: [400, 400, 400], total: 1200 })
		expect(vm.spreadRow).toBeNull()
		expect(vm.message).toBe('')
	})

	it('shows the refusal, keeps the form open and reloads when a spread fails', async () => {
		const vm = editor({ spreadRow: 0, yearly: '10' })
		spreadBudgetRow.mockRejectedValueOnce({ response: { data: { message: 'Locked' } } })
		loadBudgetLines.mockResolvedValueOnce({ rows: [] })
		await vm.spread()
		expect(vm.message).toBe('Locked')
		expect(vm.spreadRow).toBe(0)
		expect(loadBudgetLines).toHaveBeenCalledTimes(1)
	})
})
