/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — budget-core-schema.
 *
 * Covers the three browser-visible scenarios `design.md` §9 names for this
 * change's minimal index/detail pages (`AnnualBudgets`/`LedgerGroups`/
 * `BudgetLines`, all declared with NO custom `component` — see
 * `src/manifest.d/budget-core-schema.json` — so they render through the
 * GENERIC manifest renderer: `cn-index-page`/`cn-detail-page` testids, never
 * an app-invented one, per the `provincies-bbv-variant.spec.ts` precedent
 * this file follows):
 *
 *  - The `Budgets` top-level nav group is reachable and its three index
 *    pages resolve.
 *  - The seeded, P&L-shaped `LedgerGroup` rows (design.md §3c) are visible
 *    on the `LedgerGroups` index after a fresh import.
 *  - A `BudgetLine` detail page renders the 12 monthly amount fields and an
 *    operator-entered value persists (schema-shape smoke test only — the
 *    real grid UX is `budget-grid-view`'s scope).
 *
 * Everything else this change ships (the `Budget` schema-split rename, the
 * migrator, the `AnnualBudgetDefaultGuard` one-default invariant, the
 * `BudgetVsActualsReader`/`Calculator` roll-up, the §6a positive-control
 * findings) is `@e2e exclude`d — backend-only or platform-diagnostic, per
 * `specs/budget-core-schema/spec.md` and `design.md` §9 — and covered
 * instead by `BudgetSchemaSplitMigratorTest`, `AnnualBudgetDefaultGuardTest`,
 * `BudgetVsActualsReaderTest`/`CalculatorTest` (PHPUnit).
 *
 * Spec scenarios covered:
 *   - The Budgets navigation group and its pages are reachable
 *   - A fresh import ships day-one, P&L-shaped LedgerGroup data
 *   - A manually entered budget line persists its 12 monthly amounts
 *
 * @e2e budget-core-schema::budgets-nav-group-reachable
 * @e2e budget-core-schema::ledger-group-seeded-on-import
 * @e2e budget-core-schema::budget-line-monthly-columns-editable
 *
 * Authored defensively for dev-container topologies (per this file's own
 * `provincies-bbv-variant.spec.ts`/`invoice-quick-draft.spec.ts` precedent):
 * the LedgerGroup scenario reads seeded reference data that ships with every
 * import (design.md §3c — NOT operator-created, so it is asserted directly,
 * not behind a defensive skip). The BudgetLine create-and-persist scenario
 * IS behind a defensive skip: `AnnualBudget` carries no seed data by design
 * (design.md §10/§11 — this change ships no scenario/default-budget seed),
 * so a fresh install has zero `AnnualBudget`s to select when creating a
 * `BudgetLine`, and the test skips honestly rather than asserting against a
 * form that cannot be completed. NOT EXECUTED as part of this change's own
 * implementation pass (per the implementer's brief) — written against the
 * confirmed generic-renderer testid/`gotoRoute` conventions this file's own
 * precedents establish, but the exact form-field association for the 12
 * `monthNNAmount` inputs is asserted via accessible label text
 * (`page.getByLabel`), not a guessed app-invented testid, and should be
 * spot-checked against a live run before this change ships.
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const ANNUAL_BUDGETS_ROUTE = '/begroting/annual-budgets'
const LEDGER_GROUPS_ROUTE = '/begroting/ledger-groups'
const BUDGET_LINES_ROUTE = '/begroting/budget-lines'

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
 * falling through to `src/main.js`'s `/:pathMatch(.*)*` catch-all redirect
 * to Dashboard) — the `provincies-bbv-variant.spec.ts` `gotoRoute()`
 * precedent.
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

test.describe('budget-core-schema — Budgets nav group + index pages (REQ-BCS-009)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-core-schema::budgets-nav-group-reachable
	 *
	 * The `Budgets` top-level group (`src/manifest.d/budget-core-schema.json`)
	 * is relocated into the Cluster-4 `Banking & Cashflow` group via
	 * `src/menu-layout.json#relocations` (design.md §7b — done directly in
	 * this change rather than deferred, since `nav-six-clusters`/PR #923 had
	 * already merged by implementation time). The menu assertion opens
	 * Banking & Cashflow first, then Budgets, before checking each leaf link.
	 */
	test('Budgets nav group opens under Banking & Cashflow with its three leaves', async ({
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
		// after `"Budgets": "BankingCashflow"` relocates it; only its three
		// leaves are expected to remain reachable, flattened alongside
		// BankingCashflow's own children. Also: the top-level LINK navigates
		// to the overview page rather than expanding children — the
		// dedicated toggle (`CnAppNav` stamps `cn-nav-entry-<id>`, per
		// chart-of-accounts.spec.ts's own precedent) is what reveals them.
		await page
			.locator('[data-testid="cn-nav-entry-BankingCashflow"]')
			.getByRole('button', { name: /menu/i })
			.click()

		for (const label of ['Annual Budgets', 'Ledger Groups', 'Budget Lines']) {
			await expect(
				page.getByRole('link', { name: label }).first(),
				`nav leaf "${label}" must be reachable under Banking & Cashflow`,
			).toBeVisible({ timeout: 10_000 })
		}
	})

	/**
	 * @e2e budget-core-schema::budgets-nav-group-reachable
	 */
	test('AnnualBudgets index resolves and each row navigates to a working detail page', async ({
		page,
	}) => {
		await gotoRoute(page, ANNUAL_BUDGETS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const row = page.locator('table tbody tr').first()
		const hasRows = await row.isVisible().catch(() => false)
		test.skip(
			!hasRows,
			'no AnnualBudget seeded — this schema carries no seed data by design (design.md §10)',
		)

		await row.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e budget-core-schema::budgets-nav-group-reachable
	 */
	test('LedgerGroups index resolves and each row navigates to a working detail page', async ({
		page,
	}) => {
		await gotoRoute(page, LEDGER_GROUPS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const row = page.locator('table tbody tr').first()
		await expect(
			row,
			'the 19 seeded LedgerGroup rows must render (design.md §3c)',
		).toBeVisible({
			timeout: 15_000,
		})

		await row.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e budget-core-schema::budgets-nav-group-reachable
	 */
	test('BudgetLines index resolves', async ({ page }) => {
		await gotoRoute(page, BUDGET_LINES_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
	})
})

test.describe('budget-core-schema — LedgerGroup seeded data (REQ-BCS-005)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-core-schema::ledger-group-seeded-on-import
	 *
	 * design.md §3c: 19 rows total, seeded from `rj270-pl.json` (P&L-shaped),
	 * NOT `rj270-balance-sheet.json`. Asserted directly (not behind a
	 * defensive skip) because this is anchor reference data every import
	 * ships, not operator-entered data.
	 */
	test('P&L-shaped LedgerGroup rows are present, and no balance-sheet row is', async ({
		page,
	}) => {
		await gotoRoute(page, LEDGER_GROUPS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const body = page.locator('#app-content-vue, main').first()
		for (const label of ['Omzet', 'Personeel', 'Huisvestingskosten']) {
			await expect(
				body.getByText(label, { exact: false }).first(),
				`seeded LedgerGroup "${label}" must be visible on a fresh import`,
			).toBeVisible({ timeout: 15_000 })
		}

		// design.md §3c: THIS capability's 19-row seed is P&L-shaped (sourced
		// from `rj270-pl.json`), so it contributes no balance-sheet section.
		//
		// "Liquide middelen" (cash) is deliberately NOT asserted here any more.
		// budget-scenarios legitimately seeds a cash LedgerGroup of that name
		// (REQ-BSC-009, `register.d/budget-scenarios.json`) so its
		// LEDGER_AMOUNT_DELTA modifier has an account to target — the product
		// requires scenarios that move money to the bank on a given date, which
		// is inherently a balance-sheet effect.
		//
		// The original assertion claimed "no balance-sheet row may exist
		// ANYWHERE in the register", which is a stronger proposition than
		// design.md §3c actually makes. Once another capability legitimately
		// seeds one, that assertion tests the wrong thing and fails on a
		// correct system. "Voorraden" (stock) stays asserted: nothing seeds it,
		// so it still witnesses that this capability's own seed is P&L-shaped.
		await expect(
			body.getByText('Voorraden', { exact: true }),
			'balance-sheet row "Voorraden" must NOT be seeded by budget-core-schema (design.md §3c)',
		).toHaveCount(0)
	})

	/**
	 * @e2e budget-core-schema::ledger-group-seeded-on-import
	 *
	 * REQ-BCS-004 scenario: a nested LedgerGroup is reachable from its
	 * parent's detail page — `Personeel` (parent) lists `Lonen en
	 * salarissen`/`Sociale lasten en pensioenlasten` (children) via the
	 * `children` relatedCollection (`parentLedgerGroupId`). The manifest key
	 * is `relatedCollections` — `relatedLists` is not a prop on any Cn page
	 * component, so it lands in `$attrs` and renders nothing while every
	 * gate stays green.
	 */
	test("Personeel's detail page lists its two seeded children", async ({
		page,
	}) => {
		await gotoRoute(page, LEDGER_GROUPS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const body = page.locator('#app-content-vue, main').first()
		const personeelRow = body.getByText('Personeel', { exact: true }).first()
		// `isVisible()` is a POINT-IN-TIME probe with no auto-wait, and
		// `cn-index-page` mounts BEFORE its rows arrive. Probing straight
		// after the mount therefore races the row load, and the defensive
		// skip below fires on a page that would have rendered the row a
		// moment later — observed self-skipping 3 of 4 runs. A skip and a
		// pass are indistinguishable in the summary line, so this raced
		// itself into looking green. Wait for the row explicitly first; the
		// skip then means what it says (genuinely absent seed data).
		await personeelRow
			.waitFor({ state: 'visible', timeout: 15_000 })
			.catch(() => {})
		const found = await personeelRow.isVisible().catch(() => false)
		test.skip(
			!found,
			'seeded "Personeel" LedgerGroup row not found on this index',
		)

		await personeelRow.click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})

		const detailBody = page.locator('#app-content-vue, main').first()
		for (const child of [
			'Lonen en salarissen',
			'Sociale lasten en pensioenlasten',
		]) {
			await expect(
				detailBody.getByText(child, { exact: false }).first(),
				`child LedgerGroup "${child}" must be listed on Personeel's detail page`,
			).toBeVisible({ timeout: 15_000 })
		}
	})
})

test.describe('budget-core-schema — BudgetLine 12 monthly columns (REQ-BCS-007)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-core-schema::budget-line-monthly-columns-editable
	 *
	 * REQ-BCS-007 scenario: an operator creates a BudgetLine, enters values
	 * for each of the 12 monthly fields, saves, and the detail page renders
	 * all 12 values unchanged on reload, with `source: "manual"`.
	 *
	 * Data-defensive: `AnnualBudget` ships with no seed data (design.md §10),
	 * so a fresh install has none to select — the test skips honestly rather
	 * than asserting against a create form it cannot complete.
	 */
	test('a manually entered BudgetLine persists its 12 monthly amounts on reload', async ({
		page,
	}) => {
		await gotoRoute(page, BUDGET_LINES_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const createBtn = page.getByRole('button', { name: /create|add|new/i })
		const hasCreate = await createBtn
			.first()
			.isVisible()
			.catch(() => false)
		test.skip(!hasCreate, 'no create affordance found on the BudgetLines index')
		await createBtn.first().click()

		const annualBudgetField = page.getByLabel(/annual budget/i)
		const ledgerGroupField = page.getByLabel(/ledger group/i)

		// A visible field only means the form renders — it says nothing about
		// whether the field has any selectable options. AnnualBudget ships
		// with no seed data (design.md §10), so the field is present but its
		// option list is empty on a fresh install; open it and check for an
		// actual option before deciding whether to skip.
		await annualBudgetField.click()
		const hasAnnualBudgetOption = await page
			.getByRole('option')
			.first()
			.isVisible({ timeout: 3_000 })
			.catch(() => false)
		test.skip(
			!hasAnnualBudgetOption,
			'no AnnualBudget option available — this schema ships with no seed data by design (design.md §10)',
		)

		await page.getByRole('option').first().click()
		await ledgerGroupField.click()
		await page.getByRole('option').first().click()

		const monthValues: Record<string, string> = {
			Jan: '10000',
			Feb: '20000',
			Mar: '30000',
			Apr: '40000',
			May: '50000',
			Jun: '60000',
			Jul: '70000',
			Aug: '80000',
			Sep: '90000',
			Oct: '100000',
			Nov: '110000',
			Dec: '120000',
		}
		for (const [label, value] of Object.entries(monthValues)) {
			await page.getByLabel(label, { exact: true }).fill(value)
		}

		await page.getByRole('button', { name: /save/i }).click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})

		await page.reload()
		await page.waitForLoadState('domcontentloaded')
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})

		for (const [label, value] of Object.entries(monthValues)) {
			await expect(
				page.getByText(value, { exact: false }).first(),
				`monthly amount for ${label} (${value}) must persist unchanged on reload`,
			).toBeVisible({ timeout: 10_000 })
		}
	})
})
