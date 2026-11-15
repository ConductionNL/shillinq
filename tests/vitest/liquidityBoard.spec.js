/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Board ShLiquiditeit: the Liquidity page reads the register it names. Every
 * widget source is a schema the register declares, the forecast tiles look at
 * the weeks ahead, and what the page offered before (the PDF export, the
 * 13-week chart in its layout cell, the buffer status) is still there.
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const page = manifest.pages.find((p) => p.id === 'CashflowDashboard')
const widgets = Object.fromEntries(page.config.widgets.map((w) => [w.id, w]))
const registerText = fs
	.readdirSync(path.join(ROOT, 'lib/Settings/register.d'))
	.map((f) =>
		fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d', f), 'utf8'),
	)
	.join('\n')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8'),
).translations

/**
 * Every register/schema pair a widget reads.
 *
 * @param {object} w The widget.
 * @return {Array<string>} The schema slugs.
 */
function schemasOf(w) {
	const c = w.content || {}
	const sources = [c.source, c.dataSource, ...(c.entries || [])].filter(Boolean)
	return sources.filter((s) => s.schema).map((s) => s.schema)
}

describe('Liquidity board', () => {
	it('keeps its route, the PDF export and the chart cell', () => {
		expect(page.route).toBe('/cashflow/dashboard')
		expect(page.type).toBe('dashboard')
		expect(page.config.headerActions.map((a) => a.id)).toEqual([
			'export-cashflow-pdf',
			'add-scenario',
		])
		expect(page.config.headerActions[0].label).toBe('Export PDF')
		expect(
			page.config.layout.find((l) => l.id === 'layout-chart').widgetId,
		).toBe('cashflow-13week-chart')
		expect(widgets['buffer-status']).toBeDefined()
	})

	it('puts the board line above the forecast title', () => {
		const chart = widgets['cashflow-13week-chart']
		expect(chart.eyebrow).toBe('Balance per week')
		expect(nl['Balance per week']).toBe('Saldo per week')
	})

	it('draws the four key figures as a row', () => {
		expect(page.config.kpiRow).toEqual([
			'cash-today',
			'lowest-point',
			'expected-inflows',
			'expected-outflows',
		])
		for (const id of page.config.kpiRow) {
			expect(widgets[id].type).toBe('stat')
		}
		expect(widgets['cash-today'].content.endpointSource.url).toBe(
			'/apps/shillinq/api/v1/cash-position',
		)
		expect(routes).toContain("'url' => '/api/v1/cash-position'")
	})

	it('reads only schemas the register declares', () => {
		const schemas = page.config.widgets.flatMap(schemasOf)
		expect(schemas.length).toBeGreaterThan(5)
		for (const schema of new Set(schemas)) {
			expect(registerText).toContain(`"${schema}": {`)
		}
	})

	it('looks at the 13 weeks ahead for the forecast', () => {
		for (const id of ['lowest-point', 'expected-inflows', 'expected-outflows']) {
			expect(widgets[id].content.source.filter).toEqual({
				weekEnd: { gte: '@today' },
				weekStart: { lte: '@today+91d' },
			})
		}
		expect(
			widgets['cashflow-13week-chart'].content.dataSource.filter.weekEnd,
		).toEqual({ gte: '@today' })
	})

	it('places every grid widget once, inside 12 columns', () => {
		const ids = page.config.layout.map((l) => l.widgetId)
		expect(new Set(ids).size).toBe(ids.length)
		for (const l of page.config.layout) {
			expect(widgets[l.widgetId]).toBeDefined()
			expect(l.gridX + l.gridWidth).toBeLessThanOrEqual(12)
		}
	})
})
