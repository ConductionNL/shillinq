/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetTrendChart rendered from its real template: the accessible data
 * table (the primary keyboard / screen-reader path, design.md §9) with its
 * Actual / Projected / Begroot rows and totals, the partial-month badge,
 * the loading state, the disabled Cumulative toggle's explanation, and the
 * not-yet-ready state before the sidebar's object has loaded. The shared
 * fetch-once composable is mocked with a controllable payload; no network.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-004
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-009
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

const chart = vi.hoisted(() => ({ loading: false, data: null, calls: [], load: null }))

vi.mock('../../src/components/budget-charts/useBudgetChartData.js', () => ({
	useBudgetChartData: (administrationId, range, annualBudgetId) => {
		chart.calls.push([administrationId, range, annualBudgetId])
		return { loading: ref(chart.loading), data: ref(chart.data), load: chart.load }
	},
}))

const { default: BudgetTrendChart } = await import('../../src/components/budget-charts/BudgetTrendChart.vue')
const { cellText } = await import('../../src/components/budget-charts/budgetChartSeries.js')

const PAYLOAD = {
	months: ['2027-01', '2027-02', '2027-03', '2027-04'],
	accounts: [
		{
			accountNumber: '8000',
			accountType: 'revenue',
			trend: {
				'2027-01': { kind: 'actual', amount: 10000 },
				'2027-02': { kind: 'actual', amount: 20000 },
				'2027-03': { kind: 'unprojectable', reason: 'insufficient-data' },
				'2027-04': { kind: 'projected', amount: 5000 },
			},
			budgeted: { '2027-01': 9000, '2027-02': 9000, '2027-04': 1000 },
		},
		{
			accountNumber: '0100',
			accountType: 'assets',
			trend: { '2027-01': { kind: 'actual', amount: 1 } },
			budgeted: {},
		},
	],
	ledgerGroups: [
		{
			ledgerGroupKey: 'lg-9',
			accountTypes: ['expenses'],
			trend: {
				'2027-01': { kind: 'actual', amount: 100 },
				'2027-04': { kind: 'projected', amount: 300, partial: true },
			},
			budgeted: {},
		},
	],
}

const COMPONENTS = { NcLoadingIcon: standIn('NcLoadingIcon'), CnChartWidget: standIn('CnChartWidget') }

/**
 * An amount as the table writes it.
 *
 * @param {number} cents The amount in cents.
 * @return {string} The formatted amount.
 */
const eur = (cents) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(cents / 100)

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<!--[^>]*-->/g, '').replace(/<[^>]+>/g, ' ').replace(/[ \t\r\n]+/g, ' ').trim()

/**
 * The cells of one table body row, by its row header.
 *
 * @param {string} html The HTML.
 * @param {string} header The row header text.
 * @return {string[]} The cell texts, the total last.
 */
function row(html, header) {
	const body = html.match(/<tbody[^>]*>(.*)<\/tbody>/s)[1]
	const tr = body.split('</tr>').find((r) => text(r.match(/<th[^>]*>(.*?)<\/th>/s)?.[1] ?? '') === header)
	return [...tr.matchAll(/<td[^>]*>(.*?)<\/td>/gs)].map((m) => text(m[1]))
}

/**
 * Render the chart with props and data.
 *
 * @param {object} props The props.
 * @param {object} [data] Data to preset.
 * @return {Promise<string>} The HTML.
 */
function render(props, data = {}) {
	return renderTemplate(BudgetTrendChart, { props, data, components: COMPONENTS })
}

afterEach(() => {
	chart.loading = false
	chart.data = null
	chart.calls = []
	chart.load = null
})

describe('BudgetTrendChart data table', () => {
	it('lists Actual, Projected and Begroot per month with their totals', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'account', id: '8000', name: 'Omzet', administrationId: 'adm-1', range: { from: '2027-01', to: '2027-04' } }, { showTable: true })

		expect(text(html.match(/<thead[^>]*>(.*)<\/thead>/s)[1])).toBe('Series 2027-01 2027-02 2027-03 2027-04 TOTAAL')
		expect(row(html, 'Actual')).toEqual([eur(10000), eur(20000), '—', '—', eur(30000)])
		expect(row(html, 'Projected')).toEqual(['—', '—', 'Cannot project yet', eur(5000), eur(5000)])
		expect(row(html, 'Begroot')).toEqual([eur(9000), eur(9000), eur(0), eur(1000), eur(19000)])
		expect(text(html.match(/<caption[^>]*>(.*?)<\/caption>/s)[1])).toBe('Trend chart for Omzet: actual, projected and budgeted amounts')
		expect(html).not.toContain('budget-trend-chart__partial-badge')
		expect(chart.calls[0]).toEqual(['adm-1', { from: '2027-01', to: '2027-04' }, null])
	})

	it('badges a partial LedgerGroup month in the Projected row', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'ledgerGroup', id: 'lg-9', name: 'Kosten', administrationId: 'adm-1' }, { showTable: true })

		expect(row(html, 'Projected')).toEqual(['—', '—', '—', `${eur(300)} partial`, eur(300)])
		expect(html.match(/budget-trend-chart__partial-badge/g)).toHaveLength(1)
	})

	it('renders no table until it is asked for', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'account', id: '8000', administrationId: 'adm-1' })

		expect(html).not.toContain('<table')
		expect(html).toContain('data-name="CnChartWidget"')
		expect(html).toContain('aria-pressed="false"')
	})
})

describe('BudgetTrendChart toolbar and loading', () => {
	it('explains the disabled Cumulative toggle for a balance-sheet account', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'account', id: '0100', administrationId: 'adm-1' })
		const toggle = html.match(/<button[^>]*budget-trend-chart-toggle-cumulative[^>]*>/)[0]

		expect(toggle).toContain('disabled')
		expect(toggle).toContain('title="Cumulative equals trend for balance-sheet accounts"')
	})

	it('leaves Cumulative enabled and untitled for a flow account', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'account', id: '8000', administrationId: 'adm-1' })
		const toggle = html.match(/<button[^>]*budget-trend-chart-toggle-cumulative[^>]*>/)[0]

		expect(toggle).not.toContain('disabled')
		expect(toggle).not.toContain('title=')
	})

	it('shows the loading icon while the shared request is in flight', async () => {
		chart.loading = true
		const html = await render({ scope: 'account', id: '8000', administrationId: 'adm-1' })

		expect(html).toContain('data-name="NcLoadingIcon"')
	})

	it('neither loads nor asks for data before the sidebar object has arrived', async () => {
		chart.loading = true
		chart.data = PAYLOAD
		const html = await render({ scope: 'account' })

		expect(html).not.toContain('data-name="NcLoadingIcon"')
		expect(chart.calls).toEqual([])
		expect(html).toContain('aria-label="Trend chart for : actual, projected and budgeted amounts"')
	})

	it('keeps Cumulative enabled when the payload has no entry for the id', async () => {
		chart.data = PAYLOAD
		const html = await render({ scope: 'account', id: '9999', administrationId: 'adm-1' }, { showTable: true })
		const toggle = html.match(/<button[^>]*budget-trend-chart-toggle-cumulative[^>]*>/)[0]

		expect(toggle).not.toContain('disabled')
		expect(row(html, 'Actual')).toEqual(['—', '—', '—', '—', expect.any(String)])
	})
})

describe('BudgetTrendChart lifecycle and props', () => {
	it('starts the shared load when mounted', () => {
		const load = vi.fn()
		BudgetTrendChart.mounted.call({ load })
		expect(load).toHaveBeenCalledTimes(1)
	})

	it('accepts only the account and ledgerGroup scopes', () => {
		const { validator } = BudgetTrendChart.props.scope
		expect(validator('account')).toBe(true)
		expect(validator('ledgerGroup')).toBe(true)
		expect(validator('verzamelpost')).toBe(false)
	})

	it('has no entry while there is no payload yet', () => {
		const entry = BudgetTrendChart.computed.entry.call({ chartData: null })
		expect(entry).toBeNull()
	})
})

describe('cellText for a flat value', () => {
	it('formats a flat euro amount as cents, and an absent one as a dash', () => {
		const t = (_app, s) => s
		expect(cellText(t, null, 12.345)).toBe(eur(1235))
		expect(cellText(t, null, null)).toBe('—')
		expect(cellText(t, undefined, undefined)).toBe('—')
	})
})
