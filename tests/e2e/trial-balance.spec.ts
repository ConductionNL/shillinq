/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq Trial Balance SPA smoke.
 *
 * The Trial Balance pipeline registers three declarative manifest navigation
 * entries (TrialBalance, TrialBalanceDetail, TrialBalanceLines) rendered by the
 * nextcloud-vue manifest shell. This smoke confirms the SPA mounts and the
 * Trial Balance navigation entries are reachable without redirecting away from
 * shillinq.
 *
 * The full Trial Balance end-to-end flows (REQ-TB-009 GET /api/trial-balance
 * against a seeded GL, REQ-TB-002 prior-period opening carry, REQ-TB-011 KPI
 * card totals, REQ-TB-014 < 2 s render at 10 000 accounts) are @e2e excluded
 * here: they require a live OpenRegister instance seeded with Account +
 * GLTransaction + GLLine fixtures across two fiscal periods, which the
 * implementing cycle wires once the register fragment is imported into a
 * running instance.
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('shillinq — Trial Balance SPA smoke', () => {
	test('Trial Balance navigation entries resolve in the manifest shell', async ({
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

		// The TrialBalance (period snapshot) index page is registered by the
		// manifest; the SPA route must resolve without redirecting away.
		await page.goto(APP + '/financial-statements/trial-balance')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')

		// The TrialBalanceLines (per-account breakdown) index page is also
		// registered by the manifest; same SPA contract.
		await page.goto(APP + '/financial-statements/trial-balance-lines')
		await page.waitForLoadState('domcontentloaded')
		expect(page.url()).toContain('/apps/shillinq')

		// The page title remains the shillinq SPA title (Vue router did not
		// redirect us to /login or another app).
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
	})
})
