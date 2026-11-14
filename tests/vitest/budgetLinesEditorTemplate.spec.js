/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The budget entry grid rendered from its real template: the budgets to
 * choose from, a typed row with an input per month and a spread button, a
 * row from another source read-only and named after it, a pending draft in
 * its cell, the message line and the spread form.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/specs/budget-grid-view/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: BudgetLinesEditor } = await import('../../src/components/BudgetLinesEditor.vue')

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * Render the grid for a state.
 *
 * @param {object} data Preset data.
 * @return {Promise<string>} The HTML.
 */
const render = (data) => renderTemplate(BudgetLinesEditor, { props: { administrationId: 'adm' }, data, components: { NcButton: standIn('NcButton') } })

const months = (cents) => Array.from({ length: 12 }, (_, i) => cents + i)

const ROWS = [
	{ ledgerGroupId: 'g1', name: 'Personeel', depth: 0, editable: true, months: months(100000), total: 1200066 },
	{ ledgerGroupId: 'g2', name: 'Huur', depth: 1, editable: false, source: 'contract', months: months(50000), total: 600066 },
	{ ledgerGroupId: 'g3', name: 'Scenario', depth: 1, editable: false, source: 'unknown-source', months: months(0), total: 66 },
]

describe('BudgetLinesEditor template', () => {
	it('offers the budgets to choose from and shows no grid before one is chosen', async () => {
		const html = await render({ budgets: [{ annualBudgetId: 'b1', fiscalYear: 2026, name: 'Begroting' }] })

		expect(text(html)).toContain('Choose a budget 2026: Begroting')
		expect(html).toContain('<option value="b1"')
		expect(html).not.toContain('budget-entry-row')
		expect(html).not.toContain('role="status"')
	})

	it('lays out a typed row as twelve inputs and a spread button, other sources read-only and named', async () => {
		const html = await render({ annualBudgetId: 'b1', rows: ROWS, drafts: { '0-1': '1.234,5' }, message: 'The amount could not be saved.' })
		const rows = html.split('data-testid="budget-entry-row"').slice(1).map((part) => '<tr ' + part)

		expect(text(html)).toContain('The amount could not be saved.')
		expect(rows).toHaveLength(3)
		const inputs = [...rows[0].matchAll(/<input[^>]*value="([^"]*)"/g)].map((m) => m[1])
		expect(inputs).toHaveLength(12)
		expect(inputs.slice(0, 3)).toEqual(['1000', '1.234,5', '1000.02'])
		expect(rows[0]).toContain('padding-inline-start:8px')
		expect(text(rows[0])).toContain('Spread over months')
		expect(rows[0]).not.toContain('budget-entry-source')

		expect(text(rows[1])).toMatch(/^Huur from contracts 500 500.01/)
		expect(rows[1]).not.toContain('<input')
		expect(rows[1]).toContain('aria-label="500, from contracts, read-only"')
		expect(rows[1]).not.toContain('Spread over months')
		expect(rows[1]).toContain('padding-inline-start:28px')
		expect(text(rows[2])).toContain('Scenario unknown-source')
		expect(text(rows[0])).toContain('12000.66')
		expect(html.match(/<th scope="col"/g)).toHaveLength(15)
	})

	it('shows the spread form for the chosen row', async () => {
		const html = await render({ annualBudgetId: 'b1', rows: ROWS, spreadRow: 0, yearly: '12000' })
		const form = html.slice(html.indexOf('budget-entry-spread-form'))

		expect(text(form)).toContain('Yearly amount for Personeel')
		expect(form).toContain('value="12000"')
		expect(text(form)).toContain('Spread Cancel')
	})

	it('names every known source', () => {
		const label = BudgetLinesEditor.methods.sourceLabel
		expect(['contract', 'recurring', 'projected', 'scenario', 'other'].map((s) => label(s)))
			.toEqual(['from contracts', 'from recurring items', 'projected', 'from a scenario', 'other'])
	})
})
