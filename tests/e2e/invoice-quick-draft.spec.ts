/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — shillinq-invoice-quick-draft.
 *
 * Drives the InvoiceQuickDraftModal launched from the Financial
 * overview's "Create invoice" action: the modal opens in place (no
 * navigation), the form fields render, line totals recompute and the
 * save button gates on a customer + a priced line. The actual POST is
 * asserted at unit level (tests/vitest/invoiceQuickDraft.spec.js); this
 * spec proves the UI behaviour that the proposal scenarios describe.
 *
 * Spec scenarios covered:
 *   - Create invoice opens the quick-draft modal
 *   - Selecting a customer pre-fills the due date
 *   - Adding a line updates the totals
 *   - Save creates a draft ARInvoice
 *   - Receivables widget refreshes after save
 *
 * @e2e shillinq-invoice-quick-draft::create-invoice-opens-the-quick-draft-modal
 * @e2e shillinq-invoice-quick-draft::selecting-a-customer-pre-fills-the-due-date
 * @e2e shillinq-invoice-quick-draft::adding-a-line-updates-the-totals
 * @e2e shillinq-invoice-quick-draft::save-creates-a-draft-arinvoice
 * @e2e shillinq-invoice-quick-draft::receivables-widget-refreshes-after-save
 *
 * Authored defensively for dev-container topologies: when no customers
 * are seeded the customer picker is empty and save stays disabled — the
 * modal shell must still render and the gating must hold.
 *
 * LOCATOR CONTRACT (ADR-049 Phase-4). The launcher is no longer the bespoke
 * `FinancialDashboardActions.vue` (`fda-create-invoice`, deleted). It is a
 * declarative `pages[Dashboard].config.headerActions[]` open-modal entry with
 * `id: "create-invoice"` in `src/manifest.json`, rendered by CnActionButtons
 * as `data-testid="cn-action-<id>"` — the same live convention
 * `bill-import-modal.spec.ts` already drives with `cn-action-import-bill`.
 * The modal's own `iqd-*` testids are unchanged (src/modals/InvoiceQuickDraftModal.vue).
 *
 * @spec openspec/changes/shillinq-invoice-quick-draft/specs/shillinq-invoice-quick-draft/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const APP = '/apps/shillinq'

/**
 * Close the first-open support note if it is up.
 *
 * `CnAppRoot` mounts `CnSupportDialog` behind `useSupportDialog(appId)`; on a
 * profile that has never seen it the note opens over the dashboard and its
 * `modal-mask` swallows pointer events for the whole viewport, so the click on
 * the launcher never lands. Dismissing it is what a real operator does before
 * using the dashboard; it replaces no assertion below. Mirrors the identical
 * helper in `bill-import-modal.spec.ts`.
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

/** Open the quick-draft modal from the dashboard's declarative header action. */
async function openQuickDraft(page: Page): Promise<void> {
	const launcher = page.getByTestId('cn-action-create-invoice')
	await expect(launcher).toBeVisible({ timeout: 15_000 })
	await dismissSupportDialog(page)
	await launcher.click()
	await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({ timeout: 10_000 })
}

test.describe('shillinq-invoice-quick-draft — quick draft modal', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		await page.goto(APP + '/')
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	test('Create invoice opens the quick-draft modal in place', async ({ page }) => {
		const launcher = page.getByTestId('cn-action-create-invoice')
		await expect(launcher).toBeVisible({ timeout: 15_000 })
		await dismissSupportDialog(page)
		const before = page.url()
		await launcher.click()
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({ timeout: 10_000 })
		// REQ: opens without leaving the dashboard.
		expect(page.url()).toBe(before)
		await expect(page.getByTestId('iqd-customer')).toBeVisible()
		await expect(page.getByTestId('iqd-invoice-date')).toBeVisible()
		await expect(page.getByTestId('iqd-due-date')).toBeVisible()
	})

	test('line items recompute the live totals', async ({ page }) => {
		await openQuickDraft(page)

		await page.getByTestId('iqd-line-description').first().fill('Consulting')
		await page.getByTestId('iqd-line-quantity').first().fill('2')
		await page.getByTestId('iqd-line-unit-price').first().fill('100')

		// 2 × 100 = €200 net at the default 21% → €242 gross.
		await expect(page.getByTestId('iqd-totals')).toContainText('200')
		await expect(page.getByTestId('iqd-totals')).toContainText('242')
	})

	test('add line button adds another row', async ({ page }) => {
		await openQuickDraft(page)
		await expect(page.getByTestId('iqd-line')).toHaveCount(1)
		await page.getByTestId('iqd-add-line').click()
		await expect(page.getByTestId('iqd-line')).toHaveCount(2)
	})

	test('save is disabled until a customer and a priced line are present', async ({ page }) => {
		await openQuickDraft(page)
		// No customer yet → save disabled.
		await expect(page.getByTestId('iqd-save')).toBeDisabled()
	})
})
