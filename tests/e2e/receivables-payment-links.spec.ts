/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: Send payment link follows whether online payment is set up
 * (receivables-payment-links task 3.3, REQ-RPL-002).
 *
 *   1. `GET /api/online-payment/status` answers `{live, reason, message}`;
 *      while the payment adapter is dormant it says `not-set-up` with the
 *      line that names where to configure it;
 *   2. the Payment requests page opens from its route and lists requests;
 *   3. on a pending request's page, Send payment link is enabled when online
 *      payment is live and disabled when it is not. An instance without a
 *      pending request skips this step and says so.
 *
 * The caller is the suite's administrator (globalSetup's storage state). The
 * spec writes nothing: it never clicks Send payment link. Written
 * 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live
 * instance).
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md
 *
 * @e2e ar-invoice-payment-links::an-instance-without-a-payment-source
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const NOT_SET_UP = 'Online payment is not set up: configure a payment source in integriq.'

/** The online payment status (OnlinePaymentStatusController::status). */
interface OnlinePaymentStatus {
	live: boolean
	reason: string
	message: string
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('receivables-payment-links: Send payment link and the payment source', () => {
	test('the status says whether online payment is set up @spec REQ-RPL-002', async ({ request }) => {
		const res = await request.get(`${API}/online-payment/status`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const status = (await res.json()) as OnlinePaymentStatus
		expect(typeof status.live).toBe('boolean')
		if (status.live) {
			expect(status.reason).toBe('')
		} else {
			expect(status.reason).toBe('not-set-up')
			expect(status.message).not.toBe('')
		}
	})

	test('the Payment requests page opens @spec REQ-RPL-002', async ({ page }) => {
		await page.goto(`/index.php${APP}/bookkeeping/payment-requests`)
		await dismissOverlays(page)
		await expect(page.getByText('Payment requests').first()).toBeVisible()
	})

	test('Send payment link on a pending request follows the status @spec REQ-RPL-002', async ({ page, request }) => {
		const statusRes = await request.get(`${API}/online-payment/status`, { headers: HEADERS })
		expect(statusRes.status()).toBe(200)
		const status = (await statusRes.json()) as OnlinePaymentStatus

		const list = await request.get(`${OBJECTS}/PaymentRequest?state=pending&_limit=1`, { headers: HEADERS })
		expect(list.ok(), await list.text()).toBe(true)
		const pending = (((await list.json()) as { results?: Array<{ id?: string, '@self'?: { id?: string } }> }).results ?? [])[0]
		test.skip(pending === undefined, 'the instance holds no pending payment request')
		const id = String(pending?.id ?? pending?.['@self']?.id ?? '')

		await page.goto(`/index.php${APP}/bookkeeping/payment-requests/${encodeURIComponent(id)}`)
		await dismissOverlays(page)
		const send = page.getByRole('button', { name: 'Send payment link' }).first()
		await expect(send).toBeVisible()
		if (status.live) {
			await expect(send).toBeEnabled()
		} else {
			await expect(send).toBeDisabled()
			await expect(page.getByText(NOT_SET_UP).first()
				.or(page.locator(`[title="${NOT_SET_UP}"]`).first())).toBeAttached()
		}
	})
})
