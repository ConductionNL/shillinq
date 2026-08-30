/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq Innovatiebox Administratie SPA smoke.
 *
 * The bookkeeping-innovatiebox-administratie change registers a
 * Bookkeeping > Innovatiebox submenu behind featureFlags.mkb-innovatiebox with
 * five index/detail pages (REQ-IBA-001..009). This smoke confirms the SPA
 * mounts and the Innovatiebox navigation entries resolve in the manifest shell
 * without redirecting away from shillinq.
 *
 * The deeper end-to-end flows (REQ-IBA-006 per-asset Vpb roll-up,
 * REQ-IBA-009 scenario calculator, REQ-IBA-004 doorsnijdingsverbod close-block,
 * REQ-IBA-008 VSO lock + audit-trail event append) are @e2e excluded here:
 * they require a live OpenRegister instance seeded with the five register
 * fragments + a QualifyingAsset + NexusCalculation + IBProfitAttribution +
 * CarryForwardLoss + IBExpenseAllocation chain plus a paired GL feed for the
 * doorsnijdingsverbod check, which the implementing cycle wires once the
 * register fragment is imported into a running instance.
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('shillinq — Innovatiebox Administratie SPA smoke', () => {
	test('Innovatiebox navigation entries resolve in the manifest shell', async ({
		page,
	}) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		// Dismiss any first-run wizard overlays.
		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}

		// Stay within shillinq.
		expect(page.url()).toContain('/apps/shillinq')

		// Innovatiebox elections index — the top of the Bookkeeping > Innovatiebox
		// submenu. The page should mount whether the feature flag is on or off:
		// when off, the manifest renders an empty list; the SPA must not bounce.
		await page.goto(APP + '/bookkeeping/innovatiebox')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')

		// IP-activa (afpelmethode) detail route — registered by the manifest
		// behind the same mkb-innovatiebox flag; with no fixture loaded the
		// page renders an empty/not-found state but the SPA route resolves.
		await page.goto(APP + '/bookkeeping/innovatiebox/ip-activa/none')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')

		// The page title remains the shillinq SPA title (Vue router did not
		// redirect us to /login or another app).
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
	})
})
