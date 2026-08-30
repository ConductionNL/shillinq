/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Inventory" navigation group.
 * Deep-links every Inventory manifest page, asserts the title + a genuine
 * CnIndexPage index surface, and that no shillinq-origin 5xx / page error
 * fires. Data-independent (bare environment, no seeded stock).
 */

import { test } from '@playwright/test'
import {
	gotoPage,
	assertIndexSurface,
	assertNoShillinqFailures,
	recordShillinqErrors,
} from './_helpers'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	// ⚠️ These two currently FAIL, and that is the spec working — see #860.
	// `2026-06-14-inventory-product-catalog` Task 14 is ticked `[x]` claiming it
	// added both routes plus the `Product` / `ProductAttribute` schemas. None of
	// it exists: 0 of the 590 declared page routes mention "product", and the
	// live API answers `Schema not found` for both. Left asserting on purpose —
	// quarantining them would turn a known missing capability into an invisible
	// one, which is the failure mode this whole spec file exists to prevent.
	{ route: '/inventory/products', title: 'Products' },
	{ route: '/inventory/product-attributes', title: 'Product Attributes' },
	{ route: '/inventory/reorder-rules', title: 'Reorder Rules' },
	{ route: '/inventory/low-stock-alerts', title: 'Low Stock Alerts' },
	{ route: '/inventory/stock-levels', title: 'Stock Levels' },
	{ route: '/inventory/stock', title: 'Stock Levels' },
	{ route: '/inventory/stock-by-location', title: 'Stock by Location' },
	{ route: '/inventory/reserve-stock', title: 'Reserve Stock' },
	{ route: '/inventory/barcodes', title: 'Barcodes' },
	{
		route: '/inventory/valuation',
		title: 'Valuation',
		titleRe: /Valuation|Waarder/i,
	},
	{ route: '/inventory/lots', title: 'Lots', titleRe: /Lots|Batch/i },
	{ route: '/inventory/expiry-alerts', title: 'Expiry Alerts' },
	{
		route: '/inventory/posting-config',
		title: 'Posting',
		titleRe: /Posting|Configurat/i,
	},
	// nav-six-clusters removed the standalone /inventory/posting-history page
	// (one of the 35 duplicate/dead pages consolidated by that change) — the
	// "Posting history" nav item now routes to the pre-existing General Ledger
	// page filtered by `subLedgerType: inventory`, not a distinct route+title.
	{
		route: '/general-ledger?subLedgerType=inventory',
		title: 'General Ledger',
	},
]

test.describe('shillinq spec-coverage — Inventory', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. This block is
	// where the cost was measured: ONE failing route entry
	// (`/inventory/products`) left the other 13 pages here unmeasured.

	for (const p of PAGES) {
		test(`Inventory › ${p.title} (${p.route})`, async ({ page }) => {
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
