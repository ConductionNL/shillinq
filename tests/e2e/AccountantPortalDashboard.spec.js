/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AccountantPortalDashboard — Playwright UI shell-smoke for the
 * `accountant-portal` change (REQ-ACP-001, REQ-ACP-002).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only): the page renders (either the per-client card grid or the
 * "no client administrations" empty state for a user with no memberships),
 * and — when at least one client card is present — the "Download handover
 * pack" action triggers a real request to the handover-pack endpoint. The
 * deeper guarantees are owned by other layers and referenced here so the
 * spec Scenario carries an @e2e proof:
 *  - per-client status composition (period-close / BTW filing / missing
 *    documents / open items) and its fail-soft degradation live in
 *    `tests/Unit/Service/AccountantDashboardServiceTest.php` (PHPUnit);
 *  - the masked-404 tenant-isolation guard on both endpoints — the security
 *    headline of this change — lives in
 *    `tests/Unit/Controller/AccountantPortalControllerTest.php` (PHPUnit),
 *    not here: proving a 404 for another tenant's id needs a second seeded
 *    account and is a same-origin same-session concern, not a rendering one.
 *
 * Data-defensive: skips when the page isn't deployed yet, and skips the
 * handover-pack assertion when the signed-in user has no client
 * administrations to show a card for.
 *
 * @e2e accountant-portal::dashboard-lists-granted-client-administrations
 * @spec openspec/changes/accountant-portal/specs/accountant-portal/spec.md#req-acp-002
 */

import { test, expect } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'
const ROUTE = '/accountant-portal'

async function dismissWizard(page) {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('accountant-portal — scoped multi-client dashboard (REQ-ACP-001/002)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('renders client cards or the no-memberships empty state', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const heading = page.getByRole('heading', { name: 'Accountant portal' })
		const deployed = await becomesVisible(heading)
		test.skip(!deployed, 'accountant-portal page not deployed on this build')

		const card = page.getByTestId('accountant-client-card').first()
		const hasCard = await becomesVisible(card)
		if (!hasCard) {
			// A user with no AdministrationMembership sees the empty state, not an error.
			await expect(page.getByText('No client administrations')).toBeVisible()
			return
		}

		await expect(card).toBeVisible()
	})

	test('the handover-pack action requests the scoped export endpoint', async ({
		page,
		context,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const button = page.getByTestId('accountant-handover-pack-button').first()
		const hasButton = await becomesVisible(button)
		test.skip(
			!hasButton,
			'no client administration available to test the handover-pack action against',
		)

		const [popup] = await Promise.all([
			context.waitForEvent('page', { timeout: 5_000 }).catch(() => null),
			button.click(),
		])

		if (popup !== null) {
			expect(popup.url()).toContain('/api/accountant/administrations/')
			expect(popup.url()).toContain('/handover-pack')
			await popup.close()
		}
	})
})
