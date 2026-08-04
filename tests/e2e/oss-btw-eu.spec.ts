/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq OSS (One-Stop-Shop) SPA smoke.
 *
 * The OSS pipeline adds two declarative manifest navigation entries
 * (`OssRegistration` → "OSS Registration", `OssReturns` → "OSS Returns",
 * both children of the Bookkeeping group in
 * `src/manifest.d/bookkeeping-btw-oss-eu.json`, neither listed in
 * `src/menu-layout.json` `removals`) rendered by the nextcloud-vue manifest
 * shell. This smoke confirms those entries are actually in the rendered menu
 * and that their index pages mount.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * The test ended at `expect(page.url()).toContain('/apps/shillinq')` — twice.
 * `appinfo/routes.php` delegates to `\OCA\OpenRegister\AppHost\Routes::standard()`,
 * whose catch-all (`'/{path}'`, `requirements: ['path' => '.+']`) answers every
 * path under `/apps/shillinq/` with the same `TemplateResponse`, so no
 * navigation to a `/apps/shillinq/...` URL can ever leave that prefix. The
 * assertion was therefore true even with `js/shillinq-main.js` truncated to
 * 0 bytes (CI 30881746678's control), i.e. with the SPA never booting and the
 * menu never rendering.
 *
 * The replacement asserts the two claims the docblock actually makes:
 *  - the OSS nav entries exist in the rendered menu (`cn-nav-entry-<page id>`,
 *    the lib's own stable testid), and
 *  - `/bookkeeping/oss/returns` resolves to ITS page — proven by the pairing
 *    of `gotoPage()`'s settled-path assertion (the requested path must still
 *    be the current one, which is what catches `src/main.js`'s
 *    `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })` silently
 *    rewriting an undeclared route to the Dashboard) with CnIndexPage's own
 *    root inside `#app-content-vue` (NcAppContent's `<main>`).
 *
 * ⚠️ DO NOT ASSERT THE PAGE TITLE INSIDE `#app-content-vue` — IT IS NOT THERE.
 * An earlier revision of this file asserted
 * `#app-content-vue [data-testid="cn-page-title"]`. `CnPageHeader` does emit
 * that `<h1>`, but `CnIndexPage.vue:12` renders CnPageHeader behind
 * `v-if="showTitle"` and `showTitle` defaults to FALSE ("When false (default),
 * the title is shown in the sidebar header instead"). `CnPageRenderer.vue`
 * never passes `show-title`, and all six `showTitle` occurrences in
 * `src/manifest.json` set it to false — so `cn-page-title` renders on NO
 * shillinq index page. That is also why `spec-coverage/_helpers.ts` keeps its
 * title check soft and matching the SIDEBAR. Run 30894384122 turned that
 * mistake into 69 false failures.
 *
 * The full OSS end-to-end flows (REQ-OSS-001 invoice rate resolution, REQ-OSS-004
 * quarterly return generation + XSD download, REQ-OSS-008 payment reconciliation,
 * REQ-OSS-009 voluntary opt-in lock-in) are @e2e excluded here: they require a
 * live OpenRegister instance seeded with EuVatRate TEDB data + OSS registrations
 * and a posted-invoice fixture, which the implementing cycle wires once the
 * register fragment is imported into a running instance. No @e2e scenario tags are
 * emitted by this smoke.
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — OSS SPA smoke', () => {
	test('OSS navigation entries are present in the rendered manifest menu', async ({ page }) => {
		await gotoPage(page, '/')

		// The lib renders every menu item as
		// `data-testid="cn-nav-entry-${item.id}"`. Both ids come straight from
		// the OSS fragment's `menu[].children[]`. `toBeAttached` (not
		// `toBeVisible`) because a nav entry inside a collapsed group is
		// present-but-hidden — the claim under test is "declared and rendered",
		// not "expanded".
		await expect(
			page.locator('[data-testid="cn-nav-entry-OssRegistration"]'),
			'the OSS Registration nav entry must be rendered by the manifest shell',
		).toBeAttached({ timeout: 10_000 })
		await expect(
			page.locator('[data-testid="cn-nav-entry-OssReturns"]'),
			'the OSS Returns nav entry must be rendered by the manifest shell',
		).toBeAttached({ timeout: 10_000 })
	})

	test('OSS Returns index page mounts on /bookkeeping/oss/returns', async ({ page }) => {
		await gotoPage(page, '/bookkeeping/oss/returns')

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		// `cn-index-page` is CnIndexPage's own root div (CnIndexPage.vue:2) —
		// no `v-if`, so it is present on an empty (unseeded) index too and this
		// stays data-independent. The Dashboard renders `cn-dashboard-page`
		// instead, so a catch-all fallback cannot satisfy this either.
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the OSS Returns route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})
})
