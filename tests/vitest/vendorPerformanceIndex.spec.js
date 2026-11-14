/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins VendorPerformanceIndex against a fake instance: the latest scorecard
 * per supplier and administration, ranked by overall score; the
 * auto-review eligibility filter; loading the scorecards; and the columns.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import VendorPerformanceIndex from '../../src/components/vendor-performance/VendorPerformanceIndex.vue'

const { computed, methods } = VendorPerformanceIndex
const originalGet = axios.get

/**
 * A fake instance whose computed values are evaluated on demand.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function instance(overrides = {}) {
	const vm = { ...VendorPerformanceIndex.data(), t: (_app, text) => text, ...overrides }
	for (const [name, fn] of Object.entries(computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

const rows = [
	{ id: 'a1', supplierId: 'acme', administrationId: 'adm-1', period: '2026-01', overallScore: 9000, automatedReviewEligible: true },
	{ id: 'a2', supplierId: 'acme', administrationId: 'adm-1', period: '2026-02', overallScore: 8000, automatedReviewEligible: false },
	{ id: 'a3', supplierId: 'acme', administrationId: 'adm-2', period: '2026-01', overallScore: 9900, automatedReviewEligible: true },
	{ id: 'b1', supplierId: 'bolt', administrationId: 'adm-1', period: '2026-02', overallScore: 9500, automatedReviewEligible: true },
	{ id: 'b0', supplierId: 'bolt', administrationId: 'adm-1', period: '2025-12', overallScore: 9999, automatedReviewEligible: false },
]

afterEach(() => {
	axios.get = originalGet
})

describe('VendorPerformanceIndex rows', () => {
	it('keeps the latest period per supplier and administration, best score first', () => {
		expect(instance({ rows }).latestPerSupplier.map((r) => r.id)).toEqual(['a3', 'b1', 'a2'])
	})

	it('keeps the first row of a period that does not come later', () => {
		const tie = [
			{ id: 'x1', supplierId: 's', period: '2026-01', overallScore: 1 },
			{ id: 'x2', supplierId: 's', period: '2026-01', overallScore: 2 },
		]
		expect(instance({ rows: tie }).latestPerSupplier.map((r) => r.id)).toEqual(['x1'])
	})

	it('filters on auto-review eligibility', () => {
		expect(instance({ rows, eligibilityFilter: 'eligible' }).filteredRows.map((r) => r.id)).toEqual(['a3', 'b1'])
		expect(instance({ rows, eligibilityFilter: 'ineligible' }).filteredRows.map((r) => r.id)).toEqual(['a2'])
		expect(instance({ rows, eligibilityFilter: '' }).filteredRows.map((r) => r.id)).toEqual(['a3', 'b1', 'a2'])
	})

	it('offers sortable columns and an unsortable actions column', () => {
		const columns = instance().columns
		expect(columns.map((c) => c.key)).toEqual([
			'supplierId', 'period', 'overallScore', 'scoreTrend', 'disputeCount', 'automatedReviewEligible', 'actions',
		])
		expect(columns.find((c) => c.key === 'overallScore').label).toBe('Overall score')
		expect(columns.filter((c) => c.sortable === false).map((c) => c.key)).toEqual(['actions'])
	})

	it('names the score band', () => {
		expect(instance().scoreClass(9700)).toBe('vp-index__score--high')
		expect(instance().scoreClass(100)).toBe('vp-index__score--low')
	})
})

describe('VendorPerformanceIndex loading', () => {
	it('loads the scorecards from a results envelope', async () => {
		axios.get = vi.fn(async () => ({ data: { results: rows } }))
		const vm = instance()

		await VendorPerformanceIndex.created.call(vm)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/VendorPerformance')
		expect(vm.rows).toBe(rows)
		expect(vm.loading).toBe(false)
	})

	it('accepts a bare list and ignores anything else', async () => {
		axios.get = vi.fn(async () => ({ data: [rows[0]] }))
		const vm = instance()
		await vm.loadRows()
		expect(vm.rows).toEqual([rows[0]])

		axios.get = vi.fn(async () => ({ data: { count: 3 } }))
		await vm.loadRows()
		expect(vm.rows).toEqual([])
	})

	it('shows the server error, or a generic one', async () => {
		axios.get = vi.fn(async () => {
			throw { response: { data: { error: 'No access' } } }
		})
		const vm = instance()
		await vm.loadRows()
		expect(vm.error).toBe('No access')
		expect(vm.loading).toBe(false)

		axios.get = vi.fn(async () => {
			throw new Error('offline')
		})
		await vm.loadRows()
		expect(vm.error).toBe('Failed to load vendor scorecards')
	})
})
