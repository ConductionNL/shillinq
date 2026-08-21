/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Budget grid — Playwright UI shell-smoke for the `budget-grid-view` change
 * (REQ-BGV-001..009), the user-facing centrepiece of the begroting
 * programme: verzamelpost rows expand to child groups or resolved grootboek
 * accounts, past columns show actuals + a text-labelled deviation from
 * budget, a grootboek row drills through to ChartOfAccountsDetail, and the
 * expand/collapse toggle is keyboard-operable (ADR-059).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only, per `BudgetLineCommitments.vue`'s own header comment). The
 * deeper guarantees are owned by other layers and referenced here so each
 * Scenario carries an @e2e proof —
 *   - the row tree / column generation / past-boundary / query-budget logic
 *     lives in `tests/Unit/Service/BudgetGridReaderTest.php` (PHPUnit);
 *   - the accountType-driven sign convention, cumulative TOTAAL pair, and
 *     computed-row formula evaluator live in
 *     `tests/Unit/Service/BudgetGridCalculatorTest.php` (PHPUnit);
 *   - the client-side tree-flatten (zero-additional-query expand/collapse)
 *     and amount-formatting helpers live in
 *     `tests/vitest/budgetGridHelpers.spec.js` (vitest).
 *
 * Data-defensive: when no LedgerGroup/BudgetLine/posted GLTransaction+GLLine/
 * FiscalPeriod seed data exists for the current administration, the affected
 * assertions are skipped with a POLLED (not immediate) probe — see
 * `becomes-visible.js`'s own header comment on why an immediate
 * `isVisible()` check produces a skip reason that lies. This spec never
 * seeds or asserts against `TrialBalanceLine` — that schema has no
 * persisted rows (design.md §0/§1c amendment); actuals come from
 * GLTransaction/GLLine/Account.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'
const ROUTE = '/begroting/grid'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('budget-grid-view — the year-basis begroting grid', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('grid-renders-rows-and-columns: root LedgerGroup rows and the month + TOTAAL columns render (REQ-BGV-001, REQ-BGV-002)', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const row = page.getByTestId('budget-grid-row').first()
		const rendered = await becomesVisible(row)
		test.skip(!rendered, 'no LedgerGroup seeded for this administration')

		const totalHeader = page.locator(
			'[data-testid="budget-grid-column-header"][data-testid-total="budget-grid-total-column"]',
		)
		await expect(totalHeader).toBeVisible()

		const columnHeaders = page.getByTestId('budget-grid-column-header')
		await expect(columnHeaders.first()).toBeVisible()
	})

	test('verzamelpost-expand-reveals-children: expanding a root row reveals its child rows without a page reload (REQ-BGV-002)', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const toggle = page.getByTestId('budget-grid-expand-toggle').first()
		const hasToggle = await becomesVisible(toggle)
		test.skip(
			!hasToggle,
			'no expandable LedgerGroup row seeded for this administration',
		)

		const rowsBefore = await page.getByTestId('budget-grid-row').count()
		await toggle.click()

		await expect
			.poll(async () => page.getByTestId('budget-grid-row').count())
			.toBeGreaterThan(rowsBefore)

		await expect(toggle).toHaveAttribute('aria-expanded', 'true')
	})

	test('expand-keyboard-operable: the toggle fires on Enter and Space via keyboard focus, not only pointer click (ADR-059)', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const toggle = page.getByTestId('budget-grid-expand-toggle').first()
		const hasToggle = await becomesVisible(toggle)
		test.skip(
			!hasToggle,
			'no expandable LedgerGroup row seeded for this administration',
		)

		await toggle.focus()
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')

		await page.keyboard.press('Enter')
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')

		await page.keyboard.press('Enter')
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')

		await page.keyboard.press('Space')
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')
	})

	test('grootboek-drill-through-navigates: clicking a resolved Account leaf row navigates to ChartOfAccountsDetail (REQ-BGV-007)', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		// Expand every toggle-able row once to reveal any leaf Account rows
		// (a leaf LedgerGroup shows its resolved accounts directly under it).
		const toggles = page.getByTestId('budget-grid-expand-toggle')
		const toggleCount = await toggles.count()
		for (let i = 0; i < toggleCount; i++) {
			await toggles
				.nth(i)
				.click()
				.catch(() => {})
		}

		const accountLink = page.getByTestId('budget-grid-account-link').first()
		const hasAccountLink = await becomesVisible(accountLink)
		test.skip(
			!hasAccountLink,
			'no leaf LedgerGroup with resolved Account members seeded for this administration',
		)

		await accountLink.click()
		await expect(page).toHaveURL(/\/chart-of-accounts\//)
	})

	test('past-column-shows-actuals-and-deviation: a closed-period column shows an actual amount and a text-labelled deviation, not budget alone (REQ-BGV-003, REQ-BGV-004)', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const pastCell = page.locator('.budget-grid-cell__deviation').first()
		const hasPastColumn = await becomesVisible(pastCell)
		test.skip(
			!hasPastColumn,
			'no closed FiscalPeriod with posted GLTransaction/GLLine activity seeded for this administration',
		)

		// Text-labelled, not colour alone (WCAG 2.1 AA) — "Favorable:" or
		// "Unfavorable:" accompanies the signed amount.
		const text = await pastCell.innerText()
		expect(text).toMatch(/Favorable|Unfavorable/)

		const actualLine = page.locator('.budget-grid-cell__actual').first()
		await expect(actualLine).toBeVisible()
	})
})
