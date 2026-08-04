/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — bookkeeping-period-close (T2).
 *
 * Covers the two pages declared by `src/manifest.d/bookkeeping-period-close.json`
 * (REQ-PC-005, REQ-PC-007):
 *   /bookkeeping/period-close      (index)  "Period Close"  id PeriodClose
 *   /bookkeeping/period-close/:id  (custom) "Period Close"  → PeriodCloseDetail
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * Both tests asserted `expect(page.url()).toContain('/apps/shillinq')` plus
 * `expect(page).toHaveTitle(/shillinq/i)`. Neither can fail:
 *  - `appinfo/routes.php` delegates to
 *    `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 *    (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY path under
 *    `/apps/shillinq/` with the same `TemplateResponse` — a navigation to a
 *    `/apps/shillinq/...` URL can never leave that prefix, and there is no 404
 *    path for the detail test's "without falling back to a 404" claim to
 *    detect.
 *  - the `<title>` is server-rendered by Nextcloud's
 *    `core/templates/layout.user.php` from the app id BEFORE any JavaScript
 *    runs. On CI 30881746678 the control truncated `js/shillinq-main.js` to
 *    0 bytes; the SPA never booted and both tests still passed.
 * The detail test's only real observation was
 * `await expect(any).toBeVisible({ timeout: 15_000 }).catch(() => {})` — the
 * `.catch` discarded the verdict, so the test both could not fail AND burned
 * 15 s of wall clock doing it. That `.catch` is removed below: the four
 * `period-close-detail*` testids are exactly the markers `PeriodCloseDetail`
 * renders on its loading, error and loaded paths, so at least one of them
 * MUST appear if the component mounted at all.
 *
 * The replacement also adds route-resolution proof: `gotoPage()` waits for
 * `#content-vue` (which exists only after `app.mount('#shillinq-app')`) and
 * asserts the SETTLED path equals the requested one — the check that catches
 * `src/main.js`'s `routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })`
 * silently rewriting an undeclared route to the Dashboard. Assertions are
 * scoped to `#app-content-vue` (NcAppContent's `<main>`), never `#content-vue`,
 * which also wraps the sidebar that is identical on all ~107 pages.
 *
 * ⚠️ DO NOT ASSERT THE PAGE TITLE INSIDE `#app-content-vue` — IT IS NOT THERE.
 * An earlier revision asserted `#app-content-vue [data-testid="cn-page-title"]`
 * on the index. `CnPageHeader` does emit that `<h1>`, but `CnIndexPage.vue:12`
 * renders CnPageHeader behind `v-if="showTitle"` and `showTitle` defaults to
 * FALSE ("When false (default), the title is shown in the sidebar header
 * instead"). `CnPageRenderer.vue` never passes `show-title`, and all six
 * `showTitle` occurrences in `src/manifest.json` set it to false — so
 * `cn-page-title` renders on NO shillinq index page. That is also why
 * `spec-coverage/_helpers.ts` keeps its title check soft and matching the
 * SIDEBAR. Run 30894384122 turned that mistake into 69 false failures.
 *
 * This stays a UI-only smoke and stays data-independent: the detail id below
 * need not exist — `PeriodCloseDetail` renders its error marker on the
 * "Period not found" path, which is a mount just as much as the loaded path
 * is. The declarative requirements (lifecycle state machine,
 * GLTransaction.post precondition, role gates) are covered by the PHPUnit
 * `PeriodCloseServiceTest` / `PeriodCloseGuardTest` /
 * `PeriodCloseControllerTest` / `PeriodCloseFragmentTest` suites already
 * shipped with the change.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-16
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

const INDEX_PATH = '/bookkeeping/period-close'
const DETAIL_PATH = '/bookkeeping/period-close/period-close-smb-2026-03'

test.describe('bookkeeping-period-close — Tier-2 manifest pages', () => {
	test.beforeEach(async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 })
	})

	test('Period Close index — page mounts on /bookkeeping/period-close', async ({ page }) => {
		await gotoPage(page, INDEX_PATH)

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-index-page"]'),
			'the Period Close route must mount CnIndexPage in the content region',
		).toBeVisible({ timeout: 15_000 })
	})

	test('Period Close detail — custom component mounts on /bookkeeping/period-close/:id', async ({ page }) => {
		await gotoPage(page, DETAIL_PATH)

		await page.waitForSelector('#app-content-vue', { timeout: 15_000 })

		// The PeriodCloseDetail custom component renders one of these markers on
		// every path it can take — loading, error ("Period not found"), or a
		// populated header. Which one is data-dependent; that AT LEAST ONE of
		// them appears is not, and is the proof the SPA dispatched this route to
		// our registered component rather than to the Dashboard.
		//
		// The `.catch(() => {})` that used to follow this expect is GONE: it
		// discarded the verdict, making the only real assertion in this test
		// unfalsifiable while still paying its full 15 s timeout on failure.
		const root = page.locator('#app-content-vue [data-testid="period-close-detail"]')
		const loading = page.locator('#app-content-vue [data-testid="period-close-detail-loading"]')
		const errorMsg = page.locator('#app-content-vue [data-testid="period-close-detail-error"]')
		const body = page.locator('#app-content-vue [data-testid="period-close-detail-header"]')

		await expect(
			root.or(loading).or(errorMsg).or(body).first(),
			'the period-close detail route must mount PeriodCloseDetail (loading, error or loaded marker)',
		).toBeVisible({ timeout: 15_000 })
	})
})
