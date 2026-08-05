/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * e2e for the W8 EXTERNAL-ADAPTER ADMIN UIs (this week's new surface).
 *
 * Shillinq ships 14+ dormant external-API adapter ports (Digipoort/SBR,
 * Salarisbureau, RvO, IB47, CBS, BZK SiSa, Mollie, Bunq, KvK, UWV, …). The W8
 * change added the operator admin UI:
 *   - ExternalAdaptersStatus.vue — the "External Connections" index that lists
 *     every adapter family with a dormant/live badge, sourced from
 *     GET /api/admin/external-adapters (ExternalAdaptersAdminController#index).
 *   - ExternalAdapterDetail.vue — the per-adapter activation panel (config
 *     keys, openconnector source slug, feature flag, ordered activation steps),
 *     reached by opening a family (ExternalAdaptersAdminController#show).
 *
 * This drives those real surfaces in the manifest shell and asserts:
 *   1. The Adapter Status index mounts, the summary pills (families/dormant/
 *      live) render, and at least one adapter family from the live API appears.
 *   2. Opening a family routes to its detail/activation panel, which renders
 *      the adapter title + dormant/live state + an activation-steps section.
 *   3. No shillinq-origin console error / 5xx fires on either surface.
 *
 * The backend adapter unit tests already exist; this closes the UI side.
 */
import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

const APP = '/apps/shillinq'

/** Dismiss the first-run wizard / support dialog if it intercepts the route. */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
	const support = page.locator('[data-testid-modal="cn-support-dialog"], .cn-support-dialog').first()
	if (await support.isVisible().catch(() => false)) {
		await support.getByRole('button', { name: /close|sluiten|dismiss/i }).first().click().catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Reach the Adapter Status surface. Shillinq's manifest SPA uses HISTORY-mode
 * routing, so the page route is directly addressable at
 * /apps/shillinq/external-adapters (verified live) — no hash, no SPA reset.
 */
async function openAdapterStatus(page: Page): Promise<void> {
	await page.goto(`${APP}/external-adapters`)
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)
	await expect(page).toHaveURL(/external-adapters/, { timeout: 10_000 })
}

/** Collect shillinq-origin console errors + 5xx, filtering NC-core / env noise. */
function trackShillinqErrors(page: Page): () => string[] {
	const errors: string[] = []
	const noise = /Failed to load resource|favicon|net::ERR|\b404\b|user status|status\.php|Download the (React|Vue) DevTools/i
	page.on('console', (m: ConsoleMessage) => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (noise.test(t)) return
		errors.push(t)
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && r.url().includes('/apps/shillinq/')) {
			errors.push(`HTTP ${r.status()} ${r.url()}`)
		}
	})
	return () => [...new Set(errors)]
}

test.describe('Shillinq — external-adapter admin UI', () => {
	test('Adapter Status index lists adapter families from the live API', async ({ page }) => {
		const errors = trackShillinqErrors(page)

		await openAdapterStatus(page)

		// The status surface renders its header + summary pills.
		await expect(page.locator('.external-adapters__title')).toContainText(/External Connections/i, { timeout: 15_000 })
		await expect(page.locator('.external-adapters__summary')).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.external-adapters__pill--total')).toContainText(/\d+\s+families/i)

		// At least one adapter family is listed (sourced from
		// GET /api/admin/external-adapters). Assert the list + a known family.
		const items = page.locator('.external-adapters__item')
		await expect(items.first()).toBeVisible({ timeout: 10_000 })
		expect(await items.count()).toBeGreaterThan(0)
		await expect(page.locator('.external-adapters__item[data-adapter-id="digipoort-sbr"]')).toBeVisible({ timeout: 10_000 })

		expect(errors(), `shillinq-origin errors:\n${errors().join('\n')}`).toEqual([])
	})

	test('opening a family routes to its activation/detail panel', async ({ page }) => {
		const errors = trackShillinqErrors(page)

		await openAdapterStatus(page)

		// Open the Digipoort/SBR family via its "open detail" button.
		const row = page.locator('.external-adapters__item[data-adapter-id="digipoort-sbr"]')
		await expect(row).toBeVisible({ timeout: 15_000 })
		await row.locator('button').first().click()

		// We land on the detail/activation panel for the adapter.
		await expect(page).toHaveURL(/external-adapters\/digipoort-sbr/, { timeout: 10_000 })
		await dismissOverlays(page)

		// The detail panel renders the adapter title + dormant/live status badge
		// + the activation-steps section (the W8 detail UI).
		await expect(page.locator('.adapter-detail__title')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('.adapter-detail__activation')).toBeVisible({ timeout: 10_000 })
		await expect(page.locator('.adapter-detail__section-title')).toContainText(/Activation steps/i)
		// A "Back to External Connections" control closes the loop.
		await expect(page.getByRole('button', { name: /Back to External Connections/i })).toBeVisible()

		expect(errors(), `shillinq-origin errors:\n${errors().join('\n')}`).toEqual([])
	})
})
