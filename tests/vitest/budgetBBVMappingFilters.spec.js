/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins BudgetBBVMappingIndex's `filteredObjects` computed: the search term on
 * account and programme, the fiscal-year overlap, the allocation bucket and
 * the effective-from window, alone and together, in the original row order.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import BudgetBBVMappingIndex from '../../src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue'

vi.mock('../../src/store/modules/budgetBBVMappingStore.js', () => ({ useBudgetBBVMappingStore: () => ({}) }))

const ROWS = [
	{ id: 1, glAccountNumber: '4000', programmeCode: 'P01', effectiveFrom: '2024-01-01', effectiveTo: '2024-12-31', allocationPercentage: 100 },
	{ id: 2, glAccountNumber: '4100', programmeCode: 'P02', effectiveFrom: '2025-03-01', effectiveTo: null, allocationPercentage: '40' },
	{ id: 3, glAccountNumber: 4200, programmeCode: 'XP01', effectiveFrom: null, effectiveTo: '2025-06-30', allocationPercentage: 'n/a' },
	{ id: 4, glAccountNumber: null, programmeCode: null, effectiveFrom: '2026-07-15', effectiveTo: '2027-01-01', allocationPercentage: 0 },
	{ id: 5, glAccountNumber: '5000', programmeCode: 'p03', effectiveFrom: '2023-05-01', effectiveTo: '2026-02-01', allocationPercentage: 75.5 },
]

/**
 * Evaluate filteredObjects against a fake instance.
 *
 * @param {object} state The filter fields on the instance.
 * @return {Array<number>} The ids of the rows that pass.
 */
function ids(state) {
	const ctx = {
		objects: ROWS,
		searchTermApplied: '',
		effectiveFromAfter: '',
		effectiveFromBefore: '',
		allocationBucket: '',
		fiscalYearFilter: '',
		...state,
	}
	ctx.parseAllocationBucket = BudgetBBVMappingIndex.methods.parseAllocationBucket.bind(ctx)
	return BudgetBBVMappingIndex.computed.filteredObjects.call(ctx).map((row) => row.id)
}

describe('BudgetBBVMappingIndex filteredObjects', () => {
	it.each([
		['no filter', {}, [1, 2, 3, 4, 5]],
		['no objects', { objects: null }, []],
		['term on account', { searchTermApplied: ' 41 ' }, [2]],
		['term on programme, case-insensitive', { searchTermApplied: 'P0' }, [1, 2, 3, 5]],
		['term on a numeric account', { searchTermApplied: '4200' }, [3]],
		['term matching nothing', { searchTermApplied: 'zz' }, []],
		['fiscal year 2024', { fiscalYearFilter: '2024' }, [1, 3, 5]],
		['fiscal year 2025', { fiscalYearFilter: 2025 }, [2, 3, 5]],
		['fiscal year 2027', { fiscalYearFilter: '2027' }, [2, 4]],
		['allocation 0-50', { allocationBucket: '0-50' }, [2, 4]],
		['allocation 50-100', { allocationBucket: '50-100' }, [1, 5]],
		['allocation bucket malformed', { allocationBucket: 'all' }, [1, 2, 3, 4, 5]],
		['effective after', { effectiveFromAfter: '2025-01-01' }, [2, 4]],
		['effective before', { effectiveFromBefore: '2024-06-01' }, [1, 5]],
		['effective window', { effectiveFromAfter: '2024-01-01', effectiveFromBefore: '2025-12-31' }, [1, 2]],
		['all together', { searchTermApplied: 'p0', fiscalYearFilter: '2025', allocationBucket: '0-50', effectiveFromAfter: '2025-01-01' }, [2]],
	])('%s', (name, state, expected) => {
		expect(ids(state)).toEqual(expected)
	})
})
