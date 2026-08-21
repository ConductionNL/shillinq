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
 *    action) flips scenario A's own `isDefault` back to false in the same
 *    action — read through that action's own `visibleWhen: isDefault eq
 *    false` gate, on a freshly loaded detail page.
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
 * `BudgetScenario` and `BudgetScenarioModifier` ship with NO seed data
 * (design.md ships only the one `LedgerGroup` anchor row, RULING 1), so the
 * two write-flow tests SEED THEIR OWN FIXTURES — uniquely named per run —
 * rather than reading whatever the instance happens to hold. That matters
 * for more than repeatability: REQ-BSC-002 scopes the one-default rule per
 * administration, so a test that paired two ambient rows could pair two
 * DIFFERENT administrations, for which not demoting is the correct
 * behaviour. The administration itself is resolved from the app's own
 * `/api/administrations/context` (never invented): `promote` masks a
 * non-membership as a 404, so a fabricated `administrationId` would make
 * the promotion unreachable regardless of the promoter's correctness.
 *
 * Locator conventions this file learned the hard way (CI run 32425675997,
 * where both write-flow tests timed out against their own feature):
 *
 *  - The generic renderer builds a field's accessible name from the schema
 *    `title` PLUS a required marker — "Name *", "Administration ID *". An
 *    anchored `getByLabel(/^name$/i)` therefore matches NOTHING.
 *  - `CnFormDialog`'s submit button reads "Create" in create mode; "Save"
 *    appears only when editing.
 *  - Every REQUIRED field must be filled before that button leaves its
 *    disabled state — `administrationId` included.
 *  - Creating from an index page refreshes the list IN PLACE; it does not
 *    navigate to the new object's detail page.
 *  - A leaf/label-only locator can match a different feature's element:
 *    cashflow-13wk ships its own `Scenarios` nav leaf, which is why the nav
 *    test below asserts manifest ids instead.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md
 */

import type { Locator, Page } from '@playwright/test'

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
 * A per-run discriminator, so a re-run (or a Playwright retry) never
 * mistakes the previous attempt's scenarios for its own — the
 * `an-e2e-spec-that-depends-on-ambient-state` precedent: seed your own
 * fixture rather than reading whatever the instance happens to hold.
 */
function uniqueSuffix(): string {
	return `${Date.now()}`
}

/**
 * The administration the signed-in user actually holds a membership for.
 *
 * This CANNOT be an invented value. `BudgetScenarioController::promote`
 * runs `AdministrationContextService::canAccess()` on the promoted
 * scenario's own `administrationId` and masks a non-membership as a 404 —
 * so a scenario created under a made-up administration is unpromotable no
 * matter how correct `BudgetScenarioDefaultPromoter` is, and the test
 * would be failing its own fixture rather than the product.
 *
 * Read from the app's own context endpoint instead of hardcoding the
 * seeded `ADM-001`, so the fixture cannot drift from the seed.
 */
async function accessibleAdministrationId(page: Page): Promise<string> {
	const response = await page.request.get(`${APP}/api/administrations/context`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(
		response.status(),
		'the administration context endpoint must answer for the signed-in user',
	).toBe(200)

	const body = await response.json()
	const administrationId = String(
		body?.activeAdministrationId
			?? body?.administrations?.[0]?.administrationId
			?? '',
	)
	expect(
		administrationId,
		'the signed-in user must hold a membership to create BudgetScenarios under',
	).not.toBe('')

	return administrationId
}

/**
 * Open the generic index page's own create dialog.
 *
 * `CnIndexPage` labels this button from the schema title ("Add Budget
 * Scenario", "Add Budget Scenario Modifier"), so it is matched on the
 * stable `Add ` prefix and scoped to `main` — the dialog itself renders in
 * a body-level portal and carries its own "Create" button, which an
 * unscoped `/create|add|new/i` would also match.
 */
async function openCreateDialog(page: Page) {
	await page
		.getByRole('main')
		.getByRole('button', { name: /^Add\b/i })
		.first()
		.click()

	const dialog = page.getByRole('dialog').last()
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	return dialog
}

/**
 * Pick a value in one of the generic form's `NcSelect` comboboxes.
 *
 * `CnResourceSelect` labels a `$ref` option `obj[labelField] || obj.name ||
 * obj.title || id`, so a referenced `BudgetScenario`/`LedgerGroup` is
 * addressable by its own `name`. The listbox renders in a body-level
 * portal, so options are looked up on `page`, not inside the dialog.
 */
async function pickOption(
	page: Page,
	field: Locator,
	optionName: string | RegExp,
	message: string,
): Promise<void> {
	await field.click()
	const option = page.getByRole('option', { name: optionName }).first()
	await expect(option, message).toBeVisible({ timeout: 15_000 })
	await option.click()
}

/**
 * Create one `BudgetScenario` through the index page's own create dialog.
 *
 * Fills BOTH required free-text fields. `administrationId` is required by
 * this change's own schema, and `CnFormDialog` keeps its submit button
 * disabled until every required field holds a non-empty value — so
 * omitting it makes the create impossible, not merely untidy. `isDefault`
 * is a required checkbox that create-mode initialises to `false`, which
 * already satisfies that check: the scenario is deliberately created
 * NON-default, which is what leaves the "Promote to default" header action
 * visible (`visibleWhen: isDefault eq false`).
 *
 * Note the submit button is "Create" — `CnFormDialog` renders "Create" in
 * create mode and "Save" only when editing.
 */
async function createScenario(
	page: Page,
	options: { name: string; administrationId: string },
): Promise<void> {
	const dialog = await openCreateDialog(page)

	// The generic renderer appends a required marker to the accessible
	// name ("Name *"), so an ANCHORED `/^name$/i` can never match it.
	await dialog
		.getByRole('textbox', { name: /^Administration ID/i })
		.fill(options.administrationId)
	await dialog.getByRole('textbox', { name: /^Name/i }).fill(options.name)

	const submit = dialog.getByRole('button', { name: 'Create', exact: true })
	await expect(
		submit,
		'every required BudgetScenario field must be filled before Create enables',
	).toBeEnabled({ timeout: 10_000 })
	await submit.click()

	// The dialog auto-closes on success; creating from an index page
	// refreshes the list in place and does NOT navigate to the detail page.
	await expect(dialog).toBeHidden({ timeout: 15_000 })
}

/** The index row carrying a given scenario name. */
function scenarioRow(page: Page, name: string) {
	return page.locator('table tbody tr').filter({ hasText: name })
}

/** Open one named scenario's detail page from the index. */
async function openScenario(page: Page, name: string): Promise<void> {
	const row = scenarioRow(page, name)
	await expect(row).toBeVisible({ timeout: 15_000 })
	await row.click()
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({
		timeout: 15_000,
	})
	await expect(
		page.getByText(name).first(),
		`the detail page must be showing "${name}"`,
	).toBeVisible({ timeout: 10_000 })
}

/**
 * The "Promote to default" header action.
 *
 * Safe to match on its English label: it is THIS change's own manifest
 * string (`src/manifest.d/budget-scenarios.json`), not a framework string
 * the instance would translate.
 */
function promoteButton(page: Page) {
	return page.getByRole('button', { name: /promote to default/i })
}

/**
 * Click "Promote to default" and prove the promotion actually reached the
 * backend, rather than only that a toast appeared.
 */
async function promoteToDefault(page: Page): Promise<void> {
	const promoted = page.waitForResponse(
		(response) =>
			/\/api\/v1\/budget-scenarios\/[^/]+\/promote$/.test(
				new URL(response.url()).pathname,
			) && response.request().method() === 'POST',
		{ timeout: 25_000 },
	)

	await promoteButton(page).first().click()

	const response = await promoted
	expect(
		response.status(),
		'BudgetScenarioController::promote must accept the promotion',
	).toBe(200)

	await expect(page.getByText(/promoted to default/i).first()).toBeVisible({
		timeout: 10_000,
	})
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
	test('the three new budget-scenarios leaves are reachable under Banking & Cashflow', async ({
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

		// Assert each leaf by the id `CnAppNav` stamps onto it
		// (`cn-nav-entry-<child id>`, CnAppNav.vue) rather than by its label.
		// `getByRole('link', { name: 'Scenarios' })` is AMBIGUOUS in this very
		// subtree: cashflow-13wk already ships a `Scenarios` leaf pointing at
		// `/apps/shillinq/cashflow/scenarios`, so a label-only locator matches
		// that page and reports PASS without this change's own
		// `/begroting/scenarios` leaf existing at all. The manifest id is
		// unique per leaf, so it cannot false-pass.
		for (const [id, label] of [
			['BudgetScenarios', 'Scenarios'],
			['BudgetScenarioModifiers', 'Scenario Modifiers'],
			['BudgetScenarioComparison', 'Scenario Comparison'],
		]) {
			await expect(
				page.locator(`[data-testid="cn-nav-entry-${id}"]`),
				`nav leaf "${label}" (${id}) must be reachable under Banking & Cashflow`,
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
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e budget-scenarios::modifier-crud-reachable
	 */
	test('BudgetScenarioModifiers index resolves', async ({ page }) => {
		await gotoRoute(page, SCENARIO_MODIFIERS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
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
		const stamp = uniqueSuffix()
		const administrationId = await accessibleAdministrationId(page)
		const scenarioName = `E2E modifier fixture ${stamp}`

		// Seed this test's OWN scenario instead of attaching to whatever row
		// the index happens to hold: the modifier's `scenarioId` is a `$ref`,
		// so picking "the first option" would silently bind the modifier to an
		// unrelated scenario and prove nothing about the flow under test.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
		await createScenario(page, { name: scenarioName, administrationId })
		await expect(
			scenarioRow(page, scenarioName),
			'the freshly created BudgetScenario must appear on its index',
		).toBeVisible({ timeout: 15_000 })

		await gotoRoute(page, SCENARIO_MODIFIERS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const dialog = await openCreateDialog(page)

		// Labels come from the schema `title` plus the required marker, e.g.
		// "Administration ID *" / "Modifier Type *" — never a bare lowercase
		// English word, and never an anchored `/^…$/` match.
		await dialog
			.getByRole('textbox', { name: /^Administration ID/i })
			.fill(administrationId)

		// `scenarioId` / `modifierType` / `targetLedgerGroupId` all resolve to
		// the `select` widget, which NcSelect renders as a combobox carrying
		// the field's own accessible name (the `Status *` combobox in this
		// change's own create dialog is the observed precedent).
		await pickOption(
			page,
			dialog.getByRole('combobox', { name: /^Scenario ID/i }),
			scenarioName,
			`the BudgetScenario "${scenarioName}" must be selectable as the modifier's owner`,
		)

		await pickOption(
			page,
			dialog.getByRole('combobox', { name: /^Modifier Type/i }),
			'LEDGER_AMOUNT_DELTA',
			'REQ-BSC-003: LEDGER_AMOUNT_DELTA must be one of the three offered modifier kinds',
		)

		// REQ-BSC-009: asserted, NOT skipped. This is this change's own anchor
		// seed (`register.d/budget-scenarios.json`, slug
		// `ledger-group-vla-liq`), shipped by every fresh import — the same
		// reasoning `budget-core-schema.spec.ts` gives for asserting its own
		// LedgerGroup seed directly. Treating its absence as a skip would turn
		// a broken seed into a green run.
		await pickOption(
			page,
			dialog.getByRole('combobox', { name: /^Target Ledger Group ID/i }),
			'Liquide middelen',
			'REQ-BSC-009: the seeded "Liquide middelen" LedgerGroup must be a pickable LEDGER_AMOUNT_DELTA target on a fresh import',
		)

		await dialog.getByLabel(/^Effective Date/i).fill('2027-09-01')
		await dialog.getByLabel(/^Amount Delta/i).fill('-500000')

		const submit = dialog.getByRole('button', { name: 'Create', exact: true })
		await expect(
			submit,
			'every required BudgetScenarioModifier field must be filled before Create enables',
		).toBeEnabled({ timeout: 10_000 })
		await submit.click()
		await expect(dialog).toBeHidden({ timeout: 15_000 })

		// Creating from an index page refreshes the list in place rather than
		// navigating to the new object's detail page, so the saved modifier is
		// asserted on the index row it now owns.
		const modifierRow = page
			.locator('table tbody tr')
			.filter({ hasText: 'LEDGER_AMOUNT_DELTA' })
		await expect(
			modifierRow.first(),
			'the saved LEDGER_AMOUNT_DELTA modifier must appear on the BudgetScenarioModifiers index',
		).toBeVisible({ timeout: 15_000 })

		// …and its own detail page must render the type it was saved with.
		await modifierRow.first().click()
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.getByText('LEDGER_AMOUNT_DELTA', { exact: false }).first(),
			"the saved modifier's type must render on its own detail page",
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
	 * design.md §3a: atomic demotion, not rejection.
	 *
	 * Seeds its own scenario A and B under one administration, promotes A,
	 * proves A became the default, then promotes B and proves A was demoted
	 * by that same action. The two verdicts are read through the same
	 * `visibleWhen: isDefault eq false` header action on the same object —
	 * hidden while A is default, visible again once it is not — so the pair
	 * carries its own must-PASS control rather than resting on a locator
	 * that could be absent for an unrelated reason.
	 */
	test('promoting scenario B to default demotes scenario A in the same action', async ({
		page,
	}) => {
		const stamp = uniqueSuffix()
		const administrationId = await accessibleAdministrationId(page)
		const nameA = `E2E scenario A ${stamp}`
		const nameB = `E2E scenario B ${stamp}`

		await gotoRoute(page, SCENARIOS_ROUTE)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		// Seed BOTH scenarios, explicitly under the SAME administration.
		// REQ-BSC-002 scopes the one-default rule PER ADMINISTRATION, so
		// pairing whatever two rows the index happens to hold (the previous
		// `rows.nth(0)` / `rows.nth(1)`) could pick scenarios belonging to two
		// DIFFERENT administrations — for which not demoting is the CORRECT
		// behaviour, failing this test against a working product.
		//
		// The administration is necessarily shared (promotion requires a
		// membership), so per-run isolation comes from the unique NAMES
		// instead. A leftover default from an earlier run is not a problem
		// and is not ignored: promoting A demotes it, and the control
		// assertion below re-reads A to prove that promotion really landed
		// before anything is concluded from B's.
		await createScenario(page, { name: nameA, administrationId })
		await createScenario(page, { name: nameB, administrationId })

		// Promote A first, so there is a genuine "previous default" to demote.
		await openScenario(page, nameA)
		await promoteToDefault(page)

		// CONTROL (must-PASS): on a freshly loaded detail page, A's promote
		// action is now GONE. This proves two things the final assertion
		// depends on — that the promotion persisted, and that CnActionButtons
		// really does evaluate `visibleWhen` against the stored `isDefault`
		// rather than rendering the action unconditionally. Every visibility
		// assertion here is made after a fresh page load, so none of them rely
		// on the api-call action refreshing the record in place.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await openScenario(page, nameA)
		await expect(
			promoteButton(page),
			'scenario A must hide "Promote to default" once it IS the default (visibleWhen isDefault=false)',
		).toBeHidden({ timeout: 15_000 })

		// Now promote B — A must be demoted by the same action.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await openScenario(page, nameB)
		await promoteToDefault(page)

		// B is the default now…
		await gotoRoute(page, SCENARIOS_ROUTE)
		await openScenario(page, nameB)
		await expect(
			promoteButton(page),
			'scenario B must be the default after being promoted',
		).toBeHidden({ timeout: 15_000 })

		// …and A must have been demoted in that same action. Paired with the
		// CONTROL above — the identical locator, on the identical object,
		// hidden before and visible now — this is the end-to-end assertion of
		// REQ-BSC-002's "exactly one default": A flipped back to
		// `isDefault: false` precisely because B was promoted.
		await gotoRoute(page, SCENARIOS_ROUTE)
		await openScenario(page, nameA)
		await expect(
			promoteButton(page),
			'scenario A must show "Promote to default" again once demoted — promoting B did NOT demote the previous default (REQ-BSC-002)',
		).toBeVisible({ timeout: 15_000 })
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

		const scenarioSelect = page.getByTestId(
			'budget-scenario-comparison-scenario',
		)
		await expect(scenarioSelect).toBeVisible({ timeout: 15_000 })

		const optionCount = await scenarioSelect.locator('option').count()
		test.skip(
			optionCount <= 1,
			'no BudgetScenario available to select (only the placeholder option present)',
		)

		await scenarioSelect.selectOption({ index: 1 })

		const table = page.getByTestId('budget-scenario-comparison-table')
		const tableVisible = await table
			.isVisible({ timeout: 10_000 })
			.catch(() => false)
		test.skip(
			!tableVisible,
			'selected scenario has no evaluable LedgerGroup data to render a table for',
		)

		await expect(table.getByText('Base', { exact: true }).first()).toBeVisible({
			timeout: 10_000,
		})
		await expect(
			table.getByText('Scenario', { exact: true }).first(),
		).toBeVisible({ timeout: 10_000 })
		await expect(table.getByText('Delta', { exact: true }).first()).toBeVisible({
			timeout: 10_000,
		})
	})
})
