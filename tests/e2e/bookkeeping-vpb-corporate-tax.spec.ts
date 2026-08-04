/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq bookkeeping-vpb-corporate-tax
 * tax deadline list + payment list SPA smoke (REQ-VPB-005, REQ-VPB-006,
 * REQ-VPB-007, REQ-VPB-013, REQ-VPB-016).
 *
 * The change ships a "Corporate tax (Vpb)" menu group with four index pages —
 * Tax deadlines, Tax payments, Quarterly statement, Vpb settings — plus the
 * deadline/payment detail pages. All pages are declarative (manifest-v2),
 * rendered by the @conduction/nextcloud-vue manifest shell from
 * `src/manifest.d/bookkeeping-vpb-corporate-tax.json`; there is NO custom
 * Vue / router for the deadline + payment surfaces.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * Every test ended at `expect(page.url()).toContain('/apps/shillinq')`, three
 * of them adding `expect(page).toHaveTitle(/shillinq/i)`. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix, so the "bounce-out
 *    guard" the comments describe was never guarding anything.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and all five tests still passed.
 * The REQ-VPB-016 test additionally did
 * `await vpbLink.waitFor({ state: 'attached' }).catch(() => {})` and then
 * asserted only the URL — the lookup result was swallowed and discarded.
 *
 * The replacement, per route: `gotoPage()` waits for `#content-vue` (which
 * exists only after `app.mount('#shillinq-app')`) and asserts the SETTLED path
 * equals the requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently
 * rewriting an undeclared route to the Dashboard. Then a page-type-specific
 * surface must have rendered inside `#app-content-vue` (NcAppContent's
 * `<main>`; never `#content-vue`, which also wraps the sidebar that is
 * identical on all ~107 pages):
 *  - index pages assert CnIndexPage's own root div
 *    (`data-testid="cn-index-page"`, `CnIndexPage.vue:2`, no `v-if`), which
 *    renders on an empty index too, so the check is data-independent;
 *
 *    ⚠️ NOT the page title: an earlier revision asserted
 *    `#app-content-vue [data-testid="cn-page-title"]`. `CnPageHeader` does
 *    emit that `<h1>`, but `CnIndexPage.vue:12` renders CnPageHeader behind
 *    `v-if="showTitle"` and `showTitle` defaults to FALSE ("When false
 *    (default), the title is shown in the sidebar header instead").
 *    `CnPageRenderer.vue` never passes `show-title`, and all six `showTitle`
 *    occurrences in `src/manifest.json` set it to false — so `cn-page-title`
 *    renders on NO shillinq index page. That is also why
 *    `spec-coverage/_helpers.ts` keeps its title check soft and matching the
 *    SIDEBAR. Run 30894384122 turned that mistake into 69 false failures;
 *  - detail routes are requested with the synthetic id `none`, so no object
 *    resolves and CnDetailPage's title falls back to the object display name;
 *    the stable proof that the DETAIL renderer mounted is its unconditional
 *    `data-testid="cn-detail-page-header"` root.
 * REQ-VPB-016 now asserts the nav entry itself, using the lib's stable
 * `cn-nav-entry-<page id>` testid (see `chart-of-accounts.spec.ts` for the
 * same pattern) instead of a five-way OR whose result was thrown away.
 *
 * The behavioural acceptance — REQ-VPB-005 search/filter/bulk round-trip on
 * real deadline rows, REQ-VPB-006 detail-page audit-trail append, REQ-VPB-008
 * payment reconciliation against GL postings, and REQ-VPB-013 7-day / 1-day
 * reminder notification surfacing in the Nextcloud notification panel — is
 * @e2e excluded here: live UI exercise requires a running OpenRegister with
 * the bookkeeping-vpb-corporate-tax register fragment imported, seed
 * TaxDeadline + TaxPaymentTracking objects, a paired GL feed for
 * reconciliation, and the TaxNotificationService background job triggered,
 * none of which the build sandbox provides. That acceptance is covered by the
 * PHPUnit suites authored under Tasks 39–41.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-42
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — bookkeeping-vpb-corporate-tax deadline + payment SPA smoke', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('Tax deadlines index — mounts on /bookkeeping/vpb/deadlines (REQ-VPB-005)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/deadlines')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Tax deadlines route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Tax deadline detail — mounts CnDetailPage on /bookkeeping/vpb/deadlines/:id (REQ-VPB-006)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/deadlines/none')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-detail-page-header"]'),
			'the deadline detail route must mount CnDetailPage, not fall through to the Dashboard',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Tax payments index — mounts on /bookkeeping/vpb/payments (REQ-VPB-007)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/payments')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Tax payments route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Tax payment detail — mounts CnDetailPage on /bookkeeping/vpb/payments/:id (REQ-VPB-008)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/payments/none')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-detail-page-header"]'),
			'the payment detail route must mount CnDetailPage, not fall through to the Dashboard',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Vpb navigation cluster is reachable from the shillinq shell (REQ-VPB-016)', async ({ page }) => {
		await gotoPage(page, '/')

		// The lib renders each menu item as `data-testid="cn-nav-entry-${id}"`.
		// The cluster's own `Vpb` id is deliberately NOT asserted: it is a
		// relocation SOURCE (`"Vpb": "Belastingen"` in `src/menu-layout.json`),
		// and the lib's `applyMenuRelocations()` dissolves a relocated group —
		// its children merge into the target and the empty shell is dropped —
		// so `cn-nav-entry-Vpb` legitimately does not exist. Its three surviving
		// leaves do: none of `VpbDeadlines` / `VpbPayments` / `VpbSettings`
		// appears in that file's `removals` list (`VpbReports` does, and is
		// therefore correctly absent from this list). `toBeAttached` rather than
		// `toBeVisible`: an entry inside a collapsed group is present-but-hidden,
		// and the claim under test is "declared and rendered", not "expanded".
		for (const id of ['VpbDeadlines', 'VpbPayments', 'VpbSettings']) {
			await expect(
				page.locator(`[data-testid="cn-nav-entry-${id}"]`),
				`the ${id} nav entry must be rendered by the manifest shell`,
			).toBeAttached({ timeout: 10_000 })
		}
	})
})
