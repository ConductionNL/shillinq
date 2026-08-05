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
import { gotoPage, assertIndexSurface, assertNoShillinqFailures, recordShillinqErrors } from './_helpers'

const PAGES: Array<{ route: string, title: string, titleRe?: RegExp }> = [
	{ route: '/belastingen/kor', title: 'KOR', titleRe: /KOR/i },
	{ route: '/tax-filing-prep', title: 'Tax Filing Prep' },
	{ route: '/tax-estimates', title: 'Tax Estimates' },
	{ route: '/tax-configuration', title: 'Tax Configuration' },
	{ route: '/belastingen/btw-aangiften', title: 'BTW-aangiften', titleRe: /BTW-?aangift/i },
	{ route: '/belastingen/vat-by-period', title: 'VAT by Period', titleRe: /VAT by Period|VAT/i },
	{ route: '/belastingen/icp-opgaaf', title: 'ICP-opgaaf', titleRe: /ICP-?opgaaf|ICP/i },
	{ route: '/belastingen/btw-correcties', title: 'BTW-correcties', titleRe: /BTW-?correct/i },
	{ route: '/belastingen/urenregistratie', title: 'Urenregistratie' },
	{ route: '/belastingen/zzp-aftrek', title: 'ZZP-aftrek', titleRe: /ZZP-?aftrek/i },
	{ route: '/belastingen/ib-aangifte', title: 'IB-aangifte', titleRe: /IB-?aangift/i },
	// Uitgestelde belastingen (deferred tax)
	{ route: '/belastingen/uitgestelde-belastingen/provisies', title: 'Belastinglatentie', titleRe: /Belastinglatentie|Provisi/i },
	{ route: '/belastingen/uitgestelde-belastingen/tijdelijke-verschillen', title: 'Tijdelijke verschillen' },
	{ route: '/belastingen/uitgestelde-belastingen/mutaties', title: 'Mutatieoverzicht', titleRe: /Mutatie/i },
	{ route: '/belastingen/uitgestelde-belastingen/verliescompensatie', title: 'Compensabele verliezen', titleRe: /Compensabele|verliez/i },
	{ route: '/belastingen/uitgestelde-belastingen/etr-aansluiting', title: 'ETR-aansluiting', titleRe: /ETR/i },
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
