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

		// 2. The Shillinq page title must be set (renders after Vue mounts + l10n).
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })

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
