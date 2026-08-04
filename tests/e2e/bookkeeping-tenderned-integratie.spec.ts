/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq bookkeeping-tenderned-integratie
 * SPA smoke (REQ-001, REQ-002, REQ-008).
 *
 * `src/manifest.d/20-tenderned-integratie.json` ships three index pages and
 * their detail pages under the `Purchasing` menu group:
 *   /inkoop/tenderned        (index)  "TenderNed tenders"  id TenderNedAanbestedingen
 *   /inkoop/verplichtingen   (index)  "Commitments"        id Verplichtingen
 *   /inkoop/mijn-contracten  (index)  "My contracts"       id MijnContracten
 * All are declarative (manifest-v2), rendered by the @conduction/nextcloud-vue
 * manifest shell; there is NO custom Vue / router for this change.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * Every test ended at `expect(page.url()).toContain('/apps/shillinq')`, three
 * of them adding `expect(page).toHaveTitle(/shillinq/i)`. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and all four tests still passed.
 * The navigation test additionally did
 * `await inkoopLink.waitFor({ state: 'attached' }).catch(() => {})` and then
 * asserted only the URL — the lookup result was swallowed and discarded, so
 * the nav entry it claimed to check was never checked.
 *
 * The replacement, per route: `gotoPage()` waits for `#content-vue` (which
 * exists only after `app.mount('#shillinq-app')`) and asserts the SETTLED path
 * equals the requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently
 * rewriting an undeclared route to the Dashboard. Then CnIndexPage's own root
 * div (`data-testid="cn-index-page"`, `CnIndexPage.vue:2`, no `v-if`) must
 * have rendered inside `#app-content-vue` (NcAppContent's `<main>`; never
 * `#content-vue`, which also wraps the sidebar that is identical on all ~107
 * pages). It renders on an empty index too, so the check is data-independent;
 * and the Dashboard renders `cn-dashboard-page`, so a catch-all fallback
 * cannot satisfy it.
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
 * The behavioural acceptance — REQ-002 auto-promotion, REQ-004 bewijsstuk
 * gate, REQ-006 status-sync — is covered by the PHPUnit Guard + listener tests
 * + the Newman API collection and is @e2e excluded here: a live UI exercise
 * requires openconnector to feed the `tenderned.award.detected` CloudEvent + a
 * seeded tenant KvK + a vendor-isolated session, which the build sandbox does
 * not provide.
 *
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-10-4
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — bookkeeping-tenderned-integratie SPA smoke', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('TenderNed Aanbestedingen index — mounts on /inkoop/tenderned', async ({ page }) => {
		await gotoPage(page, '/inkoop/tenderned')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the TenderNed tenders route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Verplichtingen index — mounts on /inkoop/verplichtingen', async ({ page }) => {
		await gotoPage(page, '/inkoop/verplichtingen')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Commitments route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Mijn Contracten index — mounts on /inkoop/mijn-contracten (REQ-008)', async ({ page }) => {
		// Mijn Contracten is the bron=tenderned filtered view consumed by
		// inschrijvers (REQ-008). The manifest declares `config.filters.bron`
		// = "tenderned" so vendors only see their own contracted obligations.
		await gotoPage(page, '/inkoop/mijn-contracten')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the My contracts route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Inkoop navigation entries are reachable from the shillinq shell', async ({ page }) => {
		await gotoPage(page, '/')

		// The lib renders each menu item as `data-testid="cn-nav-entry-${id}"`
		// (see `chart-of-accounts.spec.ts` for the same pattern). Only the two
		// ids that survive `src/menu-layout.json` are asserted: `Verplichtingen`
		// IS listed in that file's `removals` (its PAGE stays routable — proven
		// by the test above — but its menu leaf is retired on purpose), whereas
		// `TenderNedAanbestedingen` and `MijnContracten` are not. `toBeAttached`
		// rather than `toBeVisible`: an entry inside a collapsed group is
		// present-but-hidden, and the claim under test is "declared and
		// rendered", not "expanded".
		for (const id of ['TenderNedAanbestedingen', 'MijnContracten']) {
			await expect(
				page.locator(`[data-testid="cn-nav-entry-${id}"]`),
				`the ${id} nav entry must be rendered by the manifest shell`,
			).toBeAttached({ timeout: 10_000 })
		}
	})
})
