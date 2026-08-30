/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Budget-line committed-vs-realised drilldown — Playwright UI shell-smoke
 * for the `verplichtingen-commitment-accounting` change (REQ-VPL-011).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only): the BudgetLineCommitments page renders the declarative
 * committed-vs-realised aggregation as a table with the four BBV columns
 * (Authorized/Committed/Realised/Available), and clicking a row expands a
 * drilldown listing the underlying commitments. The deeper guarantees are
 * owned by other layers and referenced here so the spec Scenario carries
 * an @e2e proof —
 *   - the materialisation glue (idempotency, budget/mandate guard reuse,
 *     multi-year rule splitting) lives in
 *     `tests/Unit/Service/Commitment/CommitmentMaterialisationServiceTest.php`
 *     and `tests/Unit/Listener/CommitmentMaterialisationListenerTest.php`
 *     (PHPUnit);
 *   - the declarative aggregation shape lives in
 *     `tests/Unit/Service/CommitmentAccountingFragmentTest.php`
 *     (PHPUnit);
 *   - the row-normalisation / vrij computation / drilldown-filter logic
 *     lives in `tests/vitest/budgetLineCommitmentsHelpers.spec.js` against
 *     the pure helpers in `src/views/budgetLineCommitmentsHelpers.js`.
 *
 * Data-defensive: when no CommitmentLine rows are seeded (fresh
 * administration) the table renders the empty state — the suite skips
 * the row-interaction assertions rather than failing.
 *
 * @spec openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011
 */

import { test, expect, type Page } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'
const ROUTE = '/commitments/budget-lines'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('verplichtingen-commitment-accounting — budget-line committed-vs-realised drilldown (REQ-VPL-011)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('renders the four committed-vs-realised columns and drills into a budget line', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const row = page.getByTestId('budget-line-row').first()
		const opened = await becomesVisible(row)
		test.skip(!opened, 'no CommitmentLine seeded in this administration')

		// The four BBV columns are text-labelled, not colour-coded only
		// (WCAG 2.1 AA per the spec's Non-Functional Requirements).
		await expect(page.getByText('Authorized', { exact: true })).toBeVisible()
		await expect(page.getByText('Committed', { exact: true })).toBeVisible()
		await expect(page.getByText('Realised', { exact: true })).toBeVisible()
		await expect(page.getByText('Available', { exact: true })).toBeVisible()

		// Drilling into a line lists its underlying Commitment(s).
		await row.click()
		await expect(page.getByTestId('budget-line-drilldown')).toBeVisible({
			timeout: 10_000,
		})
	})

	test('empty administration renders the empty state without erroring', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		// ⚠️ INVERTED GATE — this one skips when rows ARE present, so the
		// non-waiting probe failed in the direction that RUNS the test. On a
		// seeded administration it therefore asserted "No budget lines" against
		// a table that was merely still loading, and failed with a confusing
		// message instead of skipping honestly. Polling makes the skip correct.
		// 5s rather than the 10s default: the no-rows case is the common one and
		// this wait is paid in full every time it holds.
		const row = page.getByTestId('budget-line-row').first()
		const hasRows = await becomesVisible(row, 5_000)
		test.skip(
			hasRows,
			'administration has seeded budget lines — empty-state assertion not applicable',
		)

		await expect(
			page.getByText('No budget lines', { exact: false }),
		).toBeVisible()
	})
})
