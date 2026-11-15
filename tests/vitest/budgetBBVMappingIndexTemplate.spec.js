/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The budget mapping index rendered from its real template: the filter bar
 * (fiscal-year label, search, fiscal years, allocation ranges, effective
 * dates), the custom cells of each row (allocation, effective dates, a
 * status chip) and the error line. CnIndexPage is replaced by a stand-in
 * that renders the filter slot and the column slots per row it is given.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { renderTemplate } from './helpers/renderTemplate.js'

vi.mock('../../src/store/modules/budgetBBVMappingStore.js', () => ({ useBudgetBBVMappingStore: () => ({}) }))

globalThis.t = (_app, value) => value

const { default: BudgetBBVMappingIndex } = await import('../../src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue')

const COLUMNS = ['allocationPercentage', 'effectiveFrom', 'effectiveTo', 'lifecycleState']

const CnIndexPage = {
	name: 'CnIndexPage',
	props: { objects: { type: Array, default: () => [] }, title: String, includeColumns: Array },
	setup(props, { slots }) {
		return () => h('cn-index', { title: props.title, columns: (props.includeColumns || []).join(',') }, [
			slots['below-header']?.(),
			...props.objects.map((row) => h('cn-row', { 'data-id': row.id }, COLUMNS.map((column) => slots[`column-${column}`]?.({ row })))),
		])
	},
}

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * Render the index for a state.
 *
 * @param {object} data Preset data.
 * @return {Promise<string>} The HTML.
 */
const render = (data) => renderTemplate(BudgetBBVMappingIndex, { data: { loading: false, ...data }, components: { CnIndexPage } })

describe('BudgetBBVMappingIndex template', () => {
	it('lays out the filter bar, with the fiscal year of the scope when there is one', async () => {
		const html = await render({ scope: { fiscalYear: 2026 } })
		const year = new Date().getFullYear()

		expect(html).toContain('title="Budget Mapping"')
		expect(html).toContain('columns="glAccountNumber,programmeCode,allocationPercentage,effectiveFrom,effectiveTo,lifecycleState"')
		expect(text(html)).toContain('FY 2026')
		expect(html).toContain('placeholder="Search account or programme…"')
		expect(text(html)).toContain(`All fiscal years ${year + 1} ${year}`)
		expect(text(html)).toContain(`${year - 5} All allocations 0-25 % 25-50 % 50-75 % 75-100 %`)
		expect(text(html)).toContain('Effective on or after Effective on or before')
		expect(await render({})).not.toContain('bbv-mapping-fy-label')
	})

	it('writes each row\'s allocation, effective dates and status chip', async () => {
		const html = await render({
			objects: [
				{ id: 'm1', allocationPercentage: 62.5, effectiveFrom: '2026-01-01', effectiveTo: null, lifecycleState: 'Active' },
				{ id: 'm2', allocationPercentage: 'n/a', lifecycleState: 'pending review' },
				{ id: 'm3', allocationPercentage: '' },
			],
		})

		expect(text('<x' + html.split('data-id="m1"')[1])).toMatch(new RegExp(`^62.5 % ${new Date('2026-01-01').toLocaleDateString().replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} — Active`))
		expect(html).toContain('bbv-mapping-index__status--active')
		expect(text('<x' + html.split('data-id="m2"')[1])).toMatch(/^— — — pending review/)
		expect(html).toContain('bbv-mapping-index__status--pending-review')
		expect(text('<x' + html.split('data-id="m3"')[1])).toMatch(/^— — — —/)
		expect(html).toContain('bbv-mapping-index__status--unknown')
	})

	it('shows a load failure under the list', async () => {
		expect(text(await render({ error: 'Failed to load mappings' }))).toContain('Failed to load mappings')
		expect(await render({})).not.toContain('bbv-mapping-index-error')
	})
})

describe('BudgetBBVMappingIndex lifecycle', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('reads the scope, then the mappings, then subscribes to live updates, in that order', async () => {
		const calls = []
		const self = {
			loadScope: vi.fn(async () => calls.push('scope')),
			loadMappings: vi.fn(async () => calls.push('mappings')),
			syncLiveSubscription: vi.fn(() => calls.push('live')),
		}
		await BudgetBBVMappingIndex.created.call(self)
		expect(calls).toEqual(['scope', 'mappings', 'live'])
	})

	it('cancels a pending search and releases the live subscription when it goes', () => {
		vi.useFakeTimers()
		const search = vi.fn()
		const self = { searchDebounce: setTimeout(search, 250), releaseLiveSubscription: vi.fn() }
		BudgetBBVMappingIndex.beforeUnmount.call(self)
		vi.advanceTimersByTime(500)
		expect(search).not.toHaveBeenCalled()
		expect(self.releaseLiveSubscription).toHaveBeenCalledTimes(1)

		const idle = { searchDebounce: null, releaseLiveSubscription: vi.fn() }
		BudgetBBVMappingIndex.beforeUnmount.call(idle)
		expect(idle.releaseLiveSubscription).toHaveBeenCalledTimes(1)
	})
})
