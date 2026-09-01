/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deadline-calendar settings — Playwright UI shell-smoke for the
 * `compliance-deadline-calendar` change (REQ-CDC-006).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only): the DeadlineCalendarSettings page renders the four category
 * toggles (filing / payment runs / invoice due dates / contract), a
 * toggle can be switched and saved, and the save round-trips through
 * the strictly current-user-scoped /api/deadline-calendar/settings
 * endpoint. The deeper guarantees are owned by other layers and
 * referenced here so the spec Scenario carries an @e2e proof —
 *   - VEVENT publication / removal on toggle-off, fail-soft calendar
 *     resolution and the exactly-one reminder rule live in
 *     `tests/Unit/Service/ComplianceDeadlineCalendarServiceTest.php`
 *     (PHPUnit);
 *   - the ObligationTaskBridge VEVENT extension lives in
 *     `tests/Unit/Service/ObligationTaskBridgeTest.php` (PHPUnit);
 *   - the settings normalisation / payload logic lives in
 *     `tests/vitest/deadlineCalendarSettings.spec.js` against the pure
 *     helpers in `src/views/deadlineCalendarSettingsHelpers.js`.
 *
 * Data-defensive: the settings page needs no seeded records — it always
 * renders the four categories; assertions skip only when the deployed
 * build predates this change (page not found).
 *
 * @e2e compliance-deadline-calendar::toggling-a-category-off-removes-its-events
 * @spec openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-006
 */

import { expect, test } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'
const ROUTE = '/settings/deadline-calendar'

async function dismissWizard(page) {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('compliance-deadline-calendar — per-user category toggles (REQ-CDC-006)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('renders the four deadline categories with AR opt-in off by default', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const filing = page.getByTestId('deadline-category-filing')
		const deployed = await becomesVisible(filing)
		test.skip(
			!deployed,
			'deadline-calendar settings page not deployed on this build',
		)

		await expect(page.getByTestId('deadline-category-payment-run')).toBeVisible()
		await expect(page.getByTestId('deadline-category-ar-due')).toBeVisible()
		await expect(page.getByTestId('deadline-category-contract')).toBeVisible()

		// AR due dates are the opt-in category (REQ-CDC-004 default-off):
		// its lead-time field is hidden while the toggle is off.
		await expect(page.getByTestId('deadline-lead-ar-due')).toBeHidden()
		await expect(page.getByTestId('deadline-lead-filing')).toBeVisible()
	})

	test('toggling the payment-run category off saves through the settings endpoint', async ({
		page,
	}) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const toggle = page.getByTestId('deadline-toggle-payment-run')
		const deployed = await becomesVisible(toggle)
		test.skip(
			!deployed,
			'deadline-calendar settings page not deployed on this build',
		)

		// Click the SWITCH SURFACE, not the input. `data-testid` falls through
		// NcCheckboxRadioSwitch onto its <input>, which @nextcloud/vue 9 renders
		// underneath a styled `.checkbox-radio-switch__content` span. The input
		// is technically visible, so Playwright does not re-target — it just
		// retried the click for the full 60s while the span reported
		// "intercepts pointer events". The span is what a user actually clicks.
		const switchSurface = page
			.getByTestId('deadline-category-payment-run')
			.locator('.checkbox-radio-switch__content')
			.first()

		const before = await toggle.isChecked()
		await switchSurface.click()
		// Assert the toggle actually moved before saving — otherwise a POST of
		// an unchanged payload would still return 200 and this test would pass
		// without having toggled anything.
		await expect(toggle).toBeChecked({ checked: !before })

		const saveResponse = page.waitForResponse(
			(response) =>
				response.url().includes('/api/deadline-calendar/settings')
				&& response.request().method() === 'POST',
		)
		await page.getByTestId('deadline-settings-save').click()
		const response = await saveResponse
		expect(response.ok()).toBeTruthy()

		// Restore the default so the test stays idempotent.
		await switchSurface.click()
		await expect(toggle).toBeChecked({ checked: before })
		await page.getByTestId('deadline-settings-save').click()
	})
})
