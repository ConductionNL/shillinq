/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — budget-known-costs.
 *
 * Covers the three browser-visible scenarios `design.md` §11 names for this
 * change's derived-`BudgetLine` surface: a `CashflowRecurring` row (no
 * `contractReference`) deriving a `source: "recurring"` line, a
 * `contractReference`-tagged row deriving a `source: "contract"` line, and
 * the `BudgetLineDerivationDetail` audit trail listing its contributing
 * `CashflowRecurring` row(s) and `lastGeneratedAt`. The `BudgetLines`/
 * `BudgetLineDerivations` index/detail pages ship with NO custom
 * `component` (see `src/manifest.d/budget-known-costs.json` and
 * `budget-core-schema.json`), so they render through the generic manifest
 * renderer (`cn-index-page`/`cn-detail-page` testids), the same
 * `budget-core-schema.spec.ts` convention this file follows.
 *
 * ## Why every scenario here is data-defensive
 *
 * `KnownCostBudgetWriter` (this change's own orchestrator) is
 * OPERATOR-TRIGGERED, not run automatically on import or on
 * `CashflowRecurring` save (`design.md` §12 — a scheduled/cron re-run is an
 * explicitly open, undecided question, §13.4). This change ships no
 * controller/route/command that fires it from the UI. So a fresh
 * administration has ZERO `source: "contract"`/`source: "recurring"`
 * `BudgetLine` rows and zero `BudgetLineDerivation` rows until an operator
 * (or a future change) runs the writer through whatever mechanism ends up
 * exposing it — every scenario below therefore skips honestly, with a
 * TRUE reason (`becomesVisible`'s own polling probe, never the
 * non-polling `isVisible()` anti-pattern this file's own precedents warn
 * against), rather than asserting against a regeneration this test cannot
 * itself trigger.
 *
 * The arithmetic (`KnownCostScheduleExpander`), the idempotent/override
 * orchestration (`KnownCostBudgetWriter`), the query budget
 * (`KnownCostReader`), and the extended `CashflowRecurringGuard` check are
 * all backend-only / no browser-visible surface, and are `@e2e exclude`d
 * per `specs/budget-known-costs/spec.md` — covered instead by
 * `KnownCostScheduleExpanderTest`, `KnownCostBudgetWriterTest`,
 * `KnownCostReaderTest`, `CashflowRecurringGuardTest` (PHPUnit).
 *
 * Spec scenarios covered:
 *   - A recurring cost with no contract derives a source: "recurring" line
 *   - A contract-linked recurring cost derives a source: "contract" line
 *   - The derivation detail page lists the contributing recurring cost(s)
 *
 * @e2e budget-known-costs::recurring-cost-derives-budget-line
 * @e2e budget-known-costs::contract-linked-cost-tags-source-contract
 * @e2e budget-known-costs::derivation-audit-trail-visible
 *
 * NOT EXECUTED as part of this change's own implementation pass (per the
 * implementer's brief) — written against the confirmed generic-renderer
 * testid/`gotoRoute` conventions `budget-core-schema.spec.ts` already
 * establishes, and should be spot-checked against a live run, with a
 * writer-run actually performed first (via whatever trigger mechanism a
 * future change exposes), before this change ships.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'
const BUDGET_LINES_ROUTE = '/begroting/budget-lines'
const BUDGET_LINE_DERIVATIONS_ROUTE = '/begroting/derivations'

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Strip `/index.php`, query and hash, and any trailing slash. */
function normalisePath(urlOrPath: string): string {
	const path = urlOrPath.startsWith('http')
		? new URL(urlOrPath).pathname
		: urlOrPath.split(/[?#]/)[0]
	return path.replace('/index.php', '').replace(/\/+$/, '') || '/'
}

/**
 * Deep-link to a manifest route and prove the SPA resolved it (rather than
 * falling through to the `/:pathMatch(.*)*` catch-all redirect to
 * Dashboard) — the `budget-core-schema.spec.ts`/`provincies-bbv-variant.spec.ts`
 * `gotoRoute()` precedent.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	const target = APP + route
	await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	expect(
		normalisePath(page.url()),
		`route ${route} must be declared by the manifest and resolve to itself`,
	).toBe(normalisePath(target))
}

test.describe('budget-known-costs — recurring cost derives a BudgetLine (REQ-BKC-001)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-known-costs::recurring-cost-derives-budget-line
	 *
	 * After a `CashflowRecurring` row with no `contractReference` has been
	 * expanded by a `KnownCostBudgetWriter` run, the `BudgetLines` index
	 * shows a `source: "recurring"` row with the expected monthly amount
	 * (design.md §11 scenario 1).
	 */
	test('BudgetLines index shows a source: "recurring" row after a writer run', async ({
		page,
	}) => {
		await gotoRoute(page, BUDGET_LINES_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const recurringRow = page.getByRole('row', { name: /recurring/i })
		const opened = await becomesVisible(recurringRow, 5_000)
		test.skip(
			!opened,
			'no source: "recurring" BudgetLine present — KnownCostBudgetWriter has not been run against this administration (operator-triggered, design.md §12)',
		)

		await recurringRow.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByText('recurring', { exact: false }).first(),
		).toBeVisible()
	})
})

test.describe('budget-known-costs — contract-linked cost tags source: "contract" (REQ-BKC-001)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-known-costs::contract-linked-cost-tags-source-contract
	 *
	 * A `CashflowRecurring` row with `contractReference` set derives a
	 * `BudgetLine` with `source: "contract"`, distinguishable from the
	 * `"recurring"` case (design.md §11 scenario 2).
	 */
	test('BudgetLines index shows a source: "contract" row after a writer run', async ({
		page,
	}) => {
		await gotoRoute(page, BUDGET_LINES_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const contractRow = page.getByRole('row', { name: /contract/i })
		const opened = await becomesVisible(contractRow, 5_000)
		test.skip(
			!opened,
			'no source: "contract" BudgetLine present — no CashflowRecurring row with contractReference has been regenerated in this administration',
		)

		await contractRow.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByText('contract', { exact: false }).first(),
		).toBeVisible()
	})
})

test.describe('budget-known-costs — derivation audit trail (REQ-BKC-009)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-known-costs::derivation-audit-trail-visible
	 *
	 * The `BudgetLineDerivations` index is reachable from the `Budgets` nav
	 * group's leaves, and a derivation's detail page lists its contributing
	 * `CashflowRecurring` `recurId`(s) and `lastGeneratedAt` (design.md §11
	 * scenario 3 / REQ-BKC-009's own scenario).
	 */
	test("BudgetLineDerivations index resolves and a row's detail page lists its contributing recurring cost(s)", async ({
		page,
	}) => {
		await gotoRoute(page, BUDGET_LINE_DERIVATIONS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const row = page.locator('table tbody tr').first()
		const opened = await becomesVisible(row, 5_000)
		test.skip(
			!opened,
			'no BudgetLineDerivation present — KnownCostBudgetWriter has not been run against this administration (operator-triggered, design.md §12)',
		)

		await row.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})

		await expect(
			page.getByText(/Contributing recurring costs/i).first(),
			'the contributingRecurIds field must be present on the derivation detail page',
		).toBeVisible({ timeout: 10_000 })
		await expect(
			page.getByText(/Last generated at/i).first(),
			'lastGeneratedAt must be shown on the derivation detail page',
		).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e budget-known-costs::derivation-audit-trail-visible
	 *
	 * The `Derivations` leaf this change adds to the `Budgets` group is
	 * reachable from the navigation, alongside `budget-core-schema`'s own
	 * three leaves — the either-order nav convention (design.md §10).
	 */
	test('Derivations nav leaf is reachable under Banking & Cashflow', async ({
		page,
	}) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		const bankingCashflow = page.getByRole('link', {
			name: /Banking.*Cashflow/i,
		})
		await expect(bankingCashflow.first()).toBeVisible({ timeout: 15_000 })

		// `src/menu-layout.json` relocations documents its own semantics:
		// "groups dissolve into the target, leaves move under it" — the
		// `Budgets` source group has no surviving group label to assert on
		// after `"Budgets": "BankingCashflow"` relocates it; only its leaves
		// are expected to remain reachable, flattened alongside
		// BankingCashflow's own children. This assertion used to read
		// `getByText('Budgets', { exact: true })`, which can NEVER match:
		// the design guarantees that DOM node does not exist. Also: the
		// top-level LINK navigates to the overview page rather than
		// expanding children — the dedicated toggle (`CnAppNav` stamps
		// `cn-nav-entry-<id>`, per chart-of-accounts.spec.ts's own
		// precedent) is what reveals them.
		await page
			.locator('[data-testid="cn-nav-entry-BankingCashflow"]')
			.getByRole('button', { name: /menu/i })
			.click()

		await expect(
			page.getByRole('link', { name: 'Derivations' }).first(),
			'the "Derivations" nav leaf must be reachable under Banking & Cashflow',
		).toBeVisible({ timeout: 10_000 })
	})
})
