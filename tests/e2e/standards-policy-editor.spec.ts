/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Accounting-standards precedence policy — Playwright UI proof for
 * `src/views/settings/StandardsPolicyEditor.vue`.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * gate-26 (visual-coverage) reported this component as a page with no
 * visual-regression proof of any kind. The two other ways to clear that
 * finding were both worse than a browser test:
 *
 *   - a pixel baseline under `tests/e2e/visual/**` would satisfy the gate
 *     while measuring nothing in CI, because the `visual` project is not one
 *     of the projects this config runs (its own header records that a Linux
 *     runner cannot byte-match a dev-container baseline);
 *   - a reason-bearing `@visual exclude` would be an apology to a gate for a
 *     page that is real, routed and reachable.
 *
 * So this drives the page for real.
 *
 * NO `test.skip` DEPLOY FALLBACK, DELIBERATELY
 * --------------------------------------------
 * The sibling settings spec (`DeadlineCalendarSettings.spec.js`) guards its
 * assertions with `test.skip(!deployed, 'page not deployed on this build')`.
 * That shape is right for a spec that must survive an older deployed build,
 * and wrong here: this file's whole purpose is to be the proof that the page
 * renders, and a skip whose stated reason is untrue is an invisible pass —
 * it would clear gate-26 while asserting nothing at all. If this page stops
 * rendering, this test must go red.
 *
 * The selectors are the component's own stable `data-testid` hooks
 * (`standards-policy-list`, `standards-policy-row-*`,
 * `standards-policy-resolved`), not CSS or text, so a copy change or a
 * restyle cannot break it.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md#REQ-ASP-002
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'
const ROUTE = '/settings/accounting-standards'

/**
 * The first-run wizard renders over the app shell and swallows clicks, so it
 * is dismissed before anything is asserted. Its absence is not an error.
 *
 * @param page The Playwright page.
 */
async function dismissWizard(page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('accounting-standards precedence policy editor', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('the settings route mounts the editor and renders its precedence list', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		// The route must resolve to THIS page, not to the router's catch-all.
		// Asserting the URL first separates "the page failed to render" from
		// "the route does not exist" — the two produce the same empty screen.
		await expect(page).toHaveURL(new RegExp(`${ROUTE}$`))

		// The component's own root test id. A URL alone proves nothing here:
		// an unresolved in-app route lands on the SPA shell, which renders a
		// perfectly healthy page that simply is not this one.
		await expect(page.getByTestId('StandardsPolicyEditor')).toBeVisible({
			timeout: 30_000,
		})

		const list = page.getByTestId('standards-policy-list')
		await expect(list).toBeVisible({ timeout: 30_000 })

		// A rendered but empty list would satisfy toBeVisible, so assert the
		// editor actually has rows to rank.
		await expect(list.locator('li')).not.toHaveCount(0)

		// The resolved-precedence readout is the page's output, not its input:
		// it is what proves the ranking was computed rather than merely drawn.
		await expect(page.getByTestId('standards-policy-resolved')).toBeVisible()
	})
})
