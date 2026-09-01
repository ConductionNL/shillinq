/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Belastingen" (Taxes) +
 * "Uitgestelde belastingen" (Deferred tax) navigation groups. Deep-links
 * each manifest page, asserts title + a genuine index surface, and no
 * shillinq-origin 5xx / page error. Data-independent.
 *
 * VATReconciliation (route `/belastingen/vat-by-period/:settlementPeriod`)
 * is a parameterised detail route, not a bare index page, so it is covered
 * via its parent VAT-by-period index here rather than deep-linked with a
 * dummy id.
 */

import { test } from '@playwright/test'
import {
	assertIndexSurface,
	assertNoShillinqFailures,
	gotoPage,
	recordShillinqErrors,
} from './_helpers.ts'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{ route: '/belastingen/kor', title: 'KOR', titleRe: /KOR/i },
	{ route: '/tax-filing-prep', title: 'Tax Filing Prep' },
	{ route: '/tax-estimates', title: 'Tax Estimates' },
	{ route: '/tax-configuration', title: 'Tax Configuration' },
	{
		// `btw-`, not `vat-`. The manifest declares `/belastingen/btw-aangiften`
		// and always has; this spec asked for a route that has never existed, so
		// vue-router fell through to the catch-all and the assertion measured the
		// dashboard. The `title` below was already the Dutch one, which is what
		// gives away that this was a stale route string and not a second page.
		route: '/belastingen/btw-aangiften',
		title: 'BTW-aangiften',
		titleRe: /BTW-?aangift/i,
	},
	{
		route: '/belastingen/vat-by-period',
		title: 'VAT by Period',
		titleRe: /VAT by Period|VAT/i,
	},
	{
		route: '/belastingen/icp-opgaaf',
		title: 'ICP-opgaaf',
		titleRe: /ICP-?opgaaf|ICP/i,
	},
	{
		// `btw-`, not `vat-` — same stale-route-string as btw-aangiften above.
		route: '/belastingen/btw-correcties',
		title: 'BTW-correcties',
		titleRe: /BTW-?correct/i,
	},
	{ route: '/belastingen/urenregistratie', title: 'Urenregistratie' },
	{
		route: '/belastingen/zzp-aftrek',
		title: 'ZZP-aftrek',
		titleRe: /ZZP-?aftrek/i,
	},
	{
		// `ib-aangifte`, not `ib-tax_return`. A Dutch→English substitution
		// (aangifte → tax_return) was applied to the ROUTE and TITLE here but
		// never to the manifest — the underscore in `tax_return` is the tell,
		// no route in this app uses one. The manifest declares
		// `/belastingen/ib-aangifte` titled "IB return", so accept either
		// wording rather than pinning this spec to whichever side renames next.
		route: '/belastingen/ib-aangifte',
		title: 'IB return',
		titleRe: /IB[-\s]?(aangift|return)/i,
	},
	// Uitgestelde belastingen (deferred tax)
	{
		route: '/belastingen/uitgestelde-belastingen/provisies',
		title: 'Belastinglatentie',
		titleRe: /Belastinglatentie|Provisi/i,
	},
	{
		route: '/belastingen/uitgestelde-belastingen/tijdelijke-verschillen',
		title: 'Tijdelijke verschillen',
	},
	{
		// `mutaties`, not `movements`. Same class again — and the title
		// (`Mutatieoverzicht`, matched by /Mutatie/i) is the tell.
		route: '/belastingen/uitgestelde-belastingen/mutaties',
		title: 'Mutatieoverzicht',
		titleRe: /Mutatie/i,
	},
	{
		route: '/belastingen/uitgestelde-belastingen/verliescompensatie',
		title: 'Compensabele verliezen',
		titleRe: /Compensabele|verliez/i,
	},
	{
		route: '/belastingen/uitgestelde-belastingen/etr-aansluiting',
		title: 'ETR-aansluiting',
		titleRe: /ETR/i,
	},
]

test.describe('shillinq spec-coverage — Belastingen', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. These page
	// smokes share no state, and serial mode only ever turned one failure
	// into a block of tests that never ran.
	for (const p of PAGES) {
		test(`Belastingen › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
