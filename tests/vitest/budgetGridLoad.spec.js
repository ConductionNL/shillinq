/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BudgetGrid.vue's loadGrid(): the request it
 * sends, the grid state a response leaves and the message of each failure.
 * Methods run bound to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetGrid from '../../src/views/BudgetGrid.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake BudgetGrid with its methods bound and a stale grid loaded.
 *
 * @return {object}
 */
function grid() {
	const self = {
		...BudgetGrid.data.call({ $route: { query: {} } }),
		administrationId: 'adm-1',
		startPeriod: '2026-01',
		endPeriod: '2026-12',
		granularity: 'month',
		columns: ['stale'],
		rows: ['stale'],
		computedRows: ['stale'],
		expandedIds: new Set(['x']),
		openChartRowId: 'r-1',
		t: (app, text) => `${app}:${text}`,
	}
	for (const [name, method] of Object.entries(BudgetGrid.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('BudgetGrid loadGrid', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('loads the grid for the administration and range', async () => {
		axios.get.mockResolvedValue({ data: { columns: ['c'], rows: ['r'], computedRows: 'not-a-list' } })
		const vm = grid()

		await vm.loadGrid()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/budget-grid', {
			params: { administrationId: 'adm-1', startPeriod: '2026-01', endPeriod: '2026-12', granularity: 'month' },
		})
		expect([vm.columns, vm.rows, vm.computedRows, [...vm.expandedIds], vm.openChartRowId, vm.loading, vm.errorMessage])
			.toEqual([['c'], ['r'], [], [], null, false, ''])
	})

	it('words each failure and clears the grid but keeps the open state', async () => {
		const messages = []
		for (const status of [404, 401, 500, undefined]) {
			axios.get.mockRejectedValueOnce(status ? { response: { status } } : new Error('down'))
			const vm = grid()
			await vm.loadGrid()
			messages.push(vm.errorMessage)
			expect([vm.columns, vm.rows, vm.computedRows, [...vm.expandedIds], vm.openChartRowId, vm.loading]).toEqual([[], [], [], ['x'], 'r-1', false])
		}

		expect(messages).toEqual([
			'shillinq:Administration not found.',
			'shillinq:Not logged in.',
			'shillinq:Failed to load the budget grid.',
			'shillinq:Failed to load the budget grid.',
		])
	})

	it('refuses without an administration and sends nothing', async () => {
		const vm = grid()
		vm.administrationId = ''

		await vm.loadGrid()

		expect(axios.get).not.toHaveBeenCalled()
		expect([vm.loading, vm.errorMessage, vm.columns]).toEqual([false, 'shillinq:No accessible administration.', ['stale']])
	})
})
