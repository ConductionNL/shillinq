/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLineCommitments: resolving the active administration (and its
 * fallbacks), the drilldown toggle that reads the underlying commitment
 * lines with bare property filters, its response shapes, its failure and its
 * collapse on a second press. The row load lives in
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

describe('resolveAdministrationId', () => {
	it('prefers the active administration, else the first one', async () => {
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 'a2', administrations: [{ administrationId: 'a1' }] } })
		expect(await view().resolveAdministrationId()).toBe('a2')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')

		axios.get.mockResolvedValueOnce({ data: { administrations: [{ administrationId: 'a1' }] } })
		expect(await view().resolveAdministrationId()).toBe('a1')
	})

	it('answers empty when there is none or the context cannot be read', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await view().resolveAdministrationId()).toBe('')

		axios.get.mockRejectedValueOnce(new Error('x'))
		expect(await view().resolveAdministrationId()).toBe('')
	})
})

describe('toggleDrilldown', () => {
	it('reads the commitment lines with bare property filters and a limit', async () => {
		const vm = view({ drilldownItems: [{ id: 'old' }] })
		axios.get.mockImplementationOnce(async () => {
			expect(vm.drilldownLoading).toBe(true)
			expect(vm.expandedKey).toBe('k1')
			return { data: { results: [{ id: 'c1' }] } }
		})
		await vm.toggleDrilldown(ROW)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/CommitmentLine',
			{ params: { ...drilldownFilters(ROW), _limit: 100 } },
		)
		expect(Object.keys(axios.get.mock.calls[0][1].params).some((k) => k.startsWith('filters['))).toBe(false)
		expect(vm.drilldownItems).toEqual([{ id: 'c1' }])
		expect(vm.drilldownLoading).toBe(false)
	})

	it('accepts a bare array and treats anything else as empty', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 'c2' }] })
		const vm = view()
		await vm.toggleDrilldown(ROW)
		expect(vm.drilldownItems).toEqual([{ id: 'c2' }])

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
