/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — retire-cost-project (REQ-RCP-003/004/006).
 *
 * ⚠️ WHY EVERY ASSERTION IN THIS FILE USED TO BE SATISFIED BY A BLANK PAGE
 * ------------------------------------------------------------------------
 * All four tests were built out of ABSENCE claims — `toHaveCount(0)`,
 * `not.toContain(...)`, `not.toBe(404)` — with no proof that the surface they
 * were asserting about had rendered at all. An absence assertion over a page
 * that never painted passes trivially:
 *
 *  - `expect(costProjectsLink).toHaveCount(0)` is true when the SPA never
 *    booted and the navigation does not exist.
 *  - `const navText = await navArea.innerText().catch(() => '')` followed by
 *    `expect(navText).not.toContain('Cost Projects')` is true for the empty
 *    string the `.catch` manufactures — including when `innerText()` threw
 *    because there was no navigation to read.
 *  - `expect(response.status()).not.toBe(404)` cannot fail at all:
 *    `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse`. There is no 404 path
 *    to detect.
 *  - `expect(body.toLowerCase()).not.toContain('page not found')`, again over
 *    a `.catch(() => '')` result.
 *
 * On CI 30881746678 a control truncated `js/shillinq-main.js` to 0 bytes so the
 * SPA could not boot; assertions of this shape survived it.
 *
 * Every test below therefore now establishes a POSITIVE ANCHOR — proof that
 * the surface under test actually rendered — BEFORE asserting what is absent
 * from it, and every `.catch(() => '')` around an `innerText()` is gone so an
 * exception fails instead of silently passing.
 *
 * Navigation entries are matched on the lib's own stable testid
 * (`data-testid="cn-nav-entry-${item.id}"`), which is what `CnAppNavigation`
 * emits for every menu item and child.
 *
 * This spec is UI-only (manifest renderer surface). Backend / API contract
 * assertions (schema removal, migration marker) are covered by:
 *   - tests/Unit/Repair/RetireCostProjectStepTest.php (migration mapping)
 *   - Newman integration collection (schema unavailability, migratedFrom)
 *
 * @spec openspec/changes/retire-cost-project/tasks.md#phase-7
 * @spec openspec/changes/retire-cost-project/specs/retire-cost-project/spec.md (REQ-RCP-004/REQ-RCP-006)
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

const APP = '/apps/shillinq'

/**
 * Positive anchor shared by the navigation tests: prove the menu rendered
 * before asserting that something is missing FROM it.
 *
 * `Projects` is the surviving single nav home for the RJ 270 / IFRS 15
 * `Project` register (REQ-RCP-004). It is declared exactly once across
 * `src/manifest.json` + every `src/manifest.d/*.json` fragment, and it is NOT
 * in `src/menu-layout.json` `removals` — so it must be present. `toBeAttached`
 * rather than `toBeVisible`: it lives inside the "People & Projects" group and
 * an entry in a collapsed group is present-but-hidden.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 * @return {Promise<void>}
 */
async function assertNavigationRendered(page: import('@playwright/test').Page): Promise<void> {
	await expect(
		page.locator('#app-navigation-vue'),
		'the app navigation must have rendered before any absence claim about it is meaningful',
	).toBeVisible({ timeout: 15_000 })
	await expect(
		page.locator('[data-testid="cn-nav-entry-Projects"]'),
		'the surviving Projects nav entry must be present — its absence would mean the menu did not render, not that CostProjects was retired',
	).toBeAttached({ timeout: 10_000 })
}

test.describe('retire-cost-project — navigation contract', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('CostProjects nav entry is absent after retirement (REQ-RCP-004)', async ({ page }) => {
		await gotoPage(page, '/')
		await assertNavigationRendered(page)

		// ONLY NOW is an absence claim about the menu meaningful.
		await expect(
			page.locator('[data-testid="cn-nav-entry-CostProjects"], a[href*="/cost-projects"]'),
			'the retired CostProjects nav entry must not be rendered',
		).toHaveCount(0)

		// No `.catch(() => '')`: an exception reading the navigation must FAIL
		// the test, not hand it an empty string that satisfies `not.toContain`.
		const navText = await page.locator('#app-navigation-vue').innerText()
		expect(navText.length, 'the navigation must contain readable text').toBeGreaterThan(0)
		expect(navText).not.toContain('Cost Projects')
	})

	/**
	 * ⚠️ THIS TEST ASSERTS THE REAL RETIREMENT CONTRACT, WHICH IS NOT THE ONE
	 * ITS OLD NAME CLAIMED.
	 *
	 * The old test asserted `status !== 404` — which the app's catch-all route
	 * guarantees for every path — under the heading "deep-link contract", on the
	 * strength of `src/menu-layout.json`'s own description: "removals: leaf
	 * menu-entry ids retired as duplicate navigation — their PAGES stay routable
	 * for deep links and e2e specs."
	 *
	 * For `CostProjects` that is NOT what shipped. `CostProjects` appears in
	 * `src/menu-layout.json` `removals` and NOWHERE else: no page declares the
	 * route `/cost-projects`, and no page carries the id `CostProjects`, in
	 * `src/manifest.json`, `src/manifest.d.shell.json` or any
	 * `src/manifest.d/*.json`.
	 *
	 * POSITIVE CONTROL for that absence claim — the identical lookup over the
	 * identical files DOES find the other id retired by the same change:
	 *     ProjectenOverzicht → in menu-layout.json `removals`  ✔ found
	 *     ProjectenOverzicht → page id in src/manifest.json     ✔ found (its page survives, as documented)
	 *     CostProjects       → in menu-layout.json `removals`  ✔ found
	 *     CostProjects       → page id / route anywhere         ✘ ABSENT
	 *
	 * So the observable contract is the one `src/main.js` actually implements:
	 * its route table ends with
	 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })`, therefore a
	 * deep link to `/cost-projects` is redirected to the Dashboard. That is a
	 * graceful landing rather than a dead end, and it is assertable — which
	 * `not.toBe(404)` never was. Asserted below.
	 */
	test('former CostProjects deep link redirects to the Dashboard (retirement contract)', async ({ page }) => {
		await page.goto(APP + '/cost-projects', { waitUntil: 'domcontentloaded', timeout: 25_000 })

		// The SPA must boot — `#content-vue` exists only after
		// `app.mount('#shillinq-app')` in src/main.js.
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })

		// The vue-router catch-all must have taken us to the app root…
		await expect(page, 'the retired /cost-projects deep link must land on the Dashboard')
			.toHaveURL(/\/apps\/shillinq\/?(\?.*)?$/, { timeout: 15_000 })

		// …and the Dashboard page must actually have RENDERED there. A URL alone
		// would be satisfied by a shell that booted nothing;
		// `cn-dashboard-page` is CnDashboardPage's own unconditional root.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-dashboard-page"]'),
			'the redirect target must render the Dashboard, not an empty shell',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Projects (RJ 270) has exactly one nav home and ProjectenOverzicht is absent (REQ-RCP-004)', async ({ page }) => {
		await gotoPage(page, '/')
		await assertNavigationRendered(page)

		// EXACTLY one, not "at most one". The old `toBeLessThanOrEqual(1)`
		// accepted zero, which is the value a menu that never rendered produces —
		// so the test passed hardest in the one case it should have caught.
		await expect(
			page.locator('[data-testid="cn-nav-entry-Projects"]'),
			'the RJ 270 / IFRS 15 Project register must have exactly one nav home',
		).toHaveCount(1)

		// The duplicate overview entry is retired via menu-layout.json removals.
		await expect(
			page.locator('[data-testid="cn-nav-entry-ProjectenOverzicht"]'),
			'the duplicate ProjectenOverzicht nav entry must be gone',
		).toHaveCount(0)
	})

	/**
	 * ⚠️ REPOINTED ROUTE + RETAGGED CLAIM.
	 *
	 * This test navigated to `/dimensions`, which is declared NOWHERE in the
	 * manifest — so `src/main.js`'s `/:pathMatch(.*)*` catch-all redirected it
	 * to the Dashboard and every previous run asserted against THAT.
	 *
	 * POSITIVE CONTROL for the absence claim — the same lookup over the same
	 * files finds the sibling dimension routes, so it is not simply failing:
	 *     /dimensions                                    ✘ ABSENT
	 *     /bookkeeping/dimensions/analytical-dimensions  ✔ id AnalyticalDimensions, title "Analytical dimensions"
	 *     /bookkeeping/dimensions/cost-centers           ✔ id CostCenters
	 *     /bookkeeping/dimensions/projects               ✔ id Projects
	 *     /bookkeeping/dimensions/kostendragers          ✔ id KostenDragers
	 *
	 * The successor is unambiguous — the page id `AnalyticalDimensions` is
	 * literally what this test is named after — so the route is repointed
	 * rather than reported as unresolvable.
	 *
	 * The REQ-RCP-001 tag is REMOVED, deliberately. The retire-cost-project
	 * spec carries `@e2e exclude unbuilt UI: the cost-center-as-project budget
	 * view and the OpenProject-link affordance are not yet implemented`, and the
	 * page's manifest config pins `filters: { dimensionType: "custom" }` — it
	 * does not surface project-flavoured rows at all. The old
	 * `if (await dimensionTypeFilter.count() > 0) { … }` guard meant the
	 * REQ-RCP-001 assertion inside it never ran, so the tag was never earned.
	 * What this test can honestly cover is that the AnalyticalDimensions index
	 * survived the retirement and still mounts, which is what it now asserts.
	 */
	test('AnalyticalDimensions index page mounts on /bookkeeping/dimensions/analytical-dimensions', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/dimensions/analytical-dimensions')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })

		// `cn-index-page` is CnIndexPage's own root div (CnIndexPage.vue:2) —
		// no `v-if`, so this holds on an unseeded instance, and the Dashboard
		// (which renders `cn-dashboard-page`) cannot satisfy it.
		//
		// ⚠️ NOT the page title: an earlier revision asserted
		// `#app-content-vue [data-testid="cn-page-title"]`. `CnPageHeader` does
		// emit that `<h1>`, but `CnIndexPage.vue:12` renders CnPageHeader behind
		// `v-if="showTitle"`, which defaults to FALSE — the title is shown in
		// the SIDEBAR header instead. `CnPageRenderer.vue` never passes
		// `show-title` and all six `showTitle` occurrences in
		// `src/manifest.json` set it to false, so `cn-page-title` renders on NO
		// shillinq index page. Run 30894384122 turned that into 69 false
		// failures.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Analytical dimensions route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })

		// No `.catch(() => '')`: an exception reading the page must FAIL.
		const body = await page.locator('#app-content-vue').innerText()
		expect(body.length, 'the content region must render readable text').toBeGreaterThan(10)
		expect(body.toLowerCase()).not.toContain('internal server error')
	})
})
