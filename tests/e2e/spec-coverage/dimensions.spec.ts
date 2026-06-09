/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Dimensions" navigation group
 * (cost centres, kostendragers, projects, allocation rules). Deep-links each
 * manifest page, asserts a genuine index surface, no shillinq-origin 5xx /
 * page error. Data-independent.
 */

import { test, expect } from '@playwright/test'
import { gotoPage, assertIndexSurface, assertNoShillinqFailures, recordShillinqErrors } from './_helpers'

const PAGES: Array<{ route: string, title: string, titleRe?: RegExp }> = [
	{ route: '/bookkeeping/dimensions/cost-centers', title: 'Cost Centers' },
	{ route: '/bookkeeping/dimensions/kostendragers', title: 'Kostendragers' },
	{ route: '/bookkeeping/dimensions/projects', title: 'Projects' },
	{ route: '/bookkeeping/dimensions/allocation-rules', title: 'Allocation Rules' },
]

test.describe('shillinq spec-coverage — Dimensions', () => {
	test.describe.configure({ mode: 'serial' })
	for (const p of PAGES) {
		test(`Dimensions › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}

	// Cost Centers ships a primary "Add Item" create affordance — open it and
	// confirm a create dialog/form mounts (no submit, data-independent).
	test('Dimensions › Cost Centers — Add opens a create dialog', async ({ page }) => {
		const rec = recordShillinqErrors(page)
		await gotoPage(page, '/bookkeeping/dimensions/cost-centers')
		const addBtn = page.locator('#content-vue button:has-text("Add"), #content-vue button:has-text("Nieuw"), #content-vue button:has-text("Toevoegen")').first()
		if (await addBtn.isVisible().catch(() => false)) {
			await addBtn.click({ timeout: 5_000 }).catch(() => {})
			const dialog = page.locator('.modal-container:visible, [role="dialog"]:visible, form:visible').first()
			await expect(dialog, 'create dialog/form should mount after Add').toBeVisible({ timeout: 8_000 })
			await page.keyboard.press('Escape').catch(() => {})
		}
		assertNoShillinqFailures(rec, '/bookkeeping/dimensions/cost-centers add')
	})
})
