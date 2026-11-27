/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLineCommitments: leaving the administration to the server (which
 * resolves the active one and its fallbacks, see
 * AdministrationResultsControllerTest), the drilldown toggle that reads the
 * underlying commitment lines with bare property filters, its response
 * shapes, its failure and its collapse on a second press. The row load lives in
 * budgetLineCommitmentsLoad.spec.js.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetLineCommitments from '../../src/views/BudgetLineCommitments.vue'
import { drilldownFilters } from '../../src/views/budgetLineCommitmentsHelpers.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake BudgetLineCommitments with its methods bound.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function view(state = {}) {
	const vm = { ...BudgetLineCommitments.data.call({}), t: (app, text) => text, ...state }
	for (const [name, method] of Object.entries(BudgetLineCommitments.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

const ROW = { key: 'k1', programme: '5.1', costCentre: 'FAC-2026' }

beforeEach(() => {
	axios.get.mockReset()
})

describe('administration', () => {
	it('sends no administration of its own, so the server picks the active one', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		await view().loadRows()
		await view().toggleDrilldown(ROW)

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).not.toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		for (const [, options] of axios.get.mock.calls) {
			expect(Object.keys(options?.params ?? {}).some((k) => k.includes('administrationId'))).toBe(false)
		}
	})

	it('shows the refusal when the server finds no administration', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 400 } })
		const vm = view()
		await vm.loadRows()
		expect([vm.errorMessage, vm.rows, vm.loading]).toEqual(['No active administration, so the budget lines cannot be shown.', [], false])
	})
})

describe('toggleDrilldown', () => {
	it('reads the commitment lines with bare property filters, the server paging them', async () => {
		const vm = view({ drilldownItems: [{ id: 'old' }] })
		axios.get.mockImplementationOnce(async () => {
			expect(vm.drilldownLoading).toBe(true)
			expect(vm.expandedKey).toBe('k1')
			return { data: { results: [{ id: 'c1' }] } }
		})
		await vm.toggleDrilldown(ROW)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/budget-line-commitments/lines',
			{ params: drilldownFilters(ROW) },
		)
		expect(Object.keys(axios.get.mock.calls[0][1].params).some((k) => k.startsWith('filters['))).toBe(false)
		expect(vm.drilldownItems).toEqual([{ id: 'c1' }])
		expect(vm.drilldownLoading).toBe(false)
	})

	it('reads only the results envelope and treats anything else as empty', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 'c2' }], total: 1 } })
		const vm = view()
		await vm.toggleDrilldown(ROW)
		expect(vm.drilldownItems).toEqual([{ id: 'c2' }])

		axios.get.mockResolvedValueOnce({ data: [{ id: 'c3' }] })
		const bare = view()
		await bare.toggleDrilldown(ROW)
		expect(bare.drilldownItems).toEqual([])

		axios.get.mockResolvedValueOnce({ data: { results: 'nope' } })
		const other = view()
		await other.toggleDrilldown(ROW)
		expect(other.drilldownItems).toEqual([])
	})

	it('empties the items when the read fails', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const vm = view()
		await vm.toggleDrilldown(ROW)
		expect(vm.drilldownItems).toEqual([])
		expect(vm.drilldownLoading).toBe(false)
		expect(vm.expandedKey).toBe('k1')
	})

	it('collapses the open row on a second press without a request', async () => {
		const vm = view({ expandedKey: 'k1', drilldownItems: [{ id: 'c1' }] })
		await vm.toggleDrilldown(ROW)
		expect(vm.expandedKey).toBeNull()
		expect(vm.drilldownItems).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})
})

describe('mount', () => {
	it('loads the rows on mount', () => {
		const vm = { loadRows: vi.fn() }
		BudgetLineCommitments.mounted.call(vm)
		expect(vm.loadRows).toHaveBeenCalledTimes(1)
	})
})
