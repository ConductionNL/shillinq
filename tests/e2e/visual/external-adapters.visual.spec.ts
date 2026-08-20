/*
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baseline for the integration-config-to-openconnector
 * "External Connections" roster (GAP-5 lineage) —
 * src/views/external-adapters/ExternalAdaptersStatus.vue.
 *
 * Replaces the former W8 pair (index + per-family `ExternalAdapterDetail.vue`
 * activation panel, e.g. the removed "adapter detail / activation panel
 * (mollie)" baseline): there is only one page left to baseline. The roster's
 * per-row activation recipe that used to be its own page is now an in-row
 * disclosure (`.external-adapters__recipe`) — a second shot captures it
 * expanded, since that state has meaningfully different layout (the facts
 * grid + ordered steps) from the collapsed row.
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * NOTE — NO BASELINE PNG SHIPS WITH THIS CHANGE: the previous baselines
 * (external-adapters-status-visual-linux.png,
 * external-adapter-detail-mollie-visual-linux.png) were deleted because
 * neither matches the redesigned roster DOM (new provisioning badge, deep
 * -link button, expand/collapse control) — keeping a stale PNG that is
 * guaranteed to mismatch is worse than shipping none. This spec was written
 * but NOT executed as part of this change (per the implementation brief);
 * `--update-snapshots` must be run once against a real dev instance before
 * this spec is gating in CI, per the shared visual-testing caveat below.
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat + the shared
 * freeze / dismiss / mask determinism guarantees.
 */
import { test, expect, type Page } from '@playwright/test'
import {
	dismissSupportDialog,
	waitForContentReady,
	freezePage,
	SHOT_OPTIONS,
	dynamicMasks,
} from './_visual-helpers'

// Use the SPA's history-mode base (no /index.php/ prefix) so the deep-link
// matches the vue-router base and does not get reset to the dashboard.
const ROSTER_ROUTE = '/apps/shillinq/external-adapters'

/**
 * Reach the roster, retrying the deep-link a few times because the SPA's
 * history-mode boot can reset a cold deep-link to the dashboard.
 */
async function reachRoster(page: Page): Promise<void> {
	for (let attempt = 0; attempt < 5; attempt++) {
		await page.goto(ROSTER_ROUTE, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		// Let the SPA finish booting + the manifest router settle the route.
		await page.waitForTimeout(2_000)
		if (
			await page
				.locator('.external-adapters__list')
				.first()
				.isVisible({ timeout: 4_000 })
				.catch(() => false)
		) {
			await waitForContentReady(page)
			return
		}
	}
	// Final assertion fails the test with a clear message if never reached.
	await expect(page.locator('.external-adapters__list').first()).toBeVisible({
		timeout: 10_000,
	})
}

test.describe('Shillinq — External Connections roster visual baselines', () => {
	test('roster, collapsed', async ({ page }) => {
		await reachRoster(page)
		await freezePage(page)
		await expect(page).toHaveScreenshot('external-adapters-roster.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})

	test('roster, one row expanded (activation recipe)', async ({ page }) => {
		await reachRoster(page)
		await page
			.locator('[data-adapter-id="mollie"]')
			.getByRole('button', { name: 'Show activation recipe' })
			.click()
		await expect(
			page.locator('[data-adapter-id="mollie"] .external-adapters__recipe'),
		).toBeVisible({ timeout: 10_000 })
		await freezePage(page)
		await expect(page).toHaveScreenshot(
			'external-adapters-roster-expanded.png',
			{
				...SHOT_OPTIONS,
				mask: dynamicMasks(page),
			},
		)
	})
})
