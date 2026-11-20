/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The close checklist on PeriodCloseDetail.vue: Resolve and Reopen per item
 * and Add item while the period is open or closing, none of them once it is
 * closed or locked for audit, and the requests each one sends. Only HTTP,
 * the router and the @nextcloud/vue components are replaced.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcSelect: standIn('NcSelect'),
		NcTextField: standIn('NcTextField'),
	}
})
vi.mock('../../src/modals/ReopenPeriodDialog.vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { default: standIn('ReopenPeriodDialog') }
})

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all))
globalThis.t = translate

// The page loads its period on create; the specs hand it the period instead.
const { default: Page } = await import('../../src/components/period-close/PeriodCloseDetail.vue')
const PeriodCloseDetail = { ...Page, created() {} }

const BANK = { id: 'bank', category: 'bank', description: 'bankmutaties volledig verwerkt', resolved: false, resolvedAt: null, resolvedBy: null }
const AP = { id: 'ap', category: 'ap', description: 'inkoopfacturen geboekt', resolved: true, resolvedAt: '2026-10-01T09:00:00Z', resolvedBy: 'bob' }

/**
 * The attributes of the stand-in carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} name The stand-in's component name.
 * @param {string} testId The data-testid.
 * @return {object|undefined} Its attributes.
 */
function standInById(view, name, testId) {
	return view.standIns(name).find((attrs) => attrs['data-testid'] === testId)
}

/**
 * The attributes of a checklist button.
 *
 * @param {object} view The rendered view.
 * @param {string} testId The button's data-testid without its prefix.
 * @return {object|undefined} Its attributes.
 */
function button(view, testId) {
	return standInById(view, 'NcButton', `period-close-detail-checklist-${testId}`)
}

/**
 * Whether the Add item form is rendered.
 *
 * @param {object} view The rendered view.
 * @return {boolean}
 */
function hasAddForm(view) {
	return view.html.includes('data-testid="period-close-detail-checklist-add"')
}

/**
 * Render the page for a period in a state.
 *
 * @param {string} state The period state.
 * @param {object} data Extra component data.
 * @return {Promise<object>} The view.
 */
function render(state, data = {}) {
	return renderWithStandIns(PeriodCloseDetail, {
		props: { id: 'p-2026-09' },
		globals: { t: translate },
		data: {
			loading: false,
			period: { id: 'p-2026-09', periodId: '2026-09', state, taskChecklistItems: [BANK, AP] },
			...data,
		},
	})
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('PeriodCloseDetail checklist actions', () => {
	it('offers Resolve on an open item and Reopen on a resolved one while closing', async () => {
		const view = await render('closing')

		expect(button(view, 'resolve-bank')['aria-label']).toBe('Resolve bankmutaties volledig verwerkt')
		expect(button(view, 'reopen-bank')).toBeUndefined()
		expect(button(view, 'reopen-ap')['aria-label']).toBe('Reopen inkoopfacturen geboekt')
		expect(button(view, 'resolve-ap')).toBeUndefined()
		expect(view.html).toContain('bob')
		expect(view.html).toContain('Actions')
		expect(hasAddForm(view)).toBe(true)
	})

	it('offers the actions on an open period too', async () => {
		const view = await render('open')

		expect(button(view, 'resolve-bank')).toBeDefined()
		expect(hasAddForm(view)).toBe(true)
	})

	it.each(['closed', 'audit-locked'])('offers no Resolve, Reopen or Add item on a %s period', async (state) => {
		const view = await render(state)

		expect(button(view, 'resolve-bank')).toBeUndefined()
		expect(button(view, 'reopen-ap')).toBeUndefined()
		expect(hasAddForm(view)).toBe(false)
		expect(view.html).not.toContain('Actions')
		expect(view.html).toContain('bankmutaties volledig verwerkt')
	})

	it('resolves an item and shows the period the server returns', async () => {
		const view = await render('closing')
		const resolved = { ...BANK, resolved: true, resolvedAt: '2026-10-02T10:00:00Z', resolvedBy: 'alice' }
		http.post.mockResolvedValue({ data: { data: { taskChecklistItems: [resolved, AP] } } })

		await button(view, 'resolve-bank').onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/period-close/p-2026-09/checklist/bank/resolve', {})
		expect(view.vm.period.taskChecklistItems[0]).toEqual(resolved)
		expect(view.vm.period.state).toBe('closing')
		expect(view.vm.checklistBusy).toBe(false)
		const after = await view.rerender()
		expect(after.html).toContain('alice')
		expect(button(after, 'reopen-bank')).toBeDefined()
	})

	it('reopens a resolved item', async () => {
		const view = await render('closing')
		http.post.mockResolvedValue({ data: { data: { taskChecklistItems: [BANK, { ...AP, resolved: false, resolvedAt: null, resolvedBy: null }] } } })

		await button(view, 'reopen-ap').onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/period-close/p-2026-09/checklist/ap/reopen', {})
		expect(view.vm.period.taskChecklistItems[1].resolved).toBe(false)
	})

	it('adds the typed item in the chosen category and clears the form', async () => {
		const view = await render('closing', { newItemDescription: '  loonjournaal geboekt ', newItemCategory: { value: 'other', label: 'Other' } })
		http.post.mockResolvedValue({ data: { data: { taskChecklistItems: [BANK, AP, { id: 'item-3', category: 'other', description: 'loonjournaal geboekt', resolved: false }] } } })

		await view.vm.addItem()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/period-close/p-2026-09/checklist', { category: 'other', description: 'loonjournaal geboekt' })
		expect(view.vm.period.taskChecklistItems).toHaveLength(3)
		expect(view.vm.newItemDescription).toBe('')
		expect(view.vm.newItemCategory).toBeNull()
	})

	it('files an item without a chosen category under other, and sends nothing for an empty description', async () => {
		const view = await render('closing', { newItemDescription: '   ' })
		await view.vm.addItem()
		expect(http.post).not.toHaveBeenCalled()

		view.vm.newItemDescription = 'Memoriaal'
		http.post.mockResolvedValue({ data: { data: { taskChecklistItems: [] } } })
		await view.vm.addItem()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/period-close/p-2026-09/checklist', { category: 'other', description: 'Memoriaal' })
	})

	it('lists every category in the category picker', async () => {
		const view = await render('closing')

		const picker = view.standIns('NcSelect').find((attrs) => attrs['data-testid'] === 'period-close-detail-checklist-add-category')
		expect(picker.inputLabel ?? picker['input-label']).toBe('Category')
		expect(picker.options.map((option) => option.value)).toEqual(['ap', 'ar', 'bank', 'expense-claims', 'vat', 'accruals', 'other'])
		expect(picker.options.map((option) => option.label)).toEqual(['Accounts payable', 'Accounts receivable', 'Bank reconciliation', 'Expense claims', 'VAT', 'Accruals', 'Other'])
	})

	it('keeps the form and shows a refusal when the change is not stored', async () => {
		const view = await render('closing', { newItemDescription: 'BTW' })
		http.post.mockRejectedValue({ response: { status: 403 } })

		await view.vm.addItem()

		expect(view.vm.checklistError).toBe('You do not have permission to perform this action.')
		expect(view.vm.newItemDescription).toBe('BTW')
		expect(view.vm.checklistBusy).toBe(false)
		const after = await view.rerender()
		expect(after.html).toMatch(/data-testid="period-close-detail-checklist-error"[^>]*>\s*You do not have permission to perform this action.\s*</)

		http.post.mockRejectedValue(new Error('network'))
		await view.vm.resolveItem(BANK)
		expect(view.vm.checklistError).toBe('The checklist could not be changed.')
	})
})
