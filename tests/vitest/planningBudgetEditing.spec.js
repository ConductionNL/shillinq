/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * planning-budget-editing: the grid is typed into, a year is spread, the
 * multi-year page and the amendment and multi-year estimate pages reach
 * shillinq. The helpers, the manifest, the registry and appinfo/routes.php
 * are held to each other.
 *
 * @spec openspec/changes/planning-budget-editing/specs/budget-grid-view/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const exists = (file) => fs.existsSync(path.join(ROOT, file))

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
const axiosMock = { get: vi.fn(), post: vi.fn(), put: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

describe('Typing the budget', () => {
	it('reads euros as cents the way people type them', async () => {
		const { euroToCents, centsToInput } = await import('../../src/utils/budgetEditingApi.js')
		expect(euroToCents('206000')).toBe(20600000)
		expect(euroToCents('206.000')).toBe(20600000)
		expect(euroToCents('206.000,50')).toBe(20600050)
		expect(euroToCents('1234.5')).toBe(123450)
		expect(euroToCents('twee')).toBeNull()
		expect(euroToCents('')).toBeNull()
		expect(centsToInput(20600000)).toBe('206000')
		expect(centsToInput(123450)).toBe('1234.50')
	})

	it('moves between cells with the arrow keys and Enter', async () => {
		const { nextCell } = await import('../../src/utils/budgetEditingApi.js')
		expect(nextCell('Enter', { row: 0, col: 0 }, 3)).toEqual({ row: 1, col: 0 })
		expect(nextCell('ArrowUp', { row: 0, col: 0 }, 3)).toBeNull()
		expect(nextCell('ArrowRight', { row: 1, col: 11 }, 3)).toBeNull()
		expect(nextCell('ArrowRight', { row: 1, col: 3 }, 3, false, true)).toEqual({ row: 1, col: 4 })
		expect(nextCell('ArrowLeft', { row: 1, col: 3 }, 3, false, true)).toBeNull()
		expect(nextCell('a', { row: 1, col: 3 }, 3)).toBeNull()
	})

	it('spreads a year over twelve months, the remainder on the last', async () => {
		const { spreadAmounts } = await import('../../src/utils/budgetEditingApi.js')
		expect(spreadAmounts(247200000)).toEqual(new Array(12).fill(20600000))
		const odd = spreadAmounts(100)
		expect(odd.slice(0, 11)).toEqual(new Array(11).fill(8))
		expect(odd[11]).toBe(12)
		expect(odd.reduce((a, b) => a + b, 0)).toBe(100)
	})

	it('puts the editor on the budget grid page, read-only rows naming their source', () => {
		const grid = read('src/views/BudgetGrid.vue')
		expect(grid).toContain('<BudgetLinesEditor')
		const editor = read('src/components/BudgetLinesEditor.vue')
		expect(editor).toContain('expected: current.months[col]')
		expect(editor).toContain('data-testid="budget-entry-source"')
		expect(editor).toContain('@keydown="onKey($event, rowIndex, col)"')
	})
})

describe('Budget editing endpoints', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		axiosMock.put.mockReset()
	})

	it('call routes the app declares', async () => {
		const api = await import('../../src/utils/budgetEditingApi.js')
		axiosMock.get.mockResolvedValue({ data: {} })
		axiosMock.post.mockResolvedValue({ data: {} })
		axiosMock.put.mockResolvedValue({ data: {} })
		await api.loadBudgetLines('adm', 'b-1')
		await api.loadMultiYear('adm', 2026)
		await api.saveBudgetCell({ month: 1, amount: 1, expected: 0 })
		await api.spreadBudgetRow({ yearly: 1200 })
		await api.startNextYear('adm', 'b-1', 3)
		const urls = [...axiosMock.get.mock.calls, ...axiosMock.put.mock.calls, ...axiosMock.post.mock.calls].map((c) => c[0])
		expect(urls).toEqual([
			'/index.php/apps/shillinq/api/v1/budget-editing/lines',
			'/index.php/apps/shillinq/api/v1/budget-editing/multi-year',
			'/index.php/apps/shillinq/api/v1/budget-editing/cell',
			'/index.php/apps/shillinq/api/v1/budget-editing/spread',
			'/index.php/apps/shillinq/api/v1/budget-editing/next-year',
		])
		const routes = read('appinfo/routes.php')
		expect(routes).toContain("'budgetEditing#lines', 'url' => '/api/v1/budget-editing/lines', 'verb' => 'GET'")
		expect(routes).toContain("'budgetEditing#saveCell', 'url' => '/api/v1/budget-editing/cell', 'verb' => 'PUT'")
		expect(routes).toContain("'budgetEditing#spread', 'url' => '/api/v1/budget-editing/spread', 'verb' => 'POST'")
		expect(routes).toContain("'budgetEditing#multiYear', 'url' => '/api/v1/budget-editing/multi-year', 'verb' => 'GET'")
		expect(routes).toContain("'budgetEditing#startNextYear', 'url' => '/api/v1/budget-editing/next-year', 'verb' => 'POST'")
	})
})

describe('Pages', () => {
	const fragmentPath = 'src/manifest.d/planning-budget-editing.json'

	it('has the multi-year page, registered, under Budgets', () => {
		expect(exists(fragmentPath)).toBe(true)
		const fragment = JSON.parse(read(fragmentPath))
		const multiYear = fragment.pages.find((p) => p.id === 'MultiYearBudget')
		expect(multiYear.type).toBe('custom')
		expect(read('src/registry.js')).toContain("MultiYearBudget: { kind: 'page', component: MultiYearBudget }")
		const budgets = fragment.menu.find((m) => m.id === 'Budgets')
		expect(budgets.children.map((c) => c.route)).toContain('MultiYearBudget')
	})

	it('has amendment pages with Determine, files and history, and the multi-year estimate under Government', () => {
		const fragment = JSON.parse(read(fragmentPath))
		const detail = fragment.pages.find((p) => p.id === 'BegrotingswijzigingDetail').config
		expect(detail.schema).toBe('Begrotingswijziging')
		expect(detail.actions[0]).toMatchObject({ type: 'lifecycle-transition', transition: 'vaststellen' })
		expect(detail.sidebarProps.tabs.map((tab) => tab.id)).toEqual(['files', 'audit'])
		const government = fragment.menu.find((m) => m.id === 'Overheid')
		expect(government.children.map((c) => c.route)).toEqual(['Begrotingswijzigingen', 'Meerjarenramingen', 'MeerjarenBudgetten'])
		for (const id of ['Begrotingswijzigingen', 'Meerjarenramingen', 'MeerjarenramingDetail', 'MeerjarenBudgetten', 'MeerjarenBudgetDetail']) {
			expect(fragment.pages.map((p) => p.id)).toContain(id)
		}
	})
})
