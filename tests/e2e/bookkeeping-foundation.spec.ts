/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — bookkeeping foundation (T1).
 *
 * Covers the three navigation surfaces declared by
 * `add-shillinq-bookkeeping-foundation` (routes + manifest titles read from
 * `src/manifest.json`):
 *   - /chart-of-accounts  "Chart of Accounts"  (Grootboekschema)  — REQ-CoA-008
 *   - /general-ledger     "General Ledger"     (Grootboek)        — REQ-GL-007
 *   - /journals           "Manual Journals"    (Journaalposten)   — REQ-JE-009
 *
 * The change is `kind: config` (declarative — register schemas + manifest
 * entries + RGS seeds); there are no bespoke Vue components. Rendering is done
 * by `@conduction/nextcloud-vue`'s generic `CnIndexPage` per ADR-024 Tier-4.
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
 * The REQ-JE-009 navigation test additionally did
 * `await journalsLink.waitFor({ state: 'attached' }).catch(() => {})` and then
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
 * pages).
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
 * This stays a UI-only smoke and stays data-independent: `cn-index-page` has
 * no `v-if`, so an index that mounts EMPTY (dev-container topologies where the
 * RGS template has not been seeded) still satisfies it, while the Dashboard —
 * which renders `cn-dashboard-page` instead — does not.
 * The assertion is "this page's own surface rendered", not "the list has N
 * rows". The declarative requirements (schema field types, lifecycle
 * transitions, cadence object shape, approval-gate behaviour) are covered by
 * the PHPUnit `JournalEntrySchemaTest` / `JournalEntryGuardTest` suites
 * already shipped with the change.
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('bookkeeping-foundation — Tier-1 manifest pages', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('Chart of Accounts (Grootboekschema) — index page mounts on /chart-of-accounts', async ({ page }) => {
		await gotoPage(page, '/chart-of-accounts')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Chart of Accounts route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('General Ledger (Grootboek) — index page mounts on /general-ledger', async ({ page }) => {
		await gotoPage(page, '/general-ledger')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the General Ledger route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Journals (Journaalposten) — index page mounts on /journals', async ({ page }) => {
		await gotoPage(page, '/journals')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Manual Journals route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Journals navigation entry is reachable from the Shillinq shell (REQ-JE-009)', async ({ page }) => {
		await gotoPage(page, '/')

		// The lib renders each menu item as `data-testid="cn-nav-entry-${id}"`
		// (see `chart-of-accounts.spec.ts` for the same pattern). `Journals` is
		// declared once in `src/manifest.json`'s menu and is NOT in
		// `src/menu-layout.json` `removals`, so it must be present in the
		// rendered menu. `toBeAttached` rather than `toBeVisible`: the entry
		// lives inside the "People & Projects" group, and an entry in a
		// collapsed group is present-but-hidden — the claim under test is
		// "declared and rendered", not "expanded".
		await expect(
			page.locator('[data-testid="cn-nav-entry-Journals"]'),
			'the Journals nav entry must be rendered by the manifest shell',
		).toBeAttached({ timeout: 10_000 })
	})
})
