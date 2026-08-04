/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq SPA smoke
 *
 * All spec scenarios are @e2e excluded (backend API contracts, store unit-test
 * territory, or unbuilt UI features). This smoke test confirms the Shillinq
 * SPA mounts its v0.1.0 shell (Dashboard + Settings manifest). No @e2e
 * scenario tags are emitted.
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('shillinq — SPA smoke (v0.1.0 shell)', () => {
	test('app mounts and stays on shillinq route', async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		// Dismiss any first-run wizard overlays.
		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}

		// 1. URL must stay within shillinq (no redirect to NC login or another app).
		expect(page.url()).toContain('/apps/shillinq')

		// 2. THE SPA MUST HAVE BOOTED.
		//
		// ⚠️ The two assertions this replaces were constants:
		//    - `expect(page.url()).toContain('/apps/shillinq')` cannot fail —
		//      `appinfo/routes.php` delegates to
		//      `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
		//      (`'/{path}'`, `requirements: ['path' => '.+']`) answers EVERY
		//      path under `/apps/shillinq/` with the same `TemplateResponse`;
		//    - `expect(page).toHaveTitle(/shillinq/i)` cannot fail either — the
		//      `<title>` is server-rendered by Nextcloud's
		//      `core/templates/layout.user.php` from the app id BEFORE any
		//      JavaScript runs. It is NOT, as the old comment claimed, set
		//      "after Vue mounts + l10n".
		// On CI 30881746678 a control truncated `js/shillinq-main.js` to 0
		// bytes so the SPA could not boot, and both still held.
		//
		// `#app-content-vue` is NcAppContent's `<main>` — it exists only after
		// `app.mount('#shillinq-app')` in `src/main.js`. Nextcloud renders no
		// `<main>` and no `[role="main"]` for app pages, so the server-rendered
		// shell alone cannot satisfy it. `cn-dashboard-page` is
		// CnDashboardPage's own unconditional root, proving the v0.1.0 shell's
		// Dashboard page (manifest route `/`, title "Financial overview")
		// actually rendered rather than an empty content region.
		await expect(
			page.locator('#app-content-vue'),
			'the shillinq SPA must mount NcAppContent',
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.locator('#app-content-vue [data-testid="cn-dashboard-page"]'),
			'the manifest Dashboard page must render inside the content region',
		).toBeVisible({ timeout: 15_000 })

		// 3. The sidebar must contain a link pointing at the shillinq Settings
		// page. The lib renders it inside the collapsed `cn-app-nav__settings-list`
		// footer (revealed by a toggle), so it is attached but not visible until
		// the user opens that list — assert presence via the stable nav testid
		// rather than viewport visibility (the .first() href match used to grab
		// the off-screen settings cog and flake on toBeVisible).
		await expect(
			page.locator('[data-testid="cn-nav-entry-Settings"]'),
		).toBeAttached({ timeout: 10_000 })
	})
})
