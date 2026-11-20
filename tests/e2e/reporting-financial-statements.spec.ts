/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the profit and loss and the balance sheet
 * (reporting-financial-statements task 4.1, REQ-RFS-001, REQ-RFS-002,
 * REQ-RFS-003).
 *
 *   1. compare: the profit and loss endpoint the page reads answers the
 *      current range beside the previous period and the same period last
 *      year, and every difference is the current amount minus the compared
 *      one; the page opens from its Reports card and shows the comparison
 *      columns (REQ-RFS-001);
 *   2. as-of date: the balance sheet on a date balances (assets equal
 *      liabilities plus equity), carries the running result line while the
 *      year is open, and a date before the first posting is empty
 *      (REQ-RFS-002);
 *   3. click a figure: an account figure opens the ledger lines of that
 *      account in the figure's range (REQ-RFS-003). Fixme until task 2.3
 *      adds the `LedgerLinesForAccount` page.
 *
 * Invariants are asserted on whatever the instance's administration holds,
 * so the spec needs no seeded ledger. The caller is the suite's
 * administrator (globalSetup's storage state). The spec writes nothing.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/reporting-financial-statements/specs/bookkeeping-financial-statements/spec.md
 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-4.1
 *
 * @e2e bookkeeping-financial-statements::a-director-compares-september-year-to-date
 * @e2e bookkeeping-financial-statements::a-balance-sheet-at-the-end-of-september
 * @e2e bookkeeping-financial-statements::a-posting-after-the-date-is-left-out
 * @e2e bookkeeping-financial-statements::a-bookkeeper-checks-the-housing-costs
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api/statements'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One compared account row of the profit and loss. */
interface ComparedRow {
	accountNumber: string
	label: string
	amounts: Record<string, number>
	differences: Record<string, number>
	ranges: Record<string, { from: string, to: string }>
}

/** The part of the profit and loss answer this spec reads. */
interface ProfitLossAnswer {
	columns: Array<{ key: string, from: string, to: string }>
	revenue: ComparedRow[]
	expense: ComparedRow[]
	totals: Record<'revenue' | 'expense' | 'result', { amounts: Record<string, number>, differences: Record<string, number> }>
}

/** One balance row. */
interface BalanceRow {
	accountNumber: string | null
	label: string
	amount: number
}

/** The part of the balance sheet answer this spec reads. */
interface BalanceSheetAnswer {
	asOf: string
	yearClosed: boolean
	assets: BalanceRow[]
	liabilities: BalanceRow[]
	equity: BalanceRow[]
	totals: { assets: number, liabilities: number, equity: number, liabilitiesAndEquity: number }
}

/** The last twelve months, as the pages' default preset. */
function lastYear(): { from: string, to: string } {
	const to = new Date()
	const from = new Date(to.getTime() - 365 * 24 * 3600 * 1000)
	const day = (d: Date) => d.toISOString().slice(0, 10)
	return { from: day(from), to: day(to) }
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('reporting-financial-statements: profit and loss and balance sheet', () => {
	test('the profit and loss compares the range with the previous period and last year @spec REQ-RFS-001', async ({ request }) => {
		const { from, to } = lastYear()
		const res = await request.get(`${API}/profit-and-loss?from=${from}&to=${to}&compare=previous-period,previous-year`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json() as ProfitLossAnswer

		expect(body.columns.map((column) => column.key)).toEqual(['current', 'previous-period', 'previous-year'])
		for (const row of [...body.revenue, ...body.expense]) {
			for (const kind of ['previous-period', 'previous-year']) {
				expect(cents(row.differences[kind]), `${row.label}: the ${kind} difference`).toBe(cents(row.amounts.current) - cents(row.amounts[kind]))
			}
		}

		for (const column of body.columns) {
			const revenue = body.revenue.reduce((sum, row) => sum + cents(row.amounts[column.key]), 0)
			const expense = body.expense.reduce((sum, row) => sum + cents(row.amounts[column.key]), 0)
			expect(revenue, `${column.key}: revenue rows add up`).toBe(cents(body.totals.revenue.amounts[column.key]))
			expect(expense, `${column.key}: expense rows add up`).toBe(cents(body.totals.expense.amounts[column.key]))
			expect(revenue - expense, `${column.key}: the result is revenue minus expenses`).toBe(cents(body.totals.result.amounts[column.key]))
		}
	})

	test('the profit and loss page opens from Reports and shows the comparison columns @spec REQ-RFS-001', async ({ page }) => {
		await page.goto(APP + '/reports')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		await page.getByText(/^(Profit and loss|Winst en verlies)$/i).first().click()
		await expect(page).toHaveURL(/\/reports\/profit-and-loss/)
		for (const header of [/Previous period|Vorige periode/i, /Same period last year|Dezelfde periode vorig jaar/i, /Difference with last year|Verschil met vorig jaar/i]) {
			await expect(page.getByText(header).first(), `column ${header}`).toBeVisible({ timeout: 20_000 })
		}
	})

	test('the balance sheet balances on its date and carries the running result @spec REQ-RFS-002', async ({ request }) => {
		const { to } = lastYear()
		const res = await request.get(`${API}/balance-sheet?asOf=${to}`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json() as BalanceSheetAnswer

		expect(body.asOf).toBe(to)
		expect(cents(body.totals.assets), 'assets equal liabilities plus equity').toBe(cents(body.totals.liabilitiesAndEquity))
		expect(cents(body.totals.liabilities) + cents(body.totals.equity)).toBe(cents(body.totals.liabilitiesAndEquity))
		if (body.yearClosed === false) {
			expect(body.equity.some((row) => /Resultaat lopend boekjaar/.test(row.label)), 'the running result is an equity line').toBe(true)
		}
	})

	test('a date before any posting leaves every later posting out @spec REQ-RFS-002', async ({ request }) => {
		const res = await request.get(`${API}/balance-sheet?asOf=1990-01-01`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json() as BalanceSheetAnswer

		expect(cents(body.totals.assets)).toBe(0)
		expect(cents(body.totals.liabilitiesAndEquity)).toBe(0)
	})

	test('the balance sheet page opens from Reports @spec REQ-RFS-002', async ({ page }) => {
		await page.goto(APP + '/reports')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		await page.getByText(/^(Balance sheet|Balans)$/i).first().click()
		await expect(page).toHaveURL(/\/reports\/balance-sheet/)
		for (const title of [/^(Assets|Activa)$/i, /^(Liabilities|Passiva)$/i, /^(Equity|Eigen vermogen)$/i]) {
			await expect(page.getByText(title).first(), `widget ${title}`).toBeVisible({ timeout: 20_000 })
		}
	})

	test.fixme('an account figure opens the ledger lines behind it @spec REQ-RFS-003', async ({ page }) => {
		// Task 2.3: the LedgerLinesForAccount page (/ledger/lines/:accountNumber/:from/:to)
		// and the row links from both statements do not exist yet.
		await page.goto(APP + '/reports/profit-and-loss')
		await expect(page).toHaveURL(/\/ledger\/lines\//)
	})
})
