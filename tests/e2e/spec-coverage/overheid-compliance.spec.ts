/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq government / public-sector
 * navigation groups: "Overheid", "Subsidies", "Compliance",
 * "Administratie". Deep-links each manifest page, asserts a genuine index
 * surface, no shillinq-origin 5xx / page error. Data-independent.
 */

import { test } from '@playwright/test'
import {
	gotoPage,
	assertIndexSurface,
	assertNoShillinqFailures,
	recordShillinqErrors,
} from './_helpers'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	// Overheid
	{ route: '/iv3-rapportages', title: 'IV3-rapportages', titleRe: /IV3/i },
	{ route: '/overheid/bbv-mapping', title: 'BBV-mapping', titleRe: /BBV/i },
	{ route: '/overheid/bcf-claims', title: 'BCF-claims', titleRe: /BCF/i },
	{
		route: '/overheid/schatkist-positie',
		title: 'Schatkist-positie',
		titleRe: /Schatkist/i,
	},
	// Subsidies
	{ route: '/subsidies/overzicht', title: 'Subsidies', titleRe: /Subsid/i },
	{
		route: '/subsidies/verleend',
		title: 'Verleende subsidies',
		titleRe: /Verleend/i,
	},
	{
		route: '/subsidies/teruggevorderd',
		title: 'Terugvorderingen',
		titleRe: /Terugvorder/i,
	},
	// Compliance
	{ route: '/sisa-rapportages', title: 'SiSa-rapportages', titleRe: /SiSa/i },
	{
		route: '/compliance-audit',
		title: 'Compliance audittrail',
		titleRe: /Compliance|audittrail/i,
	},
	{
		route: '/management-letter',
		title: 'Management letters',
		titleRe: /Management letter/i,
	},
	{
		route: '/audit-documents',
		title: 'Auditdocumenten',
		titleRe: /Auditdocument|Audit/i,
	},
	// Administratie
	{ route: '/administratie/bewaartermijnen', title: 'Bewaartermijnen' },
]

test.describe('shillinq spec-coverage — Overheid / Subsidies / Compliance', () => {
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
