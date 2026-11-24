/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the cash flow statement (reporting-cash-flow-statement task
 * 4.1, REQ-RCF-002, REQ-RCF-003).
 *
 *   1. the statement reconciles: operating + investing + financing + the
 *      unclassified line equals the change in cash, a non-zero difference is
 *      shown as its own "Verschil" summary line, and every unclassified row
 *      names its account (REQ-RCF-002). Asserted on the endpoint the page
 *      reads, for whatever the instance's administration holds, so the
 *      invariant is checked without seeding a ledger;
 *   2. the page opens from its Reports route, shows the summary and the three
 *      activity sections over the endpoint, and its header action opens the
 *      check of accounts without a category (REQ-RCF-001, REQ-RCF-003);
 *   3. a figure is a link: clicking a row opens `CashFlowLines` for that
 *      figure's `linesKey`, and the lines endpoint behind that page adds up to
 *      the figure. A period with no rows skips that step and says so.
 *
 * The caller is the suite's administrator (globalSetup's storage state). The
 * spec writes nothing.
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement/specs/bookkeeping-financial-statements/spec.md
 * @spec openspec/changes/archive/2026-11-24-reporting-cash-flow-statement/tasks.md
 *
 * @e2e bookkeeping-financial-statements::a-director-reads-september-year-to-date
 * @e2e bookkeeping-financial-statements::an-unclassified-account-shows-as-a-difference
 * @e2e bookkeeping-financial-statements::a-director-checks-the-investments
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const ROUTE = '/reports/cash-flow'
const API = '/index.php/apps/shillinq/api/statements/cash-flow'

/** One row of a cash flow section. */
interface CashFlowRow {
	accountNumber: string | null
	accountId?: string
	linesKey?: string
	label: string
	amount: number
}

/** The part of the endpoint's answer this spec reads. */
interface CashFlowAnswer {
	method: string
	operating: { rows: CashFlowRow[], total: number }
	investing: { rows: CashFlowRow[], total: number }
	financing: { rows: CashFlowRow[], total: number }
	unclassified: { rows: CashFlowRow[], total: number }
	summary: Array<{ label: string, amount: number }>
	totals: { netCashFlow: number, cashChange: number, difference: number }
}

/** The last twelve months, as the page's default preset. */
function lastYear(): { from: string, to: string } {
	const to = new Date()
	const from = new Date(to.getTime() - 365 * 24 * 3600 * 1000)
	const day = (d: Date) => d.toISOString().slice(0, 10)
	return { from: day(from), to: day(to) }
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

test.describe('reporting-cash-flow-statement: the cash flow statement', () => {
	test('the statement reconciles to the change in cash @spec REQ-RCF-002', async ({ request }) => {
		const { from, to } = lastYear()
		const res = await request.get(`${API}?from=${from}&to=${to}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json() as CashFlowAnswer

		expect(body.method).toBe('indirect')
		const sections = ['operating', 'investing', 'financing', 'unclassified'] as const
		for (const name of sections) {
			const rows = body[name].rows
			const sum = rows.reduce((total, row) => total + cents(row.amount), 0)
			expect(sum, `${name}: its rows add up to its total`).toBe(cents(body[name].total))
		}

		const explained = sections.reduce((total, name) => total + cents(body[name].total), 0)
		expect(explained, 'the sections, the unclassified line included, add up to the net cash flow').toBe(cents(body.totals.netCashFlow))
		expect(cents(body.totals.cashChange) - explained, 'what the sections leave unexplained is the difference').toBe(cents(body.totals.difference))

		const difference = body.summary.find((line) => line.label === 'Verschil')
		if (cents(body.totals.difference) === 0) {
			expect(difference, 'no difference line when the statement reconciles').toBeUndefined()
		} else {
			expect(cents(difference?.amount ?? 0)).toBe(cents(body.totals.difference))
		}

		for (const row of body.unclassified.rows) {
			expect(row.accountNumber, `the unclassified line "${row.label}" names its account`).toBeTruthy()
		}
	})

	test('the page shows the statement and its account check @spec REQ-RCF-003', async ({ page }) => {
		await page.goto(APP + ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		expect(page.url()).toContain(APP + ROUTE)
		await expect(page.getByRole('heading', { name: /Cash flow statement|Kasstroomoverzicht/i }).first()).toBeVisible({ timeout: 20_000 })
		for (const title of [/^(Cash flow|Kasstroom)$/i, /Operating activities|Operationele activiteiten/i, /Investing activities|Investeringsactiviteiten/i, /Financing activities|Financieringsactiviteiten/i]) {
			await expect(page.getByText(title).first(), `widget ${title}`).toBeVisible()
		}

		await page.getByRole('button', { name: /Accounts without a category|Rekeningen zonder categorie/i }).first().click()
		await expect(page).toHaveURL(/\/reports\/cash-flow\/accounts/)
	})

	test('a figure opens the ledger lines behind it @spec REQ-RCF-003', async ({ page, request }) => {
		const { from, to } = lastYear()
		const res = await request.get(`${API}?from=${from}&to=${to}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.status()).toBe(200)
		const body = await res.json() as CashFlowAnswer
		const row = [...body.operating.rows, ...body.investing.rows, ...body.financing.rows]
			.find((candidate) => (candidate.linesKey ?? '') !== '' && candidate.amount !== 0)
		test.skip(row === undefined, 'the period holds no cash flow figure, so there is nothing to click')

		const lines = await request.get(`${API}/lines?key=${encodeURIComponent(row!.linesKey!)}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(lines.status(), await lines.text()).toBe(200)
		const found = await lines.json() as { lines: Array<{ amount: number }>, total: number }
		expect(cents(found.total), `the lines of "${row!.label}" add up to its figure`).toBe(cents(row!.amount))
		expect(cents(found.lines.reduce((sum, line) => sum + line.amount, 0))).toBe(cents(row!.amount))

		await page.goto(APP + ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		const figure = page.getByRole('row', { name: new RegExp(row!.label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first()
		await expect(figure).toBeVisible({ timeout: 20_000 })
		await figure.click()
		await expect(page).toHaveURL(/\/reports\/cash-flow\/lines\//)
		await expect(page.getByText(/Ledger lines|Grootboekregels/i).first()).toBeVisible({ timeout: 20_000 })
	})
})
