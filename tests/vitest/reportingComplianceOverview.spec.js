/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for ReportingComplianceOverview.vue: the catalogue it builds
 * from the report types plus the on-screen report views, the format each
 * card starts on, the category filter and free-text search grouped in
 * catalogue order, the administration context for the generate dialog, and
 * the toasts after generating. Methods and computed run bound to a fake
 * `this`; axios and the toast functions are doubled.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ReportingComplianceOverview from '../../src/components/reporting/ReportingComplianceOverview.vue'
import { reportViewCategories, reportViews } from '../../src/components/reporting/reportViews.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/modals/GenerateReportDialog.vue', () => ({ default: { name: 'GenerateReportDialog' } }))

const TYPES = [
	{ id: 'jaarrekening', label: 'Jaarrekening', description: 'Balans en W&V', category: 'statements', kind: 'document', formats: ['pdf', 'xlsx'] },
	{ id: 'btw', label: 'BTW-aangifte', description: 'Kwartaal', category: 'tax', kind: 'data', formats: [] },
	{ id: 'eigen', label: 'Eigen rapport', description: 'x', category: 'custom', kind: 'data' },
]

/**
 * A `this` with the real data(), the methods and the computed as getters.
 *
 * @param {object} [overrides] Fields to override.
 * @return {object} The context.
 */
function ctx(overrides = {}) {
	const self = { ...ReportingComplianceOverview.data.call({}), ...ReportingComplianceOverview.methods, ...overrides }
	for (const [name, getter] of Object.entries(ReportingComplianceOverview.computed)) {
		Object.defineProperty(self, name, { get: () => getter.call(self) })
	}
	return self
}

beforeEach(() => {
	vi.restoreAllMocks()
	vi.clearAllMocks()
})

describe('ReportingComplianceOverview catalogue', () => {
	it('merges the report types with the report views and seeds each format picker', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { types: TYPES, categories: { statements: 'Jaarrekening', tax: 'Belasting' } } })
		const self = ctx()

		await self.loadTypes()

		expect(get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/reporting/types')
		expect(self.reports).toHaveLength(TYPES.length + reportViews.length)
		expect(self.reports.slice(0, 3)).toEqual(TYPES)
		const view = self.reports[TYPES.length]
		expect(view).toEqual({ ...reportViews[0], kind: 'view', formats: [], description: 'Open this report.' })
		expect(self.categories).toEqual({ statements: 'Jaarrekening', tax: 'Belasting', ...reportViewCategories })
		expect(self.selectedFormat.jaarrekening).toBe('pdf')
		expect(self.selectedFormat.btw).toBe('')
		expect(self.selectedFormat.eigen).toBe('')
		expect(self.loading).toBe(false)
	})

	it('reads a bare list, and only the views from an unknown shape', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: TYPES })
		const self = ctx()
		await self.loadTypes()
		expect(self.reports).toHaveLength(TYPES.length + reportViews.length)

		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { types: 'x' } })
		await self.loadTypes()
		expect(self.reports).toHaveLength(reportViews.length)
		expect(self.reports.every((report) => report.kind === 'view')).toBe(true)
	})

	it('shows the server error, or its own message', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		const self = ctx()
		await self.loadTypes()
		expect(self.error).toBe('Forbidden')
		expect(self.loading).toBe(false)

		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('down'))
		await self.loadTypes()
		expect(self.error).toBe('Failed to load the report catalogue')
	})
})

describe('ReportingComplianceOverview filtering', () => {
	const categories = { tax: 'Belasting', statements: 'Jaarrekening', unused: 'Nooit' }

	it('offers only categories that hold a report, in catalogue order', () => {
		expect(ctx({ reports: TYPES, categories }).categoryOptions).toEqual([
			{ value: 'tax', label: 'Belasting' },
			{ value: 'statements', label: 'Jaarrekening' },
		])
	})

	it('groups in catalogue order and puts unknown categories last', () => {
		const groups = ctx({ reports: TYPES, categories }).filteredGroups
		expect(groups.map((group) => [group.category, group.label, group.reports.map((r) => r.id)])).toEqual([
			['tax', 'Belasting', ['btw']],
			['statements', 'Jaarrekening', ['jaarrekening']],
			['custom', 'custom', ['eigen']],
		])
	})

	it('narrows by category and by a search over label, description and id', () => {
		expect(ctx({ reports: TYPES, categories, categoryFilter: 'tax' }).filteredGroups.map((g) => g.category)).toEqual(['tax'])
		expect(ctx({ reports: TYPES, categories, search: '  W&V ' }).filteredGroups.flatMap((g) => g.reports.map((r) => r.id))).toEqual(['jaarrekening'])
		expect(ctx({ reports: TYPES, categories, search: 'EIGEN' }).filteredGroups.flatMap((g) => g.reports.map((r) => r.id))).toEqual(['eigen'])
		expect(ctx({ reports: TYPES, categories, search: 'nothing like it' }).filteredGroups).toEqual([])
	})
})

describe('ReportingComplianceOverview administration context', () => {
	it('offers the administrations by name and pre-selects the active one', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				administrations: [
					{ administrationId: 'adm-1', name: 'Gemeente A' },
					{ administrationId: 'adm-2', administrationCode: 'GB' },
					{ administrationId: 'adm-3' },
				],
				activeAdministrationId: 'adm-2',
			},
		})
		const self = ctx()

		await self.loadAdministrationContext()

		expect(self.administrationOptions).toEqual([
			{ value: 'adm-1', label: 'Gemeente A' },
			{ value: 'adm-2', label: 'GB' },
			{ value: 'adm-3', label: 'adm-3' },
		])
		expect(self.activeAdministrationId).toBe('adm-2')
	})

	it('falls back to no options when the context cannot be read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		const self = ctx({ administrationOptions: [{ value: 'stale' }], activeAdministrationId: 'adm-1' })

		await self.loadAdministrationContext()

		expect(self.administrationOptions).toEqual([])
		expect(self.activeAdministrationId).toBe('adm-1')
	})
})

describe('ReportingComplianceOverview generating', () => {
	it('opens the dialog for a report', () => {
		const self = ctx()
		self.openGenerate(TYPES[0])
		expect(self.dialogReport).toBe(TYPES[0])
	})

	it('closes the dialog and links the generated file, from its url or its id', () => {
		const self = ctx({ dialogReport: TYPES[0] })

		self.onGenerated({ downloadUrl: '/file.pdf' })
		expect(self.dialogReport).toBeNull()
		expect(showSuccess).toHaveBeenLastCalledWith(
			'Report generated — <a href="/file.pdf" target="_blank" rel="noopener noreferrer">download the file</a>',
			{ isHTML: true },
		)

		self.onGenerated({ id: 7 })
		expect(showSuccess).toHaveBeenLastCalledWith(
			'Report generated — <a href="/index.php/apps/shillinq/api/reporting/download/7" target="_blank" rel="noopener noreferrer">download the file</a>',
			{ isHTML: true },
		)
	})

	it('says the report was generated without a link when there is no file', () => {
		ctx().onGenerated(null)
		expect(showSuccess).toHaveBeenCalledWith('Report generated.')
	})

	it('shows the dialog error, or its own message', () => {
		const self = ctx()
		self.onGenerateError('Period is closed')
		expect(showError).toHaveBeenLastCalledWith('Period is closed')
		self.onGenerateError('')
		expect(showError).toHaveBeenLastCalledWith('Report generation failed')
	})

	it('names the kinds of report', () => {
		const self = ctx()
		expect(self.kindLabel('document')).toBe('Document')
		expect(self.kindLabel('data')).toBe('Data export')
		expect(self.kindLabel('view')).toBe('view')
		expect(self.kindLabel(undefined)).toBe('')
	})
})
