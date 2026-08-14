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
	gotoPage,
	assertIndexSurface,
	assertNoShillinqFailures,
	recordShillinqErrors,
} from './_helpers'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{ route: '/inkoop/purchase-orders', title: 'Purchase Orders' },
	{ route: '/inkoop/goods-receipts', title: 'Goods Receipts' },
	{ route: '/inkoop/supplier-invoices', title: 'Supplier Invoices' },
	{ route: '/inkoop/3way-matches', title: '3-way Matches', titleRe: /3-?way/i },
	{
		route: '/inkoop/3way-matches/exceptions',
		title: 'Match Exceptions',
		titleRe: /Exception/i,
	},
	{ route: '/inkoop/receipts', title: 'Receipts' },
	{ route: '/inkoop/expense-claims', title: 'Expense Claims' },
	{ route: '/inkoop/mileage-log', title: 'Mileage Log' },
]

test.describe('shillinq spec-coverage — Inkoop', () => {
	test.describe.configure({ mode: 'serial' })
	for (const p of PAGES) {
		test(`Inkoop › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
