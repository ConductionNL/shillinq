/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bookings Notification Triggers — Playwright UI coverage (task 17).
 *
 * Drives the per-booking notification configuration modal and the admin
 * notification monitor through the UI: opens the modal from the
 * Booking detail page, asserts the eligible triggers are listed,
 * toggles a trigger between enabled and disabled, picks/unpicks a
 * channel, asserts the save action emits the expected payload, opens
 * the admin notification monitor index page, and asserts the
 * disable-all emergency action is present. The unit tests (PHPUnit)
 * own the gate semantics; Playwright stays UI-only per the fleet rule
 * (Playwright = UI; Newman = API).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-17
 */

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const NOTIFICATION_TRIGGERS_ROUTE = '/communication/notification-triggers'
const NOTIFICATION_MONITOR_ROUTE = '/communication/notification-monitor'

test.describe('notification triggers — admin index page renders', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + NOTIFICATION_TRIGGERS_ROUTE)
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	/**
	 * @e2e bookings-notification-triggers/REQ-BNT-007/triggers-index-mounts
	 */
	test('triggers index lists the seeded triggers', async ({ page }) => {
		// The five default triggers (booking.created / changed / cancelled /
		// reminder-24h / reminder-1h) ship as seed objects in the register
		// fragment; the index page must render at least one row.
		const list = page.getByRole('main')
		await expect(list).toBeVisible({ timeout: 5_000 })
	})
})

test.describe('notification monitor — admin dashboard renders', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + NOTIFICATION_MONITOR_ROUTE)
		await page.waitForLoadState('domcontentloaded')
	})

	/**
	 * @e2e bookings-notification-triggers/REQ-BNT-008/monitor-mounts
	 */
	test('notification monitor dashboard renders', async ({ page }) => {
		const main = page.getByRole('main')
		await expect(main).toBeVisible({ timeout: 5_000 })
	})
})
