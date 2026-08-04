/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq bookkeeping-vpb-corporate-tax
 * quarterly tax statement SPA smoke (REQ-VPB-009, REQ-VPB-010, REQ-VPB-011,
 * REQ-VPB-012, REQ-VPB-014, REQ-VPB-015).
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * All three tests ended at `expect(page.url()).toContain('/apps/shillinq')`
 * plus `expect(page).toHaveTitle(/shillinq/i)`. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix, so the "bounce-out
 *    guard" the old comments describe was never guarding anything.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and all three tests still passed.
 *
 * ⚠️ THE `:fiscalYear/:quarter` ROUTE IS NOT DECLARED — SEE THE TEST BELOW.
 *
 * The replacement: `gotoPage()` waits for `#content-vue` (which exists only
 * after `app.mount('#shillinq-app')`) and asserts the SETTLED path equals the
 * requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently
 * rewriting an undeclared route to the Dashboard. Then CnPageHeader's
 * `cn-page-title` `<h1>` inside `#app-content-vue` (NcAppContent's `<main>`;
 * never `#content-vue`, which also wraps the sidebar that is identical on all
 * ~107 pages) must read the page's own manifest title. CnIndexPage and
 * CnSettingsPage both render that header unconditionally, so the check holds
 * on an unseeded instance.
 *
 * The behavioural acceptance — REQ-VPB-009 quarterly aggregation correctness,
 * REQ-VPB-010 untagged-posting warning surfacing on tax-relevant accounts,
 * REQ-VPB-011 Excel/PDF export through CnMassExportDialog, REQ-VPB-012 annual
 * summary variance against provisional payments — is @e2e excluded here: live
 * UI exercise requires a running OpenRegister with the register fragment
 * imported, seeded GL postings spanning a fiscal quarter with mixed
 * `taxTreatment` tags, a paired TaxDeadline + TaxPaymentTracking dataset for
 * the variance roll-up, and a working ExportService binding — none of which
 * the build sandbox provides. That acceptance is covered by the PHPUnit suites
 * authored under Task 41.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-43
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — bookkeeping-vpb-corporate-tax quarterly statement SPA smoke', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('Quarterly statement index — mounts on /bookkeeping/vpb/reports (REQ-VPB-009)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/reports')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-page-title"]'),
			'the Quarterly statement index must render its own manifest title',
		).toHaveText(/Quarterly statement/i, { timeout: 15_000 })
	})

	/**
	 * ⚠️ FILED DEFECT — THIS TEST IS EXPECTED TO FAIL. DO NOT SKIP IT.
	 *
	 * The route `/bookkeeping/vpb/reports/:fiscalYear/:quarter`, which this
	 * file's header and REQ-VPB-009 both describe as the quarterly statement
	 * view (CnDetailPage backed by
	 * `TaxReportController::getQuarterlyStatement()`), is declared NOWHERE in
	 * the manifest. `src/manifest.d/bookkeeping-vpb-corporate-tax.json` ships
	 * exactly one `/bookkeeping/vpb/reports` page (`id: VpbReports`, `type:
	 * index`) and no detail page beneath it.
	 *
	 * POSITIVE CONTROL for that absence claim — the same lookup over the same
	 * file DOES find the sibling detail routes, so the method is not simply
	 * failing to find things:
	 *     /bookkeeping/vpb/deadlines      → VpbDeadlines      (index)
	 *     /bookkeeping/vpb/deadlines/:id  → VpbDeadlineDetail (detail)   ✔ found
	 *     /bookkeeping/vpb/payments       → VpbPayments       (index)
	 *     /bookkeeping/vpb/payments/:id   → VpbPaymentDetail  (detail)   ✔ found
	 *     /bookkeeping/vpb/reports        → VpbReports        (index)    ✔ found
	 *     /bookkeeping/vpb/reports/:…     →                              ✘ ABSENT
	 *
	 * Consequence: `src/main.js`'s `/:pathMatch(.*)*` catch-all redirects the
	 * deep link to `/`, so every previous run of this test was asserting
	 * against the DASHBOARD while reporting REQ-VPB-009 as covered. The test is
	 * left asserting the real contract so the gap stays visible; the fix is a
	 * manifest page, not a test change.
	 */
	test('Quarterly statement detail — mounts on /bookkeeping/vpb/reports/:year/:quarter (REQ-VPB-009)', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/vpb/reports/2026/1')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-detail-page-header"]'),
			'the quarterly statement detail route must mount CnDetailPage, not fall through to the Dashboard',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Vpb settings — mounts on /bookkeeping/vpb/settings (REQ-VPB-014, REQ-VPB-015)', async ({ page }) => {
		// Hosts deadline-template configuration plus the tax-treatment tag
		// configuration that drives the untagged-posting warning on the
		// quarterly report. `type: "settings"` resolves to CnSettingsPage,
		// which — like CnIndexPage — renders CnPageHeader unconditionally.
		await gotoPage(page, '/bookkeeping/vpb/settings')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-page-title"]'),
			'the Vpb settings page must render its own manifest title',
		).toHaveText(/Vpb settings/i, { timeout: 15_000 })
	})
})
