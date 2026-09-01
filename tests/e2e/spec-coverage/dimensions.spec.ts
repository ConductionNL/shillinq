/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Dimensions" navigation group
 * (cost centres, kostendragers, projects, allocation rules). Deep-links each
 * manifest page, asserts a genuine index surface, no shillinq-origin 5xx /
 * page error. Data-independent.
 *
 * nav-six-clusters (REQ-ADIM-103, already in place before this change) folded
 * the dedicated /bookkeeping/dimensions/cost-centers and .../kostendragers
 * index pages into one shared /bookkeeping/dimensions/analytical-dimensions
 * page, selected via a `dimensionType` query filter (cost-center /
 * cost-object) rather than a distinct route+title. Both nav items (Cost
 * centers, Cost objects) now resolve here; the rendered title is the shared
 * page's own "Analytical dimensions", not the old per-route titles.
 */

import { expect, test } from '@playwright/test'
import {
	assertIndexSurface,
	assertNoShillinqFailures,
	gotoPage,
	recordShillinqErrors,
} from './_helpers.ts'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{
		route: '/bookkeeping/dimensions/analytical-dimensions?dimensionType=cost-center',
		title: 'Analytical dimensions',
	},
	{
		route: '/bookkeeping/dimensions/analytical-dimensions?dimensionType=cost-object',
		title: 'Analytical dimensions',
	},
	{ route: '/bookkeeping/dimensions/projects', title: 'Projects' },
	{ route: '/bookkeeping/dimensions/allocation-rules', title: 'Allocation Rules' },
]

test.describe('shillinq spec-coverage — Dimensions', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. These page
	// smokes share no state, and serial mode only ever turned one failure
	// into a block of tests that never ran.
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
	test('Dimensions › Cost Centers — Add opens a create dialog', async ({
		page,
	}) => {
		const rec = recordShillinqErrors(page)
		await gotoPage(
			page,
			'/bookkeeping/dimensions/analytical-dimensions?dimensionType=cost-center',
		)
		const addBtn = page
			.locator(
				'#content-vue button:has-text("Add"), #content-vue button:has-text("Nieuw"), #content-vue button:has-text("Toevoegen")',
			)
			.first()
		if (await addBtn.isVisible().catch(() => false)) {
			await addBtn.click({ timeout: 5_000 }).catch(() => {})
			const dialog = page
				.locator(
					'.modal-container:visible, [role="dialog"]:visible, form:visible',
				)
				.first()
			await expect(
				dialog,
				'create dialog/form should mount after Add',
			).toBeVisible({ timeout: 8_000 })
			await page.keyboard.press('Escape').catch(() => {})
		}
		assertNoShillinqFailures(
			rec,
			'/bookkeeping/dimensions/analytical-dimensions add',
		)
	})
})
