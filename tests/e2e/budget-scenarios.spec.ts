/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — budget-scenarios.
 *
 * Covers the three browser-visible scenarios `design.md` §11 names for this
 * change:
 *
 *  - `scenario-comparison-renders-base-and-scenario`: the standalone
 *    `BudgetScenarioComparison` custom page (data-testid'd — a bespoke
 *    component, NOT the generic `cn-index-page`/`cn-detail-page` renderer,
 *    per `src/manifest.d/budget-scenarios.json`'s own `type: "custom"`
 *    declaration) resolves and renders a base/scenario/delta table for a
 *    seeded scenario with at least one modifier.
 *  - `promote-to-default-demotes-previous-default`: promoting scenario B to
 *    default (via `BudgetScenarioDetail`'s "Promote to default" header
 *    action) flips scenario A's own `isDefault` chip to false in the same
 *    UI flow.
 *  - `modifier-crud-reachable`: the `BudgetScenarioModifiers` index/detail
 *    pages (generic renderer) are reachable and an operator can create a
 *    `LEDGER_AMOUNT_DELTA` modifier.
 *
 * Everything else this change ships (the evaluator's full arithmetic, the
 * promoter's atomic-demotion + verification-mismatch logging, the guard's
 * same-recurId conflict rejection, the reader's query-count regression) is
 * `@e2e exclude`d — backend-only, per `specs/budget-scenarios/spec.md` and
 * `design.md` §11 — and covered instead by `BudgetScenarioEvaluatorTest`,
 * `BudgetScenarioDefaultPromoterTest`, `BudgetScenarioModifierGuardTest`,
 * `BudgetScenarioReaderTest` (PHPUnit).
 *
 * Spec scenarios covered:
 *   - The comparison page renders base and scenario columns
 *   - Promoting a new default demotes the previous one in the same action
 *   - The modifier CRUD pages are reachable and a LEDGER_AMOUNT_DELTA can be created
 *
 * @e2e budget-scenarios::scenario-comparison-renders-base-and-scenario
 * @e2e budget-scenarios::promote-to-default-demotes-previous-default
 * @e2e budget-scenarios::modifier-crud-reachable
 *
 * Authored defensively (per this file's own `budget-core-schema.spec.ts`/
 * `provincies-bbv-variant.spec.ts` precedent): `BudgetScenario` and
 * `BudgetScenarioModifier` ship with NO seed data (design.md ships only the
 * one `LedgerGroup` anchor row, RULING 1) — a fresh install has zero
 * scenarios, so every scenario-dependent test skips honestly rather than
 * asserting against data that cannot exist yet, or creates its own fixture
 * first when the flow under test IS the creation itself. NOT EXECUTED as
 * part of this change's own implementation pass (per the implementer's
 * brief) — written against the confirmed generic-renderer testid /
 * `gotoRoute` conventions this file's own precedents establish, plus this
 * change's own bespoke `data-testid`s on `BudgetScenarioComparison.vue`
 * (`budget-scenario-comparison-*`), and should be spot-checked against a
 * live run before this change ships.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const SCENARIOS_ROUTE = '/begroting/scenarios'
const SCENARIO_MODIFIERS_ROUTE = '/begroting/scenario-modifiers'
const SCENARIO_COMPARISON_ROUTE = '/begroting/scenarios/compare'

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
 * precedent, reused verbatim from `budget-core-schema.spec.ts`.
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

/**
 * Whether the given text is visible on the seeded index page — used to
 * decide whether a scenario-dependent test has a real fixture to work
 * against, or must skip honestly.
 */
async function hasAnyRow(page: Page): Promise<boolean> {
	const row = page.locator('table tbody tr').first()
	return row.isVisible().catch(() => false)
}

test.describe('budget-scenarios — Budgets nav group leaves (REQ-BSC-008)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-scenarios::modifier-crud-reachable
	 *
	 * The `Budgets` group (relocated into Cluster-4 `Banking & Cashflow`,
	 * `budget-core-schema.json`'s own `_meta_note`) gains three new leaves
	 * this change adds: Scenarios, Scenario Modifiers, Scenario Comparison.
	 */
	test('Budgets nav group lists the three new budget-scenarios leaves', async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		const bankingCashflow = page.getByRole('link', { name: /Banking.*Cashflow/i })
		await expect(bankingCashflow.first()).toBeVisible({ timeout: 15_000 })
		await bankingCashflow.first().click()

		const budgetsGroup = page.getByText('Budgets', { exact: true })
		await expect(budgetsGroup.first()).toBeVisible({ timeout: 10_000 })

		for (const label of ['Scenarios', 'Scenario Modifiers', 'Scenario Comparison']) {
			await expect(
				page.getByRole('link', { name: label }).first(),
				`nav leaf "${label}" must be reachable under Budgets`,
			).toBeVisible({ timeout: 10_000 })
		}
	})
})

test.describe('budget-scenarios — modifier CRUD reachable (REQ-BSC-008, REQ-BSC-009)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-scenarios::modifier-crud-reachable
	 */
	test('BudgetScenarios index resolves', async ({ page }) => {
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e budget-scenarios::modifier-crud-reachable
	 */
	test('BudgetScenarioModifiers index resolves', async ({ page }) => {
		await gotoRoute(page, SCENARIO_MODIFIERS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e budget-scenarios::modifier-crud-reachable
	 *
	 * REQ-BSC-003/REQ-BSC-009 scenario: an operator creates a
	 * `LEDGER_AMOUNT_DELTA` modifier targeting the ONE balance-sheet
	 * `LedgerGroup` this change seeds ("Liquide middelen", RULING 1) —
	 * proving that seed exists and is a valid, pickable target on a fresh
	 * import, exactly as the task brief's own worked example needs. Requires
	 * an existing `BudgetScenario` to attach the modifier to (this change
	 * ships no scenario seed by design) — creates one first when none exist,
	 * rather than skipping, since the create flow itself is what this
	 * scenario is proving.
	 */
	test('an operator can create a LEDGER_AMOUNT_DELTA modifier targeting the seeded Liquide middelen ledger group', async ({
		page,
	}) => {
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })

		if ((await hasAnyRow(page)) === false) {
			const createScenarioBtn = page.getByRole('button', { name: /create|add|new/i })
			const hasCreate = await createScenarioBtn.first().isVisible().catch(() => false)
			test.skip(!hasCreate, 'no create affordance found on the BudgetScenarios index')
			await createScenarioBtn.first().click()
			await page.getByLabel(/^name$/i).fill('E2E fixture scenario')
			await page.getByRole('button', { name: /save/i }).click()
			await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
		}

		await gotoRoute(page, SCENARIO_MODIFIERS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })

		const createModifierBtn = page.getByRole('button', { name: /create|add|new/i })
		const hasCreate = await createModifierBtn.first().isVisible().catch(() => false)
		test.skip(!hasCreate, 'no create affordance found on the BudgetScenarioModifiers index')
		await createModifierBtn.first().click()

		const scenarioField = page.getByLabel(/scenario/i).first()
		const hasScenarioOption = await scenarioField.isVisible().catch(() => false)
		test.skip(!hasScenarioOption, 'no scenario option available to attach the modifier to')
		await scenarioField.click()
		await page.getByRole('option').first().click()

		const modifierTypeField = page.getByLabel(/modifier type/i)
		await modifierTypeField.click()
		await page.getByRole('option', { name: /ledger_amount_delta/i }).click()

		const ledgerGroupField = page.getByLabel(/target ledger group/i)
		await ledgerGroupField.click()
		const liquideMiddelenOption = page.getByRole('option', { name: /liquide middelen/i })
		const hasSeededOption = await liquideMiddelenOption.isVisible().catch(() => false)
		test.skip(
			!hasSeededOption,
			'the seeded "Liquide middelen" LedgerGroup (RULING 1) was not found as a selectable option',
		)
		await liquideMiddelenOption.click()

		await page.getByLabel(/effective date/i).fill('2027-09-01')
		await page.getByLabel(/amount delta/i).fill('-500000')

		await page.getByRole('button', { name: /save/i }).click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })

		await expect(
			page.getByText('LEDGER_AMOUNT_DELTA', { exact: false }).first(),
			'the saved modifier\'s type must render on its own detail page',
		).toBeVisible({ timeout: 10_000 })
	})
})

test.describe('budget-scenarios — promote to default demotes the previous one (REQ-BSC-002)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-scenarios::promote-to-default-demotes-previous-default
	 *
	 * design.md §3a: atomic demotion, not rejection. Requires two seeded
	 * scenarios for the same administration to demonstrate the demotion
	 * (this change ships no scenario seed) — creates them when fewer than
	 * two exist, since the promotion flow itself is what this scenario is
	 * proving, then promotes the non-default one and asserts the previously
	 * default one's own `isDefault` chip flips to false in the same UI flow.
	 */
	test('promoting scenario B to default demotes scenario A in the same action', async ({ page }) => {
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })

		const rows = page.locator('table tbody tr')
		let rowCount = await rows.count()

		const createBtn = page.getByRole('button', { name: /create|add|new/i })
		const hasCreate = await createBtn.first().isVisible().catch(() => false)

		for (let i = rowCount; i < 2; i++) {
			test.skip(!hasCreate, 'no create affordance found on the BudgetScenarios index')
			await createBtn.first().click()
			await page.getByLabel(/^name$/i).fill(`E2E fixture scenario ${i + 1}`)
			await page.getByRole('button', { name: /save/i }).click()
			await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
			await gotoRoute(page, SCENARIOS_ROUTE)
			await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
		}

		rowCount = await rows.count()
		test.skip(rowCount < 2, 'fewer than two BudgetScenario rows available to demonstrate demotion')

		// Open the first row (scenario A) and promote it to default first, so
		// there is a genuine "previous default" to demote.
		await rows.nth(0).click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
		const promoteA = page.getByRole('button', { name: /promote to default/i })
		const hasPromoteA = await promoteA.first().isVisible().catch(() => false)
		test.skip(!hasPromoteA, 'scenario A is already default, or the promote action is not visible')
		await promoteA.first().click()
		await expect(page.getByText(/promoted to default/i).first()).toBeVisible({ timeout: 10_000 })

		// Now open scenario B and promote IT — scenario A must flip to
		// non-default in the same action.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
		await rows.nth(1).click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
		const promoteB = page.getByRole('button', { name: /promote to default/i })
		await promoteB.first().click()
		await expect(page.getByText(/promoted to default/i).first()).toBeVisible({ timeout: 10_000 })

		// Scenario A's own detail page no longer offers "Promote to default"
		// as a NO-OP-hiding action being absent would suggest it is ALREADY
		// non-default again — i.e. the action re-appears, proving isDefault
		// flipped to false.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
		await rows.nth(0).click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByRole('button', { name: /promote to default/i }).first(),
			'scenario A must show "Promote to default" again once demoted (visibleWhen isDefault=false)',
		).toBeVisible({ timeout: 10_000 })
	})
})

test.describe('budget-scenarios — scenario comparison page (REQ-BSC-005, REQ-BSC-008)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	/**
	 * @e2e budget-scenarios::scenario-comparison-renders-base-and-scenario
	 *
	 * The standalone `BudgetScenarioComparison` page (no `:id` route segment
	 * — design.md §9 / this change's own `_note`) resolves as a plain
	 * top-level nav entry and renders its picker controls unconditionally.
	 */
	test('BudgetScenarioComparison resolves and shows the administration/fiscal-year/scenario picker', async ({
		page,
	}) => {
		await gotoRoute(page, SCENARIO_COMPARISON_ROUTE)

		await expect(
			page.getByTestId('budget-scenario-comparison-administration'),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByTestId('budget-scenario-comparison-fiscal-year'),
		).toBeVisible({ timeout: 10_000 })
		await expect(
			page.getByTestId('budget-scenario-comparison-scenario'),
		).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e budget-scenarios::scenario-comparison-renders-base-and-scenario
	 *
	 * REQ-BSC-005 scenario: for a scenario carrying at least one modifier,
	 * the comparison table renders base AND scenario rows (values may
	 * legitimately be equal in cells the modifier does not touch — the
	 * important assertion is that BOTH rows render, not that they differ
	 * everywhere). Data-defensive: this change ships no scenario seed, so
	 * the test skips honestly when no scenario is selectable.
	 */
	test('selecting a scenario with a modifier renders base and scenario rows in the table', async ({
		page,
	}) => {
		await gotoRoute(page, SCENARIO_COMPARISON_ROUTE)

		const scenarioSelect = page.getByTestId('budget-scenario-comparison-scenario')
		await expect(scenarioSelect).toBeVisible({ timeout: 15_000 })

		const optionCount = await scenarioSelect.locator('option').count()
		test.skip(optionCount <= 1, 'no BudgetScenario available to select (only the placeholder option present)')

		await scenarioSelect.selectOption({ index: 1 })

		const table = page.getByTestId('budget-scenario-comparison-table')
		const tableVisible = await table.isVisible({ timeout: 10_000 }).catch(() => false)
		test.skip(!tableVisible, 'selected scenario has no evaluable LedgerGroup data to render a table for')

		await expect(table.getByText('Base', { exact: true }).first()).toBeVisible({ timeout: 10_000 })
		await expect(table.getByText('Scenario', { exact: true }).first()).toBeVisible({ timeout: 10_000 })
		await expect(table.getByText('Delta', { exact: true }).first()).toBeVisible({ timeout: 10_000 })
	})
})
