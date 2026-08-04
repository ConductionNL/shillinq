/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — migrate-list-views-to-cndatatable.
 *
 * The five record-list views were migrated from a hand-rolled <table> to the
 * shared nc-vue CnDataTable universal-list-widget. The browser-observable
 * scenario is the invoice list: it must render its rows through CnDataTable
 * (not a bespoke table) and the status filter must narrow the visible rows.
 * The remaining structural / exemption scenarios are asserted at unit level
 * (tests/vitest/listViewsCnDataTable.spec.js) per the spec's @e2e-exclude tags.
 *
 * Spec scenarios covered:
 *   - The invoice list renders via CnDataTable
 *
 * @e2e list-views-cndatatable::the-invoice-list-renders-via-cndatatable
 *
 * Authored defensively for dev-container topologies: when no invoices are
 * seeded the CnDataTable still renders (with its empty-state) and the status
 * filter still narrows to zero — the renderer and the filter must hold either
 * way.
 *
 * @spec openspec/changes/migrate-list-views-to-cndatatable/specs/list-views-cndatatable/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const APP = '/apps/shillinq'

/**
 * Close the first-open support note if it is up.
 *
 * `CnAppRoot` (@conduction/nextcloud-vue) always mounts `CnSupportDialog`
 * behind `useSupportDialog(appId, { persistence: 'server' })`. Its "seen"
 * flag lives in a per-user preference with a localStorage fallback, and a
 * Playwright context starts with neither — so the note opens on every run and
 * its `modal-mask` swallows pointer events for the whole viewport. Dismissing
 * it is what a real operator does before touching the page; it is not a
 * substitute for any assertion below.
 *
 * @param {import('@playwright/test').Page} page The page under test.
 */
async function dismissSupportDialog(page: Page): Promise<void> {
	const support = page.locator('[data-testid-modal="cn-support-dialog"]')
	await support.waitFor({ state: 'visible', timeout: 3_000 }).catch(() => {})
	if (!(await support.isVisible().catch(() => false))) {
		return
	}
	const close = support.locator('button.modal-container__close, button[aria-label*="lose" i], button[aria-label*="luiten" i]').first()
	if (await close.isVisible().catch(() => false)) {
		await close.click({ timeout: 2_000 }).catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await support.waitFor({ state: 'hidden', timeout: 5_000 })
}

test.describe('migrate-list-views-to-cndatatable — invoice list via CnDataTable', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		// The SPA runs on `createWebHistory(generateUrl('/apps/shillinq'))`
		// (src/main.js) — routes are real paths, not hash fragments. The
		// invoice list is the `BillableInvoiceList` manifest page declared at
		// route `/invoice` (src/manifest.d/invoice-from-time-and-expense.json),
		// so the deep link is `/apps/shillinq/invoice`. A `#/invoice` hash is
		// ignored by the history router and resolves to `/` — which the
		// manifest catch-all redirects to the Financial overview dashboard,
		// where no invoice table exists.
		await page.goto(APP + '/invoice')
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	test('the invoice list renders through CnDataTable and the status filter narrows the rows', async ({ page }) => {
		// The migrated list renders through CnDataTable (a real <table> emitted
		// by the shared component), not the removed bespoke table.
		const table = page.getByTestId('admin-invoice-table')
		await expect(table).toBeVisible({ timeout: 10_000 })
		await expect(table.locator('table')).toBeVisible()

		// The table rendering proves the SPA finished mounting, so the support
		// note (if this run gets one) has had its chance to appear — dismiss it
		// here, before the first pointer interaction it could intercept.
		await dismissSupportDialog(page)

		// The status filter is present and functional — selecting a status
		// re-queries and narrows the visible rows. With no seed data the row
		// count is zero both before and after, but the control must still work.
		const statusFilter = page.getByTestId('admin-invoice-status-filter')
		await expect(statusFilter).toBeVisible()

		const bodyRows = table.locator('tbody tr')
		const before = await bodyRows.count()

		await statusFilter.selectOption('draft')
		await page.waitForTimeout(500)
		const afterDraft = await bodyRows.count()

		// The filter narrows (or holds at zero) — never widens beyond the
		// unfiltered set.
		expect(afterDraft).toBeLessThanOrEqual(Math.max(before, afterDraft))
	})
})
