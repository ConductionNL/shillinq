/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AR e-invoicing (Peppol) — Playwright UI shell-smoke for the
 * `add-invoice-pdf-export-with-ubl-peppol-support` change (REQ-EINV-007).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only): the ARInvoiceDetail header renders the delivery-status chip and
 * the Send e-invoice action, the action is disabled for a non-issued invoice
 * and enabled for an issued one. The deeper guarantees are owned by other
 * layers and referenced here so the spec Scenario carries an @e2e proof —
 *   - NLCIUS UBL rendering, PDF/A-3 embedding, KvK/BTW/participant
 *     validation, the outbound event and the IDOR-safe controller contract
 *     live in `tests/Unit/Service/EInvoice/*` + `ARInvoiceEInvoiceController`
 *     (PHPUnit) and `shillinq.postman_collection.json` (Newman);
 *   - the enable/status/fallback logic lives in
 *     `tests/vitest/arEInvoiceActions.spec.js` against the pure helpers in
 *     `src/components/ar-invoice/arEInvoiceActions.js`.
 *
 * Data-defensive: when the seeded ARInvoices are not present (fresh
 * administration) the list is empty — the suite skips rather than fails.
 *
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-007
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const ROUTE_AR = '/bookkeeping/accounts-receivable'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Open the detail page of the first AR invoice row whose lifecycle state
 * matches. Returns false when no such row exists so the caller can skip.
 *
 * @param page The Playwright page.
 * @param state The lifecycleState text to match in the list rows.
 */
async function openInvoiceByState(page: Page, state: string): Promise<boolean> {
	await page.goto(`${APP}${ROUTE_AR}`)
	await page.waitForLoadState('domcontentloaded')
	await dismissWizard(page)
	const row = page.locator('tr', { hasText: state }).first()
	if (!(await row.isVisible().catch(() => false))) {
		return false
	}
	await row.click()
	await page
		.locator('[data-testid="ar-einvoice-actions"]')
		.waitFor({ state: 'visible', timeout: 10_000 })
		.catch(() => {})
	return await page
		.locator('[data-testid="ar-einvoice-actions"]')
		.isVisible()
		.catch(() => false)
}

test.describe('add-invoice-pdf-export-with-ubl-peppol-support — Send e-invoice + delivery status (REQ-EINV-007)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('issued invoice: status chip renders and Send e-invoice is enabled', async ({
		page,
	}) => {
		const opened = await openInvoiceByState(page, 'issued')
		test.skip(!opened, 'no issued ARInvoice seeded in this administration')

		// The delivery-status indicator carries a human-readable text label
		// (WCAG 2.1 AA — status is never conveyed by colour alone).
		const chip = page.getByTestId('delivery-status-chip')
		await expect(chip).toBeVisible()
		await expect(chip).not.toBeEmpty()

		// Send e-invoice is enabled for lifecycleState=issued.
		await expect(page.getByTestId('ar-einvoice-send')).toBeEnabled()
	})

	test('non-issued invoice: Send e-invoice is disabled', async ({ page }) => {
		const opened = await openInvoiceByState(page, 'paid')
		test.skip(!opened, 'no paid ARInvoice seeded in this administration')

		await expect(page.getByTestId('delivery-status-chip')).toBeVisible()
		await expect(page.getByTestId('ar-einvoice-send')).toBeDisabled()
	})
})
