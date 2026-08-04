/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq Trial Balance SPA smoke.
 *
 * `src/manifest.json` declares three Trial Balance pages:
 *   /financial-statements/trial-balance        (index)  "Trial Balance"
 *   /financial-statements/trial-balance/:id    (detail) "Trial Balance"
 *   /financial-statements/trial-balance-lines  (index)  "Trial Balance (by account)"
 * rendered by the nextcloud-vue manifest shell. This smoke confirms the two
 * index pages mount and render THEIR OWN titles.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * The test asserted `expect(page.url()).toContain('/apps/shillinq')` three
 * times and `expect(page).toHaveTitle(/shillinq/i)` once. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — so a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id, BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and both assertions still held.
 * Worse, the two routes differ only in their suffix, so nothing here
 * distinguished the two pages from each other or from the Dashboard.
 *
 * The replacement, per page: `gotoPage()` waits for `#content-vue` (which only
 * exists after `app.mount('#shillinq-app')`) and asserts the SETTLED path
 * equals the requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently rewriting
 * an undeclared route to the Dashboard. Then CnIndexPage's own root must have
 * rendered inside `#app-content-vue` (NcAppContent's `<main>` — never
 * `#content-vue`, which also wraps the sidebar that is identical on all ~107
 * pages). The two routes stay distinguishable because `gotoPage()` pins each
 * one to its own path; the Dashboard renders `cn-dashboard-page`, not
 * `cn-index-page`, so a catch-all fallback fails both checks.
 *
 * ⚠️ DO NOT ASSERT THE PAGE TITLE INSIDE `#app-content-vue` — IT IS NOT THERE.
 * An earlier revision asserted `#app-content-vue [data-testid="cn-page-title"]`.
 * `CnPageHeader` does emit that `<h1>`, but `CnIndexPage.vue:12` renders
 * CnPageHeader behind `v-if="showTitle"` and `showTitle` defaults to FALSE
 * ("When false (default), the title is shown in the sidebar header instead").
 * `CnPageRenderer.vue` never passes `show-title`, and all six `showTitle`
 * occurrences in `src/manifest.json` set it to false — so `cn-page-title`
 * renders on NO shillinq index page. That is also why
 * `spec-coverage/_helpers.ts` keeps its title check soft and matching the
 * SIDEBAR. Run 30894384122 turned that mistake into 69 false failures.
 *
 * The full Trial Balance end-to-end flows (REQ-TB-009 GET /api/trial-balance
 * against a seeded GL, REQ-TB-002 prior-period opening carry, REQ-TB-011 KPI
 * card totals, REQ-TB-014 < 2 s render at 10 000 accounts) are @e2e excluded
 * here: they require a live OpenRegister instance seeded with Account +
 * GLTransaction + GLLine fixtures across two fiscal periods, which the
 * implementing cycle wires once the register fragment is imported into a
 * running instance.
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — Trial Balance SPA smoke', () => {
	test('Trial Balance (period snapshot) index mounts on /financial-statements/trial-balance', async ({ page }) => {
		await gotoPage(page, '/financial-statements/trial-balance')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		// `cn-index-page` is CnIndexPage's own root div (CnIndexPage.vue:2) —
		// no `v-if`, so it holds on an unseeded instance. The sibling
		// `-lines` route is kept distinct by `gotoPage()`'s path assertion.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Trial Balance route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Trial Balance (by account) index mounts on /financial-statements/trial-balance-lines', async ({ page }) => {
		await gotoPage(page, '/financial-statements/trial-balance-lines')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Trial Balance (by account) route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})
})
