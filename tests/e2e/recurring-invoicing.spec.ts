/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — recurring-invoicing.
 *
 * Drives the Recurring Invoices index page and the
 * RecurringInvoiceProfileModal create flow: open the modal from the
 * index action, fill the profile form, see the next-invoice preview
 * expand the {month}/{year} tokens, add/remove lines, and have
 * validation block an empty save. The backend scenarios (scheduled
 * generation, idempotency, nextRunDate clamping, indexation) are
 * asserted at unit level (RecurringInvoiceGeneratorTest) and carry
 * reason-bearing @e2e exclude markers in the spec.
 *
 * Spec scenarios covered (UI):
 *   - Operator creates a monthly hosting profile
 *   - Next-invoice preview matches the definition (token expansion)
 *
 * Authored defensively for dev-container topologies: when the register
 * has no profiles the index renders its empty state but the action that
 * opens the modal must still be present.
 *
 * @spec openspec/changes/recurring-invoicing/specs/recurring-invoicing/spec.md
 *
 * @e2e recurring-invoicing::operator-creates-a-monthly-hosting-profile
 * @e2e recurring-invoicing::preview-shows-the-exact-would-be-invoice
 * @e2e recurring-invoicing::dutch-ui-renders-translated-strings-from-english-keys
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

test.describe('recurring-invoicing — profile create modal', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		await page.goto(APP + '/bookkeeping/recurring-invoices')
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}
	})

	/**
	 * ⚠️ WHY THESE THREE TESTS FAILED, AND WHAT CHANGED.
	 *
	 * They were RED because the affordance genuinely did not exist. The page
	 * declared `config.headerActions[{id: "new-recurring-profile"}]`, and
	 * CnActionsBar renders a manifest header action as an `NcActionButton`
	 * inside the COLLAPSED ⋯ overflow menu — so `toBeVisible()` could never
	 * pass — while its click only made CnIndexPage `$emit('header-action')`,
	 * which CnPageRenderer does not listen for. The fragment's own
	 * `_note_open_modal_gap` recorded both halves. The modal itself was
	 * complete and registered; nothing could open it.
	 *
	 * The inert entry is gone and the page now declares a `header-actions`
	 * slot WIDGET (`RecurringInvoiceProfileLauncher`, kind:"widget") that
	 * renders a visible primary button and mounts the modal. The assertions
	 * below are unchanged in substance — same modal, same fields — and are
	 * now anchored on the launcher's stable testid rather than on a loose
	 * text match that would also have matched the invisible overflow item.
	 */
	test('the index exposes the new-profile action and opens the modal', async ({
		page,
	}) => {
		const action = page.getByTestId('recurring-profile-new')
		await expect(action).toBeVisible({ timeout: 15_000 })
		await action.click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({
			timeout: 10_000,
		})
		await expect(page.getByTestId('rip-name')).toBeVisible()
		await expect(page.getByTestId('rip-customer')).toBeVisible()
	})

	test('the next-invoice preview expands period tokens', async ({ page }) => {
		const action = page.getByTestId('recurring-profile-new')
		await expect(action).toBeVisible({ timeout: 15_000 })
		await action.click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({
			timeout: 10_000,
		})

		await page
			.getByTestId('rip-line-description')
			.first()
			.fill('Hosting {month} {year}')
		await page.getByTestId('rip-line-unit-price').first().fill('99')

		const year = String(new Date().getFullYear())
		// The preview must NOT still contain the literal tokens.
		await expect(page.getByTestId('rip-preview')).not.toContainText('{month}')
		await expect(page.getByTestId('rip-preview')).toContainText(year)
	})

	test('add line adds a row and validation blocks an empty save', async ({
		page,
	}) => {
		const action = page.getByTestId('recurring-profile-new')
		await expect(action).toBeVisible({ timeout: 15_000 })
		await action.click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({
			timeout: 10_000,
		})

		await expect(page.getByTestId('rip-line')).toHaveCount(1)
		await page.getByTestId('rip-add-line').click()
		await expect(page.getByTestId('rip-line')).toHaveCount(2)

		// Save an empty profile → validation errors surface, modal stays open.
		await page.getByTestId('rip-save').click()
		await expect(page.getByTestId('rip-errors')).toBeVisible()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible()
	})
})
