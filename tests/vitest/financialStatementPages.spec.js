/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The profit and loss and balance sheet pages (reporting-financial-statements
 * tasks 2.1 and 2.2, REQ-RFS-001, REQ-RFS-002).
 *
 * Both pages are manifest configuration over the statements endpoints. This
 * test holds the fragment, the Reports cards, the route table, the
 * calculator's response keys and the Dutch catalogue to one contract, so a
 * renamed page, endpoint, response key or untranslated label fails here
 * instead of on a live instance.
 *
 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-2.1
 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-2.2
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '..', '..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/reporting-financial-statements.json'))
const manifest = JSON.parse(read('src/manifest.json'))
const routes = read('appinfo/routes.php')
const calculator = read('lib/Reporting/StatementCalculator.php')
const comparison = read('lib/Reporting/ProfitLossComparison.php')
const nl = JSON.parse(read('l10n/nl.json')).translations
const page = (id) => fragment.pages.find((candidate) => candidate.id === id)
const widget = (pageId, id) => page(pageId).config.widgets.find((candidate) => candidate.id === id)

/**
 * Every user-visible string of a page config.
 *
 * @param {object} node A config node.
 * @param {Set<string>} found The strings so far.
 * @return {Set<string>} The strings.
 */
function labels(node, found = new Set()) {
	if (Array.isArray(node)) {
		node.forEach((child) => labels(child, found))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (['label', 'title', 'description', 'emptyText'].includes(key) && typeof value === 'string') {
				found.add(value)
			} else {
				labels(value, found)
			}
		}
	}
	return found
}

describe('profit and loss page (REQ-RFS-001)', () => {
	const id = 'FinancialStatementProfitLoss'

	it('reads the range and both comparisons from the profit and loss endpoint', () => {
		expect(page(id).route).toBe('/reports/profit-and-loss')
		expect(page(id).config.dateRange.enabled).toBe(true)
		for (const entry of page(id).config.widgets) {
			expect(entry.content.endpointSource.url).toBe('/apps/shillinq/api/statements/profit-and-loss')
			expect(entry.content.endpointSource.params).toEqual({
				from: '@workspace.dateFrom?',
				to: '@workspace.dateTo?',
				compare: 'previous-period,previous-year',
			})
		}
		expect(routes).toMatch(/'financialStatement#profitAndLoss', 'url' => '\/api\/statements\/profit-and-loss', 'verb' => 'GET'/)
	})

	it('shows each account with the amount, both comparisons and their differences', () => {
		for (const section of ['revenue', 'expense']) {
			const table = widget(id, `profit-loss-${section}-accounts`)
			expect(table.content.endpointSource.responsePath).toBe(section)
			expect(table.content.columns.map((column) => column.key)).toEqual([
				'label',
				'amounts.current',
				'amounts.previous-period',
				'differences.previous-period',
				'amounts.previous-year',
				'differences.previous-year',
			])
		}
		expect(comparison).toMatch(/'revenue' => \$this->section\(/)
		expect(comparison).toMatch(/'expense' => \$this->section\(/)
		expect(comparison).toMatch(/'differences'\] = \$this->differences\(/)
	})

	it('shows the totals the comparison returns, with last year\'s result', () => {
		expect(widget(id, 'profit-loss-revenue').content.valueField).toBe('totals.revenue.amounts.current')
		expect(widget(id, 'profit-loss-expense').content.valueField).toBe('totals.expense.amounts.current')
		expect(widget(id, 'profit-loss-result').content.valueField).toBe('totals.result.amounts.current')
		expect(widget(id, 'profit-loss-result-last-year').content.valueField).toBe('totals.result.amounts.previous-year')
		expect(comparison).toMatch(/return \['amounts' => \$amounts, 'differences' =>/)
	})
})

describe('balance sheet page (REQ-RFS-002)', () => {
	const id = 'FinancialStatementBalanceSheet'

	it('reads the balance sheet as of the end date of the chosen range', () => {
		expect(page(id).route).toBe('/reports/balance-sheet')
		for (const entry of page(id).config.widgets) {
			expect(entry.content.endpointSource.url).toBe('/apps/shillinq/api/statements/balance-sheet')
			expect(entry.content.endpointSource.params).toEqual({ asOf: '@workspace.dateTo?' })
		}
		expect(routes).toMatch(/'financialStatement#balanceSheet', 'url' => '\/api\/statements\/balance-sheet', 'verb' => 'GET'/)
	})

	it('lists assets, liabilities and equity, and shows both sides so they can be seen to balance', () => {
		expect(['assets', 'liabilities', 'equity'].map((section) => widget(id, `balance-sheet-${section}-accounts`).content.endpointSource.responsePath))
			.toEqual(['assets', 'liabilities', 'equity'])
		expect(widget(id, 'balance-sheet-assets').content.valueField).toBe('totals.assets')
		expect(widget(id, 'balance-sheet-liabilities-equity').content.valueField).toBe('totals.liabilitiesAndEquity')
		expect(calculator).toMatch(/'liabilitiesAndEquity' => self::round\(/)
		expect(calculator).toMatch(/'equity' => \$sections\['equity'\]/)
	})
})

describe('both pages', () => {
	it('lay out every widget once', () => {
		for (const entry of fragment.pages) {
			expect(entry.config.layout.map((cell) => cell.widgetId).sort())
				.toEqual(entry.config.widgets.map((candidate) => candidate.id).sort())
		}
	})

	it('are opened from a card on the Reports page', () => {
		const cards = manifest.pages.find((candidate) => candidate.id === 'Reports').config.cards
		for (const id of ['FinancialStatementProfitLoss', 'FinancialStatementBalanceSheet']) {
			const card = cards.find((candidate) => candidate.id === id)
			expect([card.route, card.category]).toEqual([id, 'financial'])
			expect(nl[card.label]).toBeTruthy()
			expect(nl[card.description]).toBeTruthy()
		}
	})

	it('have a Dutch translation for every label', () => {
		const missing = [...labels(fragment.pages)].filter((label) => !nl[label])
		expect(missing).toEqual([])
	})
})
