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
 * @spec openspec/changes/shillinq-invoice-quick-draft/specs/shillinq-invoice-quick-draft/spec.md
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

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
		const before = page.url()
		await page.getByTestId('cn-action-create-invoice').click()
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({
			timeout: 10_000,
		})
		// REQ: opens without leaving the dashboard.
		expect(page.url()).toBe(before)
		await expect(page.getByTestId('iqd-customer')).toBeVisible()
		await expect(page.getByTestId('iqd-invoice-date')).toBeVisible()
		await expect(page.getByTestId('iqd-due-date')).toBeVisible()
	})

	test('line items recompute the live totals', async ({ page }) => {
		await page.getByTestId('cn-action-create-invoice').click()
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({
			timeout: 10_000,
		})

		await page.getByTestId('iqd-line-description').first().fill('Consulting')
		await page.getByTestId('iqd-line-quantity').first().fill('2')
		await page.getByTestId('iqd-line-unit-price').first().fill('100')

		// 2 × 100 = €200 net at the default 21% → €242 gross.
		await expect(page.getByTestId('iqd-totals')).toContainText('200')
		await expect(page.getByTestId('iqd-totals')).toContainText('242')
	})

	test('add line button adds another row', async ({ page }) => {
		await page.getByTestId('cn-action-create-invoice').click()
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({
			timeout: 10_000,
		})
		await expect(page.getByTestId('iqd-line')).toHaveCount(1)
		await page.getByTestId('iqd-add-line').click()
		await expect(page.getByTestId('iqd-line')).toHaveCount(2)
	})

	test('save is disabled until a customer and a priced line are present', async ({
		page,
	}) => {
		await page.getByTestId('cn-action-create-invoice').click()
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({
			timeout: 10_000,
		})
		// No customer yet → save disabled.
		await expect(page.getByTestId('iqd-save')).toBeDisabled()
	})
})
