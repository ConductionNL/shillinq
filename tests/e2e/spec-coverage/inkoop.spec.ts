/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Inkoop" (Procurement)
 * navigation group: purchase orders, goods receipts, supplier invoices,
 * 3-way matches + exceptions, receipts, expense claims, mileage log.
 * Deep-links each manifest page, asserts a genuine index surface, no
 * shillinq-origin 5xx / page error. Data-independent.
 */

import { test } from '@playwright/test'
import {
	assertIndexSurface,
	assertNoShillinqFailures,
	gotoPage,
	recordShillinqErrors,
} from './_helpers'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{ route: '/inkoop/purchase-orders', title: 'Purchase Orders' },
	{ route: '/inkoop/goods-receipts', title: 'Goods Receipts' },
	{ route: '/inkoop/supplier-invoices', title: 'Supplier Invoices' },
	{ route: '/inkoop/3way-matches', title: '3-way Matches', titleRe: /3-?way/i },
	// REMOVED: { route: '/inkoop/3way-matches/exceptions', title: 'Match Exceptions' }
	//
	// There is no such index page. The only 3way route the manifest declares
	// besides the index above is `/inkoop/3way-matches/:id` (ThreeWayMatchDetail,
	// in bookkeeping-purchase-order-3way-08-exception-workflow.json), so
	// `/exceptions` was matching the DETAIL route with the literal string
	// "exceptions" as its :id. The exception workflow is reached from a match,
	// not from a standalone list.
	//
	// It passed for as long as it did only because the panel's loader called
	// `/apps/shillinq/api/openregister/objects/ThreeWayMatch/exceptions`, a route
	// that did not exist and that the SPA catch-all answered with HTTP 200 and
	// HTML (#1209). The load "succeeded", no error state was set, and the panel
	// rendered enough chrome to satisfy assertIndexSurface. Once that url 404s
	// for real, the panel correctly shows its error state — a bare text div,
	// which is not an index surface — and the assertion fails.
	//
	// So this entry was green because the feature was broken, and asserting an
	// INDEX surface against a DETAIL route was never going to mean anything.
	// Removed rather than repaired: a meaningful exception-workflow test has to
	// open a real match and drive the accept/dispute/reject dispositions
	// (REQ-PO3W-005), which is a detail-page journey and does not belong in this
	// index-surface smoke list. Tracked in #1209.
	{ route: '/inkoop/receipts', title: 'Receipts' },
	{ route: '/inkoop/expense-claims', title: 'Expense Claims' },
	{ route: '/inkoop/mileage-log', title: 'Mileage Log' },
]

test.describe('shillinq spec-coverage — Inkoop', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. These page
	// smokes share no state, and serial mode only ever turned one failure
	// into a block of tests that never ran.
	for (const p of PAGES) {
		test(`Inkoop › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
