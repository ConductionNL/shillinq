/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for the Shillinq W8 External Adapters admin UI
 * (GAP-5) — the surfaces shipped with no visual coverage:
 *   - src/views/external-adapters/ExternalAdaptersStatus.vue  (index)
 *   - src/views/external-adapters/ExternalAdapterDetail.vue   (activation panel)
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat + the shared
 * freeze / dismiss / mask determinism guarantees.
 *
 * NAVIGATION NOTE: the manifest "External Connections" group renders in the
 * in-app sidebar but, in this nc-vue version, exposes no clickable child
 * entries (the parent label routes to `#`). The status INDEX is reached by
 * deep-linking the manifest route at the SPA's history-mode base
 * (`/apps/shillinq/...`, WITHOUT the `/index.php/` prefix — that prefix does
 * not match the vue-router base `generateUrl('/apps/shillinq')`, so the SPA
 * catch-all would reset it to the dashboard). A family DETAIL is then reached
 * via the index's in-app "View activation" button (an in-router push that
 * never reloads). The dynamic dormant/live badge text is masked via the shared
 * dynamicMasks() hooks so a dormancy flip never rebaselines.
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
const STATUS_ROUTE = '/apps/shillinq/external-adapters'

/**
 * Reach the adapter status index, retrying the deep-link a few times because
 * the SPA's history-mode boot can reset a cold deep-link to the dashboard.
 */
async function reachIndex(page: Page): Promise<void> {
	for (let attempt = 0; attempt < 5; attempt++) {
		await page.goto(STATUS_ROUTE, { waitUntil: 'domcontentloaded' })
		await dismissSupportDialog(page)
		// Let the SPA finish booting + the manifest router settle the route.
		await page.waitForTimeout(2_000)
		if (await page.locator('.external-adapters__list').first().isVisible({ timeout: 4_000 }).catch(() => false)) {
			await waitForContentReady(page)
			return
		}
	}
	// Final assertion fails the test with a clear message if never reached.
	await expect(page.locator('.external-adapters__list').first()).toBeVisible({ timeout: 10_000 })
}

test.describe('Shillinq W8 — External Adapters visual baselines', () => {
	test('adapter status index', async ({ page }) => {
		await reachIndex(page)
		await freezePage(page)
		await expect(page).toHaveScreenshot('external-adapters-status.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})

	test('adapter detail / activation panel (mollie)', async ({ page }) => {
		await reachIndex(page)
		// In-router push via the index's primary per-row action — reliable.
		await page.locator('[data-adapter-id="mollie"]')
			.getByRole('button', { name: 'View activation' })
			.click()
		await expect(page.locator('.adapter-detail[data-adapter-id]').first()).toBeVisible({ timeout: 15_000 })
		await dismissSupportDialog(page)
		await waitForContentReady(page)
		await freezePage(page)
		await expect(page).toHaveScreenshot('external-adapter-detail-mollie.png', {
			...SHOT_OPTIONS,
			mask: dynamicMasks(page),
		})
	})
})
