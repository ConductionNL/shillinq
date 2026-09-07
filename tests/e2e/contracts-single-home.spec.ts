/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — contracts-single-home.
 *
 * Proves the two schemas the register un-merge separates render cleanly
 * inside the manifest shell after the fix:
 *
 *  - The generic Contract Lifecycle Management `Contract` schema
 *    (`/contracts` index + `/contracts/:id` detail) — this had ZERO e2e
 *    coverage before this change (only the IFRS-15 side had a spec file);
 *    the deep-link smoke in NavSixClusters.spec.js checks the INDEX route
 *    only, so this file adds the DETAIL route on top of that, closing the
 *    gap as a side effect of proving the un-merge did not break rendering
 *    (design.md §D7).
 *  - The renamed IFRS-15 `RevenueContract` schema (`/ifrs-15/contracts`
 *    index + `/ifrs-15/contracts/:id` detail, `page.config.schema` changed
 *    from `Contract` to `RevenueContract`, route/menu untouched) — extends
 *    tests/e2e/bookkeeping-ifrs15-revenue.spec.ts's existing index-only
 *    smoke with the detail route, which is the part that specifically needs
 *    proving post-rename.
 *
 * Both tests navigate directly to a detail URL with a literal id segment
 * rather than depending on a specific seeded object being present on every
 * environment — mirroring the established pattern in
 * NavSixClusters.spec.js's DELETED_DIALOG_ROUTES cases: vue-router matches
 * the `:id` segment literally and mounts the detail page regardless of
 * whether that id resolves to a real object, which is exactly the
 * route-mounts-in-the-shell guarantee this spec needs (REQ-driven data
 * rendering is out of scope for a route-mount smoke test).
 *
 * @e2e contracts-single-home::clm-contracts-index-and-detail-render
 * @e2e contracts-single-home::revenue-contracts-index-and-detail-render-post-rename
 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('shillinq — contracts-single-home register un-merge SPA smoke', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		expect(page.url()).toContain('/apps/shillinq')
	})

	test('CLM Contracts index and detail render after the register un-merge (REQ-CLM-001)', async ({
		page,
	}) => {
		// Index — the generic contract-lifecycle-management `Contract` schema,
		// unaffected in shape by the rename (it stays the canonical ADR-051
		// ns#Contract implementer). No additionalProperties/required error from
		// a leftover IFRS-15 field, since the two schemas no longer merge.
		await page.goto(APP + '/contracts')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
		const indexMain = page.locator('#content-vue, .app-content, main').first()
		await expect(indexMain).toBeVisible({ timeout: 10_000 })

		// Detail — /contracts/:id resolves inside the shell (ContractDetail,
		// src/manifest.d/contract-lifecycle-management.json). A demo contract
		// slug is used when present; the route itself mounts regardless.
		await page.goto(APP + '/contracts/contract-cleaning-2026')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
		const detailMain = page.locator('#content-vue, .app-content, main').first()
		await expect(detailMain).toBeVisible({ timeout: 10_000 })
	})

	test('Revenue Contracts index and detail render post-rename (REQ-IFRS15-001, RevenueContract)', async ({
		page,
	}) => {
		// Index — same route as before the rename (/ifrs-15/contracts); only
		// page.config.schema changed underneath it, from `Contract` to
		// `RevenueContract`.
		await page.goto(APP + '/ifrs-15/contracts')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
		const indexMain = page.locator('#content-vue, .app-content, main').first()
		await expect(indexMain).toBeVisible({ timeout: 10_000 })

		// Detail — /ifrs-15/contracts/:id (ContractDetail page, now sourced
		// from the RevenueContract schema). A demo revenue-contract seed slug
		// is used when present; the route itself mounts regardless.
		await page.goto(APP + '/ifrs-15/contracts/ifrs15-contract-c2026-001')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page).toHaveTitle(/shillinq/i, { timeout: 15_000 })
		const detailMain = page.locator('#content-vue, .app-content, main').first()
		await expect(detailMain).toBeVisible({ timeout: 10_000 })
	})
})
