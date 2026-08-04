/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inventory Mobile Scanner — Playwright UI coverage (T6.2, T6.5).
 *
 * Asserts that each of the five warehouse PWA pages MOUNTS ITS OWN COMPONENT
 * — not merely that the browser stayed on a `/apps/shillinq` URL.
 *
 * ⚠️ WHY THE OLD ASSERTION WAS A CONSTANT
 * ---------------------------------------
 * Each test used to end at `expect(page.url()).toContain('/inventory/mobile…')`.
 * Two independent layers make that true regardless of whether anything works:
 *
 *  1. `appinfo/routes.php` delegates to `\OCA\OpenRegister\AppHost\Routes::standard()`,
 *     whose catch-all (`'/{path}'`, `requirements: ['path' => '.+']`) answers
 *     EVERY path under `/apps/shillinq/` with the same `TemplateResponse`.
 *     There is no 404 path, so the HTTP layer can never contradict the URL.
 *  2. `page.goto()` + `waitForLoadState('domcontentloaded')` returns as soon as
 *     the 8-line `templates/index.php` shell parses. `expect(page.url())` then
 *     re-reads the address bar. With `js/shillinq-main.js` truncated to 0 bytes
 *     — the control run on CI 30881746678 — the SPA never boots, vue-router
 *     never runs, the URL never changes, and all five tests still pass.
 *
 * The replacement proves the Vue SPA booted AND that THIS page's component is
 * what rendered:
 *  - `gotoPage()` (tests/e2e/spec-coverage/_helpers.ts) waits for `#content-vue`
 *    — which exists only after `app.mount('#shillinq-app')` — and asserts the
 *    SETTLED path equals the requested one. That matters because `src/main.js`
 *    ends its route table with `routes.push({ path: '/:pathMatch(.*)*',
 *    redirect: '/' })`, so an undeclared route is silently rewritten to the
 *    Dashboard.
 *  - the page's own root element inside `#app-content-vue` (NcAppContent's
 *    `<main>`). `#content-vue` is NOT used: it also wraps `#app-navigation-vue`,
 *    whose sidebar is identical on all ~107 pages.
 *  - the page's own `<h1>`. These pages are custom Vue components, so the
 *    heading comes from `t('shillinq', …)`; the matcher accepts the English
 *    source string or its `l10n/nl.json` translation. Both spellings name THIS
 *    page and no other, so the matcher is locale-tolerant, not loose.
 *
 * Camera + IndexedDB exercises live in the unit tests (Jest/PHPUnit);
 * Playwright stays UI-only per the fleet rule.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

/**
 * One row per manifest page in `src/manifest.d/inventory-mobile-scanner.json`.
 * `root` is the page component's own top-level class (MobileScannerHome.vue,
 * ReceivePage.vue, TransferPage.vue, PickPage.vue, CountPage.vue) — a marker
 * no other shillinq page renders. `heading` matches the `<h1>` those pages (or
 * the Op component they host) print, in either shipped locale.
 */
const PAGES = [
	{
		name: 'mobile scanner home',
		route: '/inventory/mobile',
		root: '.mobile-scanner-home',
		heading: /Inventory Mobile Scanner|Mobiele voorraadscanner/i,
		tag: 'inventory-mobile-scanner/REQ-UI-003/home-route-mounts',
	},
	{
		name: 'receive',
		route: '/inventory/mobile/receive',
		root: '.receive-page',
		heading: /Receive Goods|Goederen ontvangen/i,
		tag: 'inventory-mobile-scanner/REQ-INVENTORY-001/receive-route-mounts',
	},
	{
		name: 'transfer',
		route: '/inventory/mobile/transfer',
		root: '.transfer-page',
		heading: /Transfer Inventory|Voorraad overdragen/i,
		tag: 'inventory-mobile-scanner/REQ-INVENTORY-002/transfer-route-mounts',
	},
	{
		name: 'pick',
		route: '/inventory/mobile/pick',
		root: '.pick-page',
		heading: /Pick for Order|Picken voor order/i,
		tag: 'inventory-mobile-scanner/REQ-INVENTORY-003/pick-route-mounts',
	},
	{
		name: 'count',
		route: '/inventory/mobile/count',
		root: '.count-page',
		heading: /Inventory Count|Voorraadtelling/i,
		tag: 'inventory-mobile-scanner/REQ-INVENTORY-004/count-route-mounts',
	},
] as const

test.describe('inventory mobile scanner — manifest pages mount', () => {

	/**
	 * @e2e inventory-mobile-scanner/REQ-UI-003/home-route-mounts
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-001/receive-route-mounts
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-002/transfer-route-mounts
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-003/pick-route-mounts
	 * @e2e inventory-mobile-scanner/REQ-INVENTORY-004/count-route-mounts
	 */
	for (const p of PAGES) {
		test(`${p.name} route mounts its own component (${p.route})`, async ({ page }) => {
			// Boots the SPA, dismisses overlays, and asserts the settled path is
			// still `p.route` (i.e. vue-router matched a DECLARED route instead
			// of falling through the catch-all to the Dashboard).
			await gotoPage(page, p.route)

			// NcAppContent's `<main>`. Only exists once `app.mount()` ran.
			await page.waitForSelector('#app-content-vue', { timeout: 15_000 })

			// This page's own root element — not the sidebar, not the shell.
			await expect(
				page.locator(`#app-content-vue ${p.root}`),
				`${p.route} must render its own page component root (${p.root})`,
			).toBeVisible({ timeout: 15_000 })

			// …and this page's own heading.
			await expect(
				page.locator('#app-content-vue'),
				`${p.route} must render its own page heading`,
			).toContainText(p.heading)
		})
	}

})
