/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The BBV mapping index filter bar as the server renders it: the fiscal year
 * label only with a scope year, every control bound to its filter value, the
 * seven fiscal years, and the date formatter's fallback for a value it cannot
 * read as a date.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { findVnodes, renderSsr } from './helpers/ssrStandIns.js'

vi.mock('../../src/store/modules/budgetBBVMappingStore.js', () => ({ useBudgetBBVMappingStore: () => ({}) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {} }))

const { default: BudgetBBVMappingIndex } = await import('../../src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue')

/**
 * The filter bar markup for some filter state.
 *
 * @param {object} data The data overrides.
 * @return {Promise<string>} The markup.
 */
async function filterBar(data) {
	const { html } = await renderSsr(BudgetBBVMappingIndex, {
		data: { loading: false, ...data },
		standIns: ['CnIndexPage'],
		slotProps: () => ({ row: { id: 'm-1' } }),
	})
	return html.match(/<slot-below-header>([\s\S]*?)<\/slot-below-header>/)[1]
}

describe('BudgetBBVMappingIndex filter bar', () => {
	it('labels the fiscal year of the scope, and nothing without one', async () => {
		const scoped = await filterBar({ scope: { fiscalYear: 2026 } })
		const unscoped = await filterBar({})

		expect(scoped).toMatch(/data-testid="bbv-mapping-fy-label"[^>]*>\s*FY 2026\s*</)
		expect(unscoped).not.toContain('bbv-mapping-fy-label')
	})

	it('binds every control to its filter value', async () => {
		const year = new Date().getFullYear()
		const html = await filterBar({
			searchTerm: '4300',
			fiscalYearFilter: year,
			allocationBucket: '25-50',
			effectiveFromAfter: '2026-01-01',
			effectiveFromBefore: '2026-12-31',
		})

		expect(html).toMatch(/type="search"[^>]*value="4300"|value="4300"[^>]*type="search"/)
		const selected = [...html.matchAll(/<option value="([^"]*)"[^>]*\bselected>/g)].map((m) => m[1])
		expect(selected).toEqual([String(year), '25-50'])
		expect(html).toMatch(/value="2026-01-01"[^>]*data-testid="bbv-mapping-effective-from-after"|data-testid="bbv-mapping-effective-from-after"[^>]*value="2026-01-01"/)
		expect(html).toMatch(/value="2026-12-31"[^>]*data-testid="bbv-mapping-effective-from-before"|data-testid="bbv-mapping-effective-from-before"[^>]*value="2026-12-31"/)
	})

	it('offers next year and the six before it, newest first', async () => {
		const year = new Date().getFullYear()
		const html = await filterBar({})
		const select = html.match(/data-testid="bbv-mapping-fiscal-year"[\s\S]*?<\/select>/)[0]
		const years = [...select.matchAll(/<option value="(\d+)"/g)].map((m) => Number(m[1]))

		expect(years).toEqual([year + 1, year, year - 1, year - 2, year - 3, year - 4, year - 5])
	})
})

describe('BudgetBBVMappingIndex filter inputs', () => {
	it('write each filter value through its own control', async () => {
		const { vm, seen } = await renderSsr(BudgetBBVMappingIndex, {
			data: { loading: false, scope: { fiscalYear: 2026 } },
			standIns: ['CnIndexPage'],
			slotProps: () => ({ row: { id: 'm-1' } }),
			slotMode: 'vnode',
		})
		const control = (testid) => findVnodes(seen['CnIndexPage:vnodes'], (v) => v.props?.['data-testid'] === testid)[0]

		control('bbv-mapping-search').props['onUpdate:modelValue']('4300')
		control('bbv-mapping-fiscal-year').props['onUpdate:modelValue'](2025)
		control('bbv-mapping-allocation').props['onUpdate:modelValue']('50-75')
		control('bbv-mapping-effective-from-after').props['onUpdate:modelValue']('2026-02-01')
		control('bbv-mapping-effective-from-before').props['onUpdate:modelValue']('2026-03-01')

		expect(vm.searchTerm).toBe('4300')
		expect(vm.fiscalYearFilter).toBe(2025)
		expect(vm.allocationBucket).toBe('50-75')
		expect(vm.effectiveFromAfter).toBe('2026-02-01')
		expect(vm.effectiveFromBefore).toBe('2026-03-01')
	})
})

describe('BudgetBBVMappingIndex formatDate', () => {
	it('returns the value itself when it cannot be read as a date', () => {
		const unreadable = { [Symbol.toPrimitive]: () => { throw new TypeError('not a date') } }
		const vm = { ...BudgetBBVMappingIndex.methods }

		expect(BudgetBBVMappingIndex.methods.formatDate.call(vm, unreadable)).toBe(unreadable)
		expect(BudgetBBVMappingIndex.methods.formatDate.call(vm, null)).toBe('—')
	})
})
