/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Cashflow" + "Pensioen"
 * navigation groups. Deep-links each manifest page, asserts a genuine index
 * surface, no shillinq-origin 5xx / page error. Data-independent.
 */

import { test } from '@playwright/test'
import {
	assertIndexSurface,
	assertNoShillinqFailures,
	gotoPage,
	recordShillinqErrors,
} from './_helpers.ts'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{
		route: '/cashflow/dashboard',
		title: 'Cashflow Dashboard',
		titleRe: /Cashflow/i,
	},
	{ route: '/cashflow/scenarios', title: 'Scenarios' },
	{ route: '/cashflow/buffer-policy', title: 'Buffer Policy' },
	{
		route: '/cashflow/recurring',
		title: 'Recurring Costs',
		titleRe: /Recurring/i,
	},
	{
		route: '/cashflow/calibration',
		title: 'Calibration Report',
		titleRe: /Calibration/i,
	},
	{ route: '/pension/plans', title: 'Pension Plans' },
	{
		route: '/pension/valuations',
		title: 'Actuarial Valuations',
		titleRe: /Valuation/i,
	},
	{ route: '/pension/disclosure-tables', title: 'Disclosure Tables' },
]

test.describe('shillinq spec-coverage — Cashflow & Pensioen', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. These page
	// smokes share no state, and serial mode only ever turned one failure
	// into a block of tests that never ran.
	for (const p of PAGES) {
		test(`${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
