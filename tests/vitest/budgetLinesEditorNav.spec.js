/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLinesEditor: loading the administration's budgets and the chosen
 * budget's rows, the keyboard navigation between cells, opening the spread
 * form and the small label/key helpers. The save/spread round-trips live in
 * budgetLinesSave.spec.js; this file covers the load, navigation and
 * presentation methods those tests do not touch.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetLinesEditor from '../../src/components/BudgetLinesEditor.vue'
import { loadBudgetLines, loadMultiYear } from '../../src/utils/budgetEditingApi.js'

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
 * A fake editor with data and bound methods.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function editor(state = {}) {
	const vm = {
		...BudgetLinesEditor.data.call({}),
		administrationId: 'adm-1',
		annualBudgetId: 'ab-1',
		...state,
	}
	for (const [name, method] of Object.entries(BudgetLinesEditor.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

describe('BudgetLinesEditor loading', () => {
	beforeEach(() => {
		loadMultiYear.mockReset()
		loadBudgetLines.mockReset()
	})

	it('loads nothing without an administration', async () => {
		const vm = editor({ administrationId: null, budgets: ['kept'] })
		await vm.loadBudgets()
		expect(loadMultiYear).not.toHaveBeenCalled()
		expect(vm.budgets).toEqual(['kept'])
	})

	it('takes the budgets for the current year, defaulting a missing list', async () => {
		const vm = editor()
		loadMultiYear.mockResolvedValueOnce({ budgets: [{ annualBudgetId: 'b-1' }] })
		await vm.loadBudgets()
		expect(loadMultiYear).toHaveBeenCalledWith('adm-1', new Date().getFullYear())
		expect(vm.budgets).toEqual([{ annualBudgetId: 'b-1' }])

		loadMultiYear.mockResolvedValueOnce({})
		await vm.loadBudgets()
		expect(vm.budgets).toEqual([])
	})

	it('shows the refusal when the budgets cannot be loaded', async () => {
		const vm = editor()
		loadMultiYear.mockRejectedValueOnce({ response: { data: { message: 'denied' } } })
		await vm.loadBudgets()
		expect(vm.message).toBe('denied')

		loadMultiYear.mockRejectedValueOnce(new Error('x'))
		await vm.loadBudgets()
		expect(vm.message).toBe('The budgets could not be loaded.')
	})

	it('clears the drafts and rows and reads nothing without a chosen budget', async () => {
		const vm = editor({ annualBudgetId: '', drafts: { '0-0': '1' }, rows: [{ x: 1 }] })
		await vm.load()
		expect(loadBudgetLines).not.toHaveBeenCalled()
		expect(vm.drafts).toEqual({})
		expect(vm.rows).toEqual([])
	})

	it('reads the chosen budget rows, defaulting a missing list', async () => {
		const vm = editor({ drafts: { '0-0': '1' } })
		loadBudgetLines.mockResolvedValueOnce({ rows: [{ ledgerGroupId: 'g' }] })
		await vm.load()
		expect(loadBudgetLines).toHaveBeenCalledWith('adm-1', 'ab-1')
		expect(vm.drafts).toEqual({})
		expect(vm.rows).toEqual([{ ledgerGroupId: 'g' }])

		loadBudgetLines.mockResolvedValueOnce({})
		await vm.load()
		expect(vm.rows).toEqual([])
	})

	it('shows the refusal when the budget rows cannot be loaded', async () => {
		const vm = editor()
		loadBudgetLines.mockRejectedValueOnce({ response: { data: { message: 'gone' } } })
		await vm.load()
		expect(vm.message).toBe('gone')

		loadBudgetLines.mockRejectedValueOnce(new Error('x'))
		await vm.load()
		expect(vm.message).toBe('The budget could not be loaded.')
	})
})

describe('BudgetLinesEditor keyboard navigation', () => {
	/**
	 * A key event over a text input with a caret position.
	 *
	 * @param {string} key The pressed key.
	 * @param {object} caret selectionStart/End/value overrides.
	 * @return {object} The fake event.
	 */
	function keyEvent(key, caret = {}) {
		return {
			key,
			preventDefault: vi.fn(),
			target: { selectionStart: 0, selectionEnd: 0, value: '', ...caret },
		}
	}

	it('saves on Enter and moves down to the next row cell', async () => {
		const focus = vi.fn()
		const vm = editor({
			rows: [{ months: [0, 0] }, { months: [0, 0] }],
			drafts: { '0-0': '5' },
		})
		vm.save = vi.fn()
		vm.$refs = { 'cell-1-0': { focus } }
		const event = keyEvent('Enter')
		await vm.onKey(event, 0, 0)
		expect(vm.save).toHaveBeenCalledWith(0, 0)
		expect(event.preventDefault).toHaveBeenCalled()
		expect(focus).toHaveBeenCalled()
	})

	it('moves right out of a full caret and focuses the first ref of an array', async () => {
		const focus = vi.fn()
		const vm = editor({ rows: [{ months: [0, 0, 0] }] })
		vm.$refs = { 'cell-0-1': [{ focus }] }
		const event = keyEvent('ArrowRight', { selectionStart: 1, selectionEnd: 1, value: '9' })
		await vm.onKey(event, 0, 0)
		expect(event.preventDefault).toHaveBeenCalled()
		expect(focus).toHaveBeenCalled()
	})

	it('does nothing and does not prevent default for a key with no target cell', async () => {
		const vm = editor({ rows: [{ months: [0] }] })
		vm.save = vi.fn()
		const event = keyEvent('a')
		await vm.onKey(event, 0, 0)
		expect(vm.save).not.toHaveBeenCalled()
		expect(event.preventDefault).not.toHaveBeenCalled()
	})
})

describe('BudgetLinesEditor helpers', () => {
	it('keys and refs a cell by its row and column', () => {
		const vm = editor()
		expect(vm.cellKey(2, 3)).toBe('2-3')
		expect(vm.cellRef(2, 3)).toBe('cell-2-3')
	})

	it('labels a known line source and passes an unknown one through', () => {
		const vm = editor()
		expect(vm.sourceLabel('contract')).toBe('from contracts')
		expect(vm.sourceLabel('recurring')).toBe('from recurring items')
		expect(vm.sourceLabel('projected')).toBe('projected')
		expect(vm.sourceLabel('scenario')).toBe('from a scenario')
		expect(vm.sourceLabel('other')).toBe('other')
	})

	it('has twelve short month labels', () => {
		const vm = editor()
		const labels = BudgetLinesEditor.computed.monthLabels.call(vm)
		expect(labels).toHaveLength(12)
		expect(labels.every((label) => typeof label === 'string' && label.length > 0)).toBe(true)
	})

	it('opens the spread form with the row total as euros', () => {
		const vm = editor({ rows: [{ total: 120000 }, { total: 0 }] })
		vm.openSpread(0)
		expect(vm.spreadRow).toBe(0)
		expect(vm.yearly).toBe('1200')
	})
})
