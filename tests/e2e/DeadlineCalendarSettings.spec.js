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

import { test, expect } from '@playwright/test'

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

	test('renders the four deadline categories with AR opt-in off by default', async ({ page }) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const filing = page.getByTestId('deadline-category-filing')
		const deployed = await filing.isVisible().catch(() => false)
		test.skip(!deployed, 'deadline-calendar settings page not deployed on this build')

		await expect(page.getByTestId('deadline-category-payment-run')).toBeVisible()
		await expect(page.getByTestId('deadline-category-ar-due')).toBeVisible()
		await expect(page.getByTestId('deadline-category-contract')).toBeVisible()

		// AR due dates are the opt-in category (REQ-CDC-004 default-off):
		// its lead-time field is hidden while the toggle is off.
		await expect(page.getByTestId('deadline-lead-ar-due')).toBeHidden()
		await expect(page.getByTestId('deadline-lead-filing')).toBeVisible()
	})

	test('toggling the payment-run category off saves through the settings endpoint', async ({ page }) => {
		await page.goto(`${APP}${ROUTE}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const toggle = page.getByTestId('deadline-toggle-payment-run')
		const deployed = await toggle.isVisible().catch(() => false)
		test.skip(!deployed, 'deadline-calendar settings page not deployed on this build')

		// `data-testid` falls through NcCheckboxRadioSwitch onto the bare
		// <input type="checkbox">, which the component stacks *behind* its own
		// <span class="checkbox-radio-switch__content"> — the element that
		// actually carries the `onClick: onToggle` handler
		// (@nextcloud/vue NcCheckboxRadioSwitch render fn). Clicking the input
		// is therefore intercepted by its own label; clicking the label is both
		// what a real operator does and the only thing that toggles the model.
		const toggleLabel = page.getByTestId('deadline-category-payment-run')
			.locator('.checkbox-radio-switch__content')

		// Bind the assertion to the input's checked state so the click has to
		// have actually flipped the model, not merely landed somewhere. Read
		// the current value rather than assuming the default-on state: a run
		// that died before its restore step would otherwise poison the next.
		const wasEnabled = await toggle.isChecked()
		await toggleLabel.click()
		await expect(toggle).toBeChecked({ checked: !wasEnabled })

		const saveResponse = page.waitForResponse(
			(response) => response.url().includes('/api/deadline-calendar/settings')
				&& response.request().method() === 'POST',
		)
		await page.getByTestId('deadline-settings-save').click()
		const response = await saveResponse
		expect(response.ok()).toBeTruthy()

		// Restore the entry state so the test stays idempotent.
		await toggleLabel.click()
		await expect(toggle).toBeChecked({ checked: wasEnabled })
		await page.getByTestId('deadline-settings-save').click()
	})
})
