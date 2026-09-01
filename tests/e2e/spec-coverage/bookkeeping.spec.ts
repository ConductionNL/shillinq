/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural UI coverage — Shillinq "Bookkeeping" navigation group.
 *
 * Drives every page in the Bookkeeping menu cluster through the real UI:
 * deep-links the manifest route, confirms the SPA stays on the shillinq
 * surface, the page title renders, a genuine CnIndexPage index surface
 * mounts (table | empty-state | list | primary-action toolbar), and no
 * shillinq-origin 5xx / uncaught page error fires. Data-independent — holds
 * on a bare environment with no seeded GL/AR/AP fixtures.
 */

import { test } from '@playwright/test'
import {
	assertIndexSurface,
	assertNoShillinqFailures,
	gotoPage,
	recordShillinqErrors,
} from './_helpers.ts'

const PAGES: Array<{ route: string; title: string; titleRe?: RegExp }> = [
	{ route: '/chart-of-accounts', title: 'Chart of Accounts' },
	{ route: '/general-ledger', title: 'General Ledger' },
	{ route: '/journals', title: 'Journals' },
	{ route: '/bank-connections', title: 'Bank Connections' },
	{ route: '/bookkeeping/bank-reconciliation', title: 'Bank Reconciliation' },
	{ route: '/bookkeeping/matching-rules', title: 'Matching Rules' },
	{ route: '/fixed-assets', title: 'Fixed Assets' },
	// Renamed in the manifest; the routes below are what it actually declares.
	// 'Vendors' became 'Payees' (`/bookkeeping/vendors` is declared by no
	// manifest source and fell through main.js's '/:pathMatch(.*)*' catch-all
	// onto the Dashboard), Accounts Payable moved to /ap-transactions, and AP
	// Aging to /ap-aging-t2. `/bookkeeping/accounts-payable` and the bare
	// `/bookkeeping/ap-aging` were taken from spec prose rather than from the
	// manifest; the latter's page id `APAging` is listed in menu-layout.json
	// `removals` and has no page entry at all.
	{ route: '/bookkeeping/payees', title: 'Payees' },
	{ route: '/bookkeeping/ap-transactions', title: 'Accounts Payable' },
	{ route: '/bookkeeping/ap-aging-t2', title: 'AP Aging' },
	{ route: '/bookkeeping/payment-runs', title: 'Payment Runs' },
	{ route: '/bookkeeping/customers', title: 'Customers' },
	{ route: '/bookkeeping/accounts-receivable', title: 'Accounts Receivable' },
	{ route: '/bookkeeping/ar-aging', title: 'AR Aging' },
	{ route: '/bookkeeping/dunning-timeline', title: 'Dunning Timeline' },
	{ route: '/bookkeeping/dunning/ladders', title: 'Dunning Ladders' },
	{
		route: '/bookkeeping/dunning/overrides',
		title: 'Klant Ladder Overrides',
		titleRe: /Override|Klant/i,
	},
	{ route: '/bookkeeping/dunning/runs', title: 'Dunning Runs' },
	{ route: '/bookkeeping/dunning/incasso-kosten', title: 'Incasso Kosten' },
	{ route: '/bookkeeping/dunning/oninbaar', title: 'Oninbare Afschrijvingen' },
	{ route: '/waterschapsbelastingen', title: 'Waterschapsbelastingen' },
	{ route: '/gr/deelnemers', title: 'Deelnemers' },
	{ route: '/gr/verdeelsleutels', title: 'Verdeelsleutels' },
	{
		route: '/gr/geconsolideerd',
		title: 'Geconsolideerde view',
		titleRe: /Geconsolideerde|consolidat/i,
	},
	{ route: '/financial-statements/balance-sheet', title: 'Balance Sheet' },
	{ route: '/financial-statements/trial-balance', title: 'Trial Balance' },
	{
		route: '/financial-statements/trial-balance-lines',
		title: 'Trial Balance',
		titleRe: /Trial Balance/i,
	},
	// nav-six-clusters removed the combined /financial-statements/consolidations
	// overview page (one of the 35 duplicate/dead pages consolidated by that
	// change) — it is replaced by three dedicated pages, all already declared
	// in src/manifest.d/bookkeeping-consolidation-commercial.json and now
	// reachable from the Bookkeeping cluster landing page.
	{ route: '/consolidation/groups', title: 'Consolidation Groups' },
	{ route: '/consolidation/periods', title: 'Consolidation Periods' },
	{ route: '/consolidation/reports', title: 'Consolidated Reports' },
	{
		route: '/financial-statements/consolidated-report',
		title: 'Consolidated Report',
	},
	// The manifest route is Dutch (`/iv3-rapportages`, page id `Iv3Rapportages`)
	// while its title is already English ("IV3 reports"). `/iv3-reports` is the
	// path named in the ARCHIVED change's tasks.md, not the path that was built.
	// Matching the route as declared rather than renaming it here: a route slug
	// is a bookmarkable URL, so changing it is a product decision, not a test fix.
	{ route: '/iv3-rapportages', title: 'IV3 reports', titleRe: /IV3/i },
	{ route: '/emu-rapportage', title: 'EMU-rapportage' },
	{
		route: '/bookkeeping/r-d-subsidies',
		title: 'R&D Subsidies',
		titleRe: /R&D|R\s*&\s*D|Subsid/i,
	},
	{ route: '/wbso/tags', title: 'WBSO Tags' },
	{ route: '/wbso/export', title: 'WBSO Export' },
	{ route: '/bookkeeping/fiscal-years', title: 'Fiscal Years' },
	{
		route: '/bookkeeping/year-end-close-checklist',
		title: 'Year-End Close Checklist',
		titleRe: /Year-End|Close/i,
	},
	{ route: '/bookkeeping/closing-entries', title: 'Closing Entries' },
	{ route: '/bookkeeping/audit-trail', title: 'Audit Trail' },
]

test.describe('shillinq spec-coverage — Bookkeeping', () => {
	// No `mode: 'serial'` — see the header of ./_helpers.ts. This block paid
	// the largest price for it: ONE failing route entry
	// (`/bookkeeping/vendors`) left the other 30 pages here unmeasured.

	for (const p of PAGES) {
		test(`Bookkeeping › ${p.title} (${p.route})`, async ({ page }) => {
			// Scoped to this test. It was hoisted to describe scope, which
			// read like cross-test state but never was — every test assigned
			// it before reading it.
			const rec = recordShillinqErrors(page)
			await gotoPage(page, p.route)
			await assertIndexSurface(page, p.title, { titleRe: p.titleRe })
			assertNoShillinqFailures(rec, p.route)
		})
	}
})
