/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq ICP-opgaaf SPA smoke (REQ-ICP-003,
 * REQ-ICP-010).
 *
 * `src/manifest.json` declares the ICP-opgaaf index at `/belastingen/icp-opgaaf`
 * (title "ICP-opgaaf") and its detail at `/belastingen/icp-opgaaf/:id`, rendered
 * by the nextcloud-vue manifest shell. This smoke confirms the index page
 * actually mounts.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * The test ended at `expect(page.url()).toContain('/apps/shillinq')` — twice.
 * `appinfo/routes.php` delegates to `\OCA\OpenRegister\AppHost\Routes::standard()`,
 * whose catch-all (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY
 * path under `/apps/shillinq/` with the same `TemplateResponse`, so a navigation
 * to a `/apps/shillinq/...` URL cannot leave that prefix no matter what happens.
 * On CI 30881746678 the control truncated `js/shillinq-main.js` to 0 bytes so
 * the SPA could not boot at all, and this test still passed.
 *
 * ⚠️ THE TITLE OF THIS TEST CHANGED, DELIBERATELY.
 * It used to claim "ICP-opgaaf navigation entry is reachable in the manifest
 * shell" while never looking at the navigation. It cannot look at it either:
 * `IcpOpgaaf` is listed in `src/menu-layout.json` `removals` (index 55), so the
 * lib's `applyMenuRemovals()` strips that leaf from the rendered menu on
 * purpose — its PAGE stays routable for deep links, which is exactly what is
 * asserted below. Asserting a nav entry here would assert a retired one.
 *
 * The replacement proves the SPA booted and that THIS page rendered:
 * `gotoPage()` waits for `#content-vue` (which exists only after
 * `app.mount('#shillinq-app')`) and asserts the settled path equals the
 * requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently rewriting
 * an undeclared route to the Dashboard. The page assertion then reads
 * CnIndexPage's own root inside `#app-content-vue` (NcAppContent's `<main>`),
 * never `#content-vue`, which also wraps the sidebar that is identical on all
 * ~107 pages.
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
 * The full ICP end-to-end flows — invoice ICP-context tagging
 * (REQ-ICP-001 / REQ-ICP-007), VIES validation round-trip
 * (REQ-ICP-001 / REQ-ICP-009), ICP ledger view with period selector +
 * buyer drill-down (REQ-ICP-003), filing finalization with reconciliation
 * gate against rubriek 3b (REQ-ICP-004 / REQ-ICP-005), correction filing
 * (REQ-ICP-008), Digipoort submission, and audit-trail ZIP export
 * (REQ-ICP-010) — are @e2e excluded here: they require a live OpenRegister
 * instance seeded with ARInvoice + CustomerMaster + ViesValidation +
 * IcpSupply + VatReturn fixtures, the openconnector VIES + Digipoort
 * integrations (out of this app's scope per fragment _meta), and the
 * docudesk file surface for source-invoice PDF attachment. The implementing
 * cycle wires those once the live instance has the upstream services. No
 * @e2e scenario tags are emitted by this smoke.
 *
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — ICP-opgaaf SPA smoke', () => {
	test('ICP-opgaaf index page mounts on /belastingen/icp-opgaaf', async ({ page }) => {
		await gotoPage(page, '/belastingen/icp-opgaaf')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		// `cn-index-page` is CnIndexPage's own root div (CnIndexPage.vue:2) —
		// no `v-if`, so it is present on an empty (unseeded) index too and this
		// stays data-independent. The Dashboard renders `cn-dashboard-page`
		// instead, so a catch-all fallback cannot satisfy this either.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the ICP-opgaaf route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})
})
