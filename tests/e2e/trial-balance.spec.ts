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
 * an undeclared route to the Dashboard. Then CnPageHeader's `cn-page-title`
 * `<h1>` inside `#app-content-vue` (NcAppContent's `<main>` — never
 * `#content-vue`, which also wraps the sidebar that is identical on all ~107
 * pages) must read that page's own manifest title. The `-lines` page asserts
 * the full "Trial Balance (by account)" so it cannot be satisfied by the
 * period-snapshot page and vice versa.
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
		// `toHaveText` with an anchored-enough matcher: the sibling page's title
		// is "Trial Balance (by account)", so `/^Trial Balance$/i` keeps the two
		// distinguishable rather than letting either satisfy the other.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-page-title"]'),
			'the Trial Balance page must render its own manifest title',
		).toHaveText(/^\s*Trial Balance\s*$/i, { timeout: 15_000 })
	})

	test('Trial Balance (by account) index mounts on /financial-statements/trial-balance-lines', async ({ page }) => {
		await gotoPage(page, '/financial-statements/trial-balance-lines')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-page-title"]'),
			'the Trial Balance (by account) page must render its own manifest title',
		).toHaveText(/Trial Balance \(by account\)/i, { timeout: 15_000 })
	})
})
