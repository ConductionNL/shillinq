/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq IFRS 15 Revenue Recognition SPA smoke.
 *
 * `src/manifest.d/bookkeeping-ifrs15-revenue.json` declares six index pages and
 * one Contract detail page. This spec confirms each of the six index routes
 * mounts ITS OWN page inside the @conduction/nextcloud-vue manifest shell.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * Every test ended at `expect(page.url()).toContain('/apps/shillinq')` plus
 * `expect(page).toHaveTitle(/shillinq/i)`. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and all five tests still passed.
 * Because both assertions are identical across all six routes, nothing here
 * distinguished one IFRS 15 page from another — or from the Dashboard.
 *
 * The replacement, per route: `gotoPage()` waits for `#content-vue` (which
 * exists only after `app.mount('#shillinq-app')`) and asserts the SETTLED path
 * equals the requested one — the check that catches `src/main.js`'s
 * `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently
 * rewriting an undeclared route to the Dashboard. Then CnPageHeader's
 * `cn-page-title` `<h1>` inside `#app-content-vue` (NcAppContent's `<main>`;
 * never `#content-vue`, which also wraps the sidebar that is identical on all
 * ~107 pages) must read that page's own manifest title. CnIndexPage renders
 * that header unconditionally, so the assertion holds on an unseeded
 * instance — it is data-independent, not data-dependent.
 *
 * The five Browser-Test items in tasks.md (contract entry form validation +
 * SSP auto-allocate, 60-month waterfall chart + segment filter, contract-
 * balance bar chart drill-down, variable-consideration re-estimation modal
 * workflow, disclosure pack viewer with PDF/XBRL export) remain covered here
 * only as page-mount checks: the heavy detail flows require a live
 * OpenRegister instance seeded with Contract / PerformanceObligation /
 * PriceAllocation / RevenueRecognitionEvent fixtures across two fiscal
 * periods and the T4 PDF/XBRL exporter, both of which the implementing cycle
 * wires once the register fragment is imported into a running instance.
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#browser-tests
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

/**
 * Route → manifest title, read from
 * `src/manifest.d/bookkeeping-ifrs15-revenue.json`. `req` names the
 * requirement whose Browser-Test item this page-mount check stands in for.
 */
const PAGES = [
	{ route: '/ifrs-15/contracts', title: /^\s*Contracts\s*$/i, req: 'REQ-IFRS15-001', label: 'Contract entry' },
	{ route: '/ifrs-15/revenue-waterfall', title: /Revenue Waterfall/i, req: 'REQ-IFRS15-008', label: 'Revenue Waterfall' },
	{ route: '/ifrs-15/contract-balances', title: /Contract Balances/i, req: 'REQ-IFRS15-007', label: 'Contract Balances' },
	{ route: '/ifrs-15/contract-modifications', title: /Contract Modifications/i, req: 'REQ-IFRS15-006', label: 'Contract Modifications' },
	{ route: '/ifrs-15/contract-cost-assets', title: /Contract Cost Assets/i, req: 'REQ-IFRS15-009', label: 'Contract Cost Assets' },
	{ route: '/ifrs-15/performance-obligations', title: /Performance Obligations/i, req: 'REQ-IFRS15-009', label: 'Performance Obligations' },
] as const

test.describe('shillinq — IFRS 15 Revenue Recognition SPA smoke', () => {
	for (const p of PAGES) {
		test(`${p.label} index mounts on ${p.route} (${p.req})`, async ({ page }) => {
			await gotoPage(page, p.route)

			await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
			await expect(
				page.locator('#app-content-vue [data-testid="cn-page-title"]'),
				`${p.route} must render its own manifest title`,
			).toHaveText(p.title, { timeout: 15_000 })
		})
	}
})
