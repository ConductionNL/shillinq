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

		// 3. The sidebar must contain a link pointing at the shillinq settings
		// pages. CnAppNav stamps `data-testid="cn-nav-entry-<menu item id>"` on
		// every entry (CnAppNav.vue), and the ids come straight from the
		// manifest menu tree — so the testid is only ever as real as the id.
		//
		// ⚠️ This assertion used to read `cn-nav-entry-Settings`, and NO menu
		// entry has ever carried the id `Settings`. The shillinq settings
		// foldout is populated from `src/menu-layout.json#settingsSection`,
		// whose first entry is `GeneralSettings`; the other shipped settings
		// ids are `DeadlineCalendarSettings`, `VpbSettings`, … There is no
		// `Settings`, so the locator matched nothing and the test failed for a
		// reason that had nothing to do with the SPA shell it claims to smoke.
		//
		// The lib renders settings entries inside the collapsed
		// `cn-app-nav__settings-list` footer (revealed by a toggle), so they
		// are ATTACHED but not visible until the user opens that list — assert
		// attachment, not viewport visibility.
		await expect(
			page.locator('[data-testid="cn-nav-entry-GeneralSettings"]'),
		).toBeAttached({ timeout: 10_000 })

		// The main navigation must also have rendered its own entries — an
		// empty `<nav>` with only the settings foldout is a half-mounted shell
		// and would otherwise satisfy the check above.
		await expect(
			page.locator('[data-testid^="cn-nav-entry-"]').first(),
		).toBeAttached({ timeout: 10_000 })
		expect(
			await page.locator('[data-testid^="cn-nav-entry-"]').count(),
		).toBeGreaterThan(1)
	})
})
