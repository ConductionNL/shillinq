/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Projecten" navigation group
 * (project overview, rates, utilisation). Deep-links each manifest page,
 * asserts a genuine index surface, no shillinq-origin 5xx / page error.
 * Data-independent.
 *
 * nav-six-clusters deleted the standalone /projecten (ProjectenOverzicht)
 * page per REQ-NAVIA-002 — it was a duplicate of the pre-existing "Projects"
 * page at /bookkeeping/dimensions/projects, which the Bookkeeping cluster
 * landing page now links to directly.
 */

import { test } from '@playwright/test'
import {
	gotoPage,
	assertIndexSurface,
	assertNoShillinqFailures,
	recordShillinqErrors,
} from './_helpers'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{
		route: '/bookkeeping/dimensions/projects',
		title: 'Projects',
		titleRe: /Project/i,
	},
	{ route: '/tarieven', title: 'Tarieven' },
	{ route: '/utilisatie', title: 'Utilisatie' },
]

test.describe('shillinq spec-coverage — Projecten', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. These page
	// smokes share no state, and serial mode only ever turned one failure
	// into a block of tests that never ran.
	for (const p of PAGES) {
		test(`Projecten › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
