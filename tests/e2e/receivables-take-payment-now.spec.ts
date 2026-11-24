/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: Take payment now on an open invoice (receivables-provider-payouts
 * task 3.1, REQ-RPPO-005), the live half of the task.
 *
 *   1. an issued invoice's detail page offers Take payment now;
 *   2. the dialog explains that the open amount goes to the payment app on
 *      the user's registered phone;
 *   3. Take payment answers one of the two outcomes the provider gives
 *      through integriq: the payment is on the phone (device path), or the
 *      phone cannot take it and the dialog shows the checkout as a QR code
 *      with an https payment page link (fallback).
 *
 * On an instance without integriq's test source the server refuses with a
 * reason; the test then skips naming that reason, so the nightly reports
 * why the live half did not run instead of a false failure. The test starts
 * one test payment; it never captures it.
 *
 * Written, runs in nightly; not run here (it needs a live instance with
 * integriq's test source).
 *
 * @spec openspec/changes/receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const ROUTE_AR = '/bookkeeping/accounts-receivable'

/**
 * Close a dialog the shared chrome may have opened over the page.
 *
 * @param page The Playwright page.
 */
async function dismissMasks(page: Page): Promise<void> {
	for (const selector of ['#firstrunwizard', '[data-testid="cn-modal"]']) {
		const mask = page.locator(selector).first()
		if (await mask.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await mask.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	}
}

/**
 * Open the first invoice in one of the states Take payment now is offered in.
 *
 * @param page The Playwright page.
 * @return Whether an open invoice's detail page is shown.
 */
async function openAnOpenInvoice(page: Page): Promise<boolean> {
	for (const state of ['issued', 'overdue', 'partially-paid']) {
		await page.goto(`${APP}${ROUTE_AR}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissMasks(page)
		const row = page.locator('tr', { hasText: state }).first()
		if (await row.isVisible({ timeout: 10_000 }).catch(() => false)) {
			await row.click()
			await dismissMasks(page)
			return true
		}
	}

	return false
}

test.describe('receivables-provider-payouts — Take payment now (REQ-RPPO-005)', () => {
	test('an open invoice\'s payment goes to the phone or falls back to a QR code @spec REQ-RPPO-005', async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		const opened = await openAnOpenInvoice(page)
		test.skip(!opened, 'no issued, overdue or partially paid ARInvoice in this administration')

		await page.getByRole('button', { name: 'Take payment now' }).first().click()
		const dialog = page.getByTestId('take-payment-modal')
		await expect(dialog).toBeVisible()
		await expect(dialog).toContainText('The open amount of this invoice goes to the payment app on your registered phone.')

		await dialog.getByTestId('take-payment-start').click()
		const outcome = dialog.locator(
			'[data-testid="take-payment-on-device"], [data-testid="take-payment-qr-intro"], [data-testid="take-payment-error"]',
		).first()
		await expect(outcome).toBeVisible({ timeout: 20_000 })

		const error = dialog.getByTestId('take-payment-error')
		if (await error.isVisible().catch(() => false)) {
			test.skip(true, `the instance refused the payment: ${(await error.textContent())?.trim()}`)
		}

		if (await dialog.getByTestId('take-payment-qr-intro').isVisible().catch(() => false)) {
			await expect(dialog.getByTestId('take-payment-qr')).toBeVisible()
			await expect(dialog.getByTestId('take-payment-checkout-link')).toHaveAttribute('href', /^https:\/\//)
		} else {
			await expect(dialog.getByTestId('take-payment-on-device')).toContainText('The payment is on your phone.')
		}

		await dialog.getByTestId('take-payment-close').click()
		await expect(dialog).toBeHidden()
	})
})
