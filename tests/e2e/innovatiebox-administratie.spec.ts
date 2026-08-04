/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq Innovatiebox Administratie SPA smoke.
 *
 * `src/manifest.json` declares the Innovatiebox pages behind the
 * `mkb-innovatiebox` feature flag:
 *   /bookkeeping/innovatiebox                 (index)  "Innovation box"
 *   /bookkeeping/innovatiebox/:id             (detail) "Innovation box election"
 *   /bookkeeping/innovatiebox/ip-activa/:id   (detail) "IP-activum"
 * This smoke confirms the index page and the IP-activum detail route mount.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * The test asserted `expect(page.url()).toContain('/apps/shillinq')` three
 * times and `expect(page).toHaveTitle(/shillinq/i)` once. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and both assertions still held.
 *
 * The replacement: `gotoPage()` waits for `#content-vue` (which exists only
 * after `app.mount('#shillinq-app')`) and asserts the SETTLED path equals the
 * requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently rewriting
 * an undeclared route to the Dashboard. Then a page-type-specific surface must
 * have rendered inside `#app-content-vue` (NcAppContent's `<main>`; never
 * `#content-vue`, which also wraps the sidebar that is identical on all ~107
 * pages):
 *  - the index page must show CnPageHeader's `cn-page-title` `<h1>` reading
 *    "Innovation box";
 *  - the detail route is deliberately requested with the synthetic id `none`,
 *    so the object cannot resolve and CnDetailPage's title falls back to the
 *    object's display name. The stable, data-independent proof that the DETAIL
 *    renderer (rather than the Dashboard) mounted is its unconditional
 *    `data-testid="cn-detail-page-header"` root.
 *
 * The deeper end-to-end flows (REQ-IBA-006 per-asset Vpb roll-up,
 * REQ-IBA-009 scenario calculator, REQ-IBA-004 doorsnijdingsverbod close-block,
 * REQ-IBA-008 VSO lock + audit-trail event append) are @e2e excluded here:
 * they require a live OpenRegister instance seeded with the five register
 * fragments + a QualifyingAsset + NexusCalculation + IBProfitAttribution +
 * CarryForwardLoss + IBExpenseAllocation chain plus a paired GL feed for the
 * doorsnijdingsverbod check, which the implementing cycle wires once the
 * register fragment is imported into a running instance.
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — Innovatiebox Administratie SPA smoke', () => {
	test('Innovation box index mounts on /bookkeeping/innovatiebox', async ({ page }) => {
		// The page must mount whether the mkb-innovatiebox flag is on or off:
		// when off the index renders empty, but CnPageHeader still prints the
		// manifest title, so this assertion stays data- and flag-independent.
		await gotoPage(page, '/bookkeeping/innovatiebox')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-page-title"]'),
			'the Innovation box index must render its own manifest title',
		).toHaveText(/Innovation box/i, { timeout: 15_000 })
	})

	test('IP-activum detail route mounts the detail renderer on /bookkeeping/innovatiebox/ip-activa/:id', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/innovatiebox/ip-activa/none')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-detail-page-header"]'),
			'the IP-activum route must mount CnDetailPage, not fall through to the Dashboard',
		).toBeVisible({ timeout: 15_000 })
	})
})
