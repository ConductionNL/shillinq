/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for GeneratedReportsIndex.vue: which envelope shapes of the
 * generated-reports endpoint it reads, the filters over category, period and
 * administration, the administration names it shows instead of ids, and the
 * labels for missing values. Methods and computed run bound to a fake `this`;
 * only axios is doubled.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GeneratedReportsIndex from '../../src/components/reporting/GeneratedReportsIndex.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const ROWS = [
	{ id: 1, reportType: 'Balans', category: 'statements', period: '2025', administrationId: 'adm-1' },
	{ id: 2, reportType: 'BTW', category: 'tax', period: '2026-Q1', administrationId: 'adm-2' },
	{ id: 3, reportType: 'BTW', category: 'tax', period: '2025', administrationId: 'adm-1' },
	{ id: 4, reportType: 'Losse', category: '', period: '', administrationId: '' },
]

/**
 * A `this` with the real data(), the methods and the computed as getters.
 *
 * @param {object} [overrides] Fields to override.
 * @return {object} The context.
 */
function ctx(overrides = {}) {
	const self = { ...GeneratedReportsIndex.data.call({}), ...GeneratedReportsIndex.methods, ...overrides }
	for (const [name, getter] of Object.entries(GeneratedReportsIndex.computed)) {
		Object.defineProperty(self, name, { get: () => getter.call(self) })
	}
	return self
}

beforeEach(() => {
	vi.restoreAllMocks()
})

describe('GeneratedReportsIndex loading', () => {
	it.each([
		['generated', { generated: ROWS, categories: { tax: 'Belasting' } }],
		['results', { results: ROWS }],
		['a bare list', ROWS],
	])('reads the rows from %s', async (_shape, data) => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data })
		const self = ctx()

		await self.loadGenerated()

		expect(get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/reporting/generated')
		expect(self.rows).toEqual(ROWS)
		expect(self.loading).toBe(false)
	})

	it('keeps the category labels and reads no rows from an unknown shape', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { generated: 'x', categories: { tax: 'Belasting' } } })
		const self = ctx()

		await self.loadGenerated()

		expect(self.rows).toEqual([])
		expect(self.categories).toEqual({ tax: 'Belasting' })
	})

	it('shows the server error, or its own message', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		const self = ctx()
		await self.loadGenerated()
		expect(self.error).toBe('No access')
		expect(self.loading).toBe(false)

		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('down'))
		await self.loadGenerated()
		expect(self.error).toBe('Failed to load generated reports')
	})

	it('maps administrations to a name, falling back to the code and the id', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				administrations: [
					{ administrationId: 'adm-1', name: 'Gemeente A', administrationCode: 'GA' },
					{ administrationId: 'adm-2', administrationCode: 'GB' },
					{ administrationId: 'adm-3' },
				],
			},
		})
		const self = ctx()

		await self.loadAdministrationContext()

		expect(self.administrationNames).toEqual({ 'adm-1': 'Gemeente A', 'adm-2': 'GB', 'adm-3': 'adm-3' })
	})

	it('shows ids when the administration context cannot be read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		const self = ctx({ administrationNames: { stale: 'x' } })

		await self.loadAdministrationContext()

		expect(self.administrationNames).toEqual({})
		expect(self.administrationLabel('adm-9')).toBe('adm-9')
	})
})

describe('GeneratedReportsIndex filters', () => {
	it('offers the categories, periods (newest first) and administrations present', () => {
		const self = ctx({ rows: ROWS, categories: { tax: 'Belasting' } })

		expect(self.categoryOptions).toEqual([
			{ value: 'statements', label: 'statements' },
			{ value: 'tax', label: 'Belasting' },
		])
		expect(self.periodOptions).toEqual(['2026-Q1', '2025'])
		expect(self.administrationOptions).toEqual(['adm-1', 'adm-2'])
	})

	it('narrows the rows by every filter set', () => {
		expect(ctx({ rows: ROWS }).filteredRows).toHaveLength(4)
		expect(ctx({ rows: ROWS, categoryFilter: 'tax' }).filteredRows.map((r) => r.id)).toEqual([2, 3])
		expect(ctx({ rows: ROWS, categoryFilter: 'tax', periodFilter: '2025' }).filteredRows.map((r) => r.id)).toEqual([3])
		expect(ctx({ rows: ROWS, administrationFilter: 'adm-1' }).filteredRows.map((r) => r.id)).toEqual([1, 3])
	})

	it('has a column per field, with the file column not sortable', () => {
		const columns = ctx().columns
		expect(columns.map((c) => c.key)).toEqual(['reportType', 'category', 'period', 'administrationId', 'format', 'generatedAt', 'download'])
		expect(columns.find((c) => c.key === 'download').sortable).toBe(false)
	})
})

describe('GeneratedReportsIndex labels', () => {
	it('names a report by its label, its type, or a dash', () => {
		const self = ctx()
		expect(self.reportLabel({ reportLabel: 'Balans 2025', reportType: 'Balans' })).toBe('Balans 2025')
		expect(self.reportLabel({ reportType: 'Balans' })).toBe('Balans')
		expect(self.reportLabel({})).toBe('—')
	})

	it('names a category and an administration, with a dash for none', () => {
		const self = ctx({ categories: { tax: 'Belasting' }, administrationNames: { 'adm-1': 'Gemeente A' } })
		expect(self.categoryLabel('tax')).toBe('Belasting')
		expect(self.categoryLabel('sbr')).toBe('sbr')
		expect(self.categoryLabel('')).toBe('—')
		expect(self.administrationLabel('adm-1')).toBe('Gemeente A')
		expect(self.administrationLabel('')).toBe('—')
	})

	it('links a row to its download and formats its date', () => {
		const self = ctx()
		expect(self.downloadUrl({ id: 42 })).toBe('/index.php/apps/shillinq/api/reporting/download/42')
		expect(self.formatDate('')).toBe('—')
		expect(self.formatDate('2026-03-04T10:00:00Z')).toBe(new Date('2026-03-04T10:00:00Z').toLocaleString())
	})
})
