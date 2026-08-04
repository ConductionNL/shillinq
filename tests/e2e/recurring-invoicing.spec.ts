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
 * ROUTING. The SPA router is `createWebHistory(generateUrl('/apps/shillinq'))`
 * (src/main.js) — HTML5 history, NOT hash. `/apps/shillinq/#/…` therefore
 * resolves to path `/`, which is the Financial overview dashboard; every
 * assertion below then ran against the wrong page. The in-app nav links prove
 * the live form: `/apps/shillinq/bookkeeping/recurring-invoices`.
 *
 * @spec openspec/changes/recurring-invoicing/specs/recurring-invoicing/spec.md
 *
 * @e2e recurring-invoicing::operator-creates-a-monthly-hosting-profile
 * @e2e recurring-invoicing::preview-shows-the-exact-would-be-invoice
 * @e2e recurring-invoicing::dutch-ui-renders-translated-strings-from-english-keys
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

/*
 * KNOWN RED below the route fix — the "New recurring profile" launcher does
 * not reach the user, for two stacked reasons:
 *
 *  1. `src/manifest.d/recurring-invoicing.json` declares it under the index
 *     page's `config.actions[]`, which CnIndexPage consumes as ROW actions
 *     ("Row action definitions", CnIndexPage.vue:1091) — so on an empty
 *     register it renders nowhere. A page-scoped "New X" belongs in
 *     `config.headerActions[]`.
 *  2. Its `"type": "modal"` / `"modal": "…"` shape is not in the dispatcher's
 *     vocabulary (handler | open-modal | open-page | navigate | export |
 *     open-form | refresh | api-call | agent | toggle | object-op — see
 *     node_modules/@conduction/nextcloud-vue/src/utils/actionsDispatcher.js).
 *     The canonical shape is `"type": "open-modal"` + `"target"`, and it is
 *     the ONLY `"type": "modal"` in the whole manifest tree.
 *
 * Correcting the shape alone would not make these pass: nc-vue 3.0.0-vue3.4
 * has NO typed-action surface on `type:"index"` pages. CnActionButtons (the
 * `cn-action-<id>` open-modal dispatcher) is wired only into CnDashboardPage
 * and CnDetailPage, and CnIndexPage/manifestActionDispatch.js explicitly warns
 * `type:"open-modal" is not supported for index-page actions`. The modal
 * itself is fine (src/modals/RecurringInvoiceProfileModal.vue, registered
 * kind:"modal" at registry.js:339) — it is simply unreachable.
 */
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

	test('the index exposes the new-profile action and opens the modal', async ({ page }) => {
		const action = page.getByRole('button', { name: /new recurring profile/i })
			.or(page.getByText(/new recurring profile/i))
		await expect(action.first()).toBeVisible({ timeout: 15_000 })
		await action.first().click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({ timeout: 10_000 })
		await expect(page.getByTestId('rip-name')).toBeVisible()
		await expect(page.getByTestId('rip-customer')).toBeVisible()
	})

	test('the next-invoice preview expands period tokens', async ({ page }) => {
		const action = page.getByRole('button', { name: /new recurring profile/i })
			.or(page.getByText(/new recurring profile/i))
		await action.first().click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({ timeout: 10_000 })

		await page.getByTestId('rip-line-description').first().fill('Hosting {month} {year}')
		await page.getByTestId('rip-line-unit-price').first().fill('99')

		const year = String(new Date().getFullYear())
		// The preview must NOT still contain the literal tokens.
		await expect(page.getByTestId('rip-preview')).not.toContainText('{month}')
		await expect(page.getByTestId('rip-preview')).toContainText(year)
	})

	test('add line adds a row and validation blocks an empty save', async ({ page }) => {
		const action = page.getByRole('button', { name: /new recurring profile/i })
			.or(page.getByText(/new recurring profile/i))
		await action.first().click()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible({ timeout: 10_000 })

		await expect(page.getByTestId('rip-line')).toHaveCount(1)
		await page.getByTestId('rip-add-line').click()
		await expect(page.getByTestId('rip-line')).toHaveCount(2)

		// Save an empty profile → validation errors surface, modal stays open.
		await page.getByTestId('rip-save').click()
		await expect(page.getByTestId('rip-errors')).toBeVisible()
		await expect(page.getByTestId('recurring-profile-modal')).toBeVisible()
	})
})
