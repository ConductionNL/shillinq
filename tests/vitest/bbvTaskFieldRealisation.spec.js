/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the realisation-per-task-field widget of the BBV dashboard
 * (public-sector-task-field-allocation REQ-BBV-012): the task-field rows, the
 * unmapped accounts with their link to the mapping page, the euro format, and
 * the dashboard taking the block from the /api/bbv-dashboard envelope.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BBVComplianceDashboard from '../../src/components/Dashboard/BBVComplianceDashboard.vue'
import BBVTaskFieldRealisation from '../../src/components/Dashboard/BBVTaskFieldRealisation.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

/**
 * A fake instance of the widget with its methods and computed values bound.
 *
 * @param {object|null} realisation The realisation prop.
 * @return {object}
 */
function widget(realisation) {
	const ctx = { realisation, loading: false }
	for (const [name, fn] of Object.entries(BBVTaskFieldRealisation.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(BBVTaskFieldRealisation.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx) })
	}
	return ctx
}

describe('BBVTaskFieldRealisation — task fields and unmapped accounts', () => {
	it('shows each task field with its expenses and income', () => {
		const ctx = widget({
			fiscalYear: '2026',
			taskFields: [
				{ taskField: '0.1', expenses: 40000, income: 0 },
				{ taskField: '2.1', expenses: '1250.5', income: 300.25 },
				{ taskField: '', expenses: 1, income: 1 },
				null,
			],
			unmapped: [],
		})

		expect(ctx.taskFieldRows).toEqual([
			{ taskField: '0.1', expenses: 40000, income: 0 },
			{ taskField: '2.1', expenses: 1250.5, income: 300.25 },
		])
		expect(ctx.columns.map((c) => c.key)).toEqual(['taskField', 'expenses', 'income'])
	})

	it('lists an unmapped account with its amount and a link to the mapping page', () => {
		const ctx = widget({
			taskFields: [{ taskField: '0.1', expenses: 40000, income: 0 }],
			unmapped: [
				{ accountNumber: '4999', amount: 5000, mappingPage: '/overheid/bbv-mapping' },
				{ accountNumber: '8100', amount: -12.5 },
				{ accountNumber: '', amount: 7 },
			],
		})

		expect(ctx.unmappedRows).toEqual([
			{ accountNumber: '4999', amount: 5000, href: '/index.php/apps/shillinq/overheid/bbv-mapping' },
			{ accountNumber: '8100', amount: -12.5, href: '/index.php/apps/shillinq/overheid/bbv-mapping' },
		])
	})

	it('never links outside the app for a mapping page that is not an app path', () => {
		const ctx = widget(null)

		expect(ctx.mappingHref('https://example.org/x')).toBe('/index.php/apps/shillinq/overheid/bbv-mapping')
		expect(ctx.mappingHref('/overheid/other')).toBe('/index.php/apps/shillinq/overheid/other')
	})

	it('renders nothing for a missing or malformed block', () => {
		expect(widget(null).taskFieldRows).toEqual([])
		expect(widget(null).unmappedRows).toEqual([])
		expect(widget({ taskFields: 'x', unmapped: {} }).taskFieldRows).toEqual([])
		expect(widget({ taskFields: 'x', unmapped: {} }).unmappedRows).toEqual([])
	})

	it('formats euros to the cent and a non-number as a dash', () => {
		const ctx = widget(null)
		const space = /\s/g

		expect(ctx.formatEuro(40000).replace(space, ' ')).toBe('€ 40.000,00')
		expect(ctx.formatEuro(5000.01).replace(space, ' ')).toBe('€ 5.000,01')
		expect(ctx.formatEuro('abc')).toBe('—')
	})
})

describe('BBVComplianceDashboard — the realisation block of the envelope', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * A fake dashboard instance.
	 *
	 * @return {object}
	 */
	function dashboard() {
		const ctx = { ...BBVComplianceDashboard.data(), $emit: vi.fn() }
		for (const [name, fn] of Object.entries(BBVComplianceDashboard.methods)) {
			ctx[name] = fn.bind(ctx)
		}
		return ctx
	}

	it('takes taskFieldRealisation from the envelope and adds its widget to the layout', async () => {
		const block = { fiscalYear: '2026', taskFields: [{ taskField: '0.1', expenses: 40000, income: 0 }], unmapped: [] }
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { programmes: [], taskFieldRealisation: block } })
		const ctx = dashboard()
		ctx.administrationId = 'adm-1'

		await ctx.loadProgrammes()

		expect(get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/bbv-dashboard', { params: { administrationId: 'adm-1' } })
		expect(ctx.taskFieldRealisation).toEqual(block)
		const t = (app, text) => text
		expect(BBVComplianceDashboard.computed.widgets.call({ t }).map((w) => w.id)).toContain('bbv-task-fields')
		expect(BBVComplianceDashboard.computed.layout.call({}).find((l) => l.widgetId === 'bbv-task-fields')).toMatchObject({ gridWidth: 12 })
	})

	it('clears the block when the envelope has none or the request fails', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: { taskFieldRealisation: 'x' } })
		const ctx = dashboard()
		ctx.taskFieldRealisation = { taskFields: [] }

		await ctx.loadProgrammes()
		expect(ctx.taskFieldRealisation).toBeNull()

		ctx.taskFieldRealisation = { taskFields: [] }
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('down'))
		await ctx.loadProgrammes()
		expect(ctx.taskFieldRealisation).toBeNull()
		expect(ctx.error).toBe('Failed to load BBV programmes')
	})
})
