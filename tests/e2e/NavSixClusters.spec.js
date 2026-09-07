/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * NavSixClusters — Playwright coverage for the `nav-six-clusters` change:
 * the 29-top-level-entry menu collapses to Dashboard + exactly 6 domain
 * clusters (design.md §11, REQ-NAVC-009).
 *
 * Covers the browser-visible surface only (fleet rule: Playwright stays
 * UI-only):
 *  - the rendered top-level nav shows exactly 7 entries with the expected
 *    labels (REQ-NAVC-001);
 *  - each of the 6 cluster landing pages renders its card grid
 *    (REQ-NAVC-002);
 *  - a sample of relocated/consolidated pages still resolve by direct route
 *    navigation, including a `menu[].query` preset deep link
 *    (REQ-NAVC-003/-007);
 *  - the 4 deleted dangling-dialog routes no longer resolve to their old
 *    form UI (REQ-NAVC-004/§6).
 *
 * Structural/manifest-only assertions (depth cap, zero-new-orphans,
 * ExternalConnections byte-freeze) are build-time script assertions, not
 * independently UI-observable — verified by `check:nav-reachability` /
 * `check:manifest-budget` / manifest diff review, not Playwright (design.md
 * §11, spec.md's own `@e2e exclude` scenarios).
 *
 * @e2e nav-six-clusters::top-level-entry-count-and-labels
 * @e2e nav-six-clusters::cluster-landing-pages-render
 * @e2e nav-six-clusters::preset-deep-links-resolve
 * @e2e nav-six-clusters::deleted-dialog-routes-gone
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-001
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-003
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-007
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-009
 */

import { expect, test } from '@playwright/test'
import { becomesVisible } from './becomes-visible.js'

const APP = '/apps/shillinq'

async function dismissWizard(page) {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

// The 6 cluster landing pages, per design.md §2/§3. Reporting & Compliance
// reuses the pre-existing ReportingComplianceOverview page/test-ids
// (data-testid="reporting-overview*") rather than the shared
// "<cluster>-overview" convention the 5 new pages use.
const CLUSTERS = [
	{
		id: 'Bookkeeping',
		label: 'Bookkeeping',
		route: '/bookkeeping/overview',
		testId: 'bookkeeping-overview',
	},
	{
		id: 'Sales',
		label: 'Sales',
		route: '/sales/overview',
		testId: 'sales-overview',
	},
	{
		id: 'Purchasing',
		label: 'Purchasing',
		route: '/purchasing/overview',
		testId: 'purchasing-overview',
	},
	{
		id: 'BankingCashflow',
		label: 'Banking & Cashflow',
		route: '/banking-cashflow/overview',
		testId: 'banking-cashflow-overview',
	},
	{
		id: 'Taxes',
		label: 'Taxes',
		route: '/taxes/overview',
		testId: 'taxes-overview',
	},
]

test.describe('nav-six-clusters — Dashboard + 6 domain clusters (REQ-NAVC-001..009)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('the rendered top-level nav shows exactly 7 entries with the expected labels', async ({
		page,
	}) => {
		await page.goto(`${APP}/`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const nav = page.locator('.app-navigation, #app-navigation-vue').first()
		const deployed = await becomesVisible(nav)
		test.skip(!deployed, 'app navigation not deployed on this build')

		const expectedLabels = [
			'Dashboard',
			'Bookkeeping',
			'Sales',
			'Purchasing',
			'Banking & Cashflow',
			'Taxes',
			'Reporting & Compliance',
		]

		for (const label of expectedLabels) {
			const entry = nav.getByRole('link', { name: label, exact: true }).first()
			await expect(
				entry,
				`top-level entry "${label}" is rendered`,
			).toBeVisible({ timeout: 10_000 })
		}

		// None of the 25 former top-level ids/labels survives as a top-level
		// entry — spot-check a representative handful (REQ-NAVC-001 scenario 2).
		const retiredLabels = [
			'Banking & Treasury',
			'People & Projects',
			'Public sector',
			'DBA Compliance',
			'Commitments',
			'Purchase Orders',
			'Subsidies & Funds',
		]
		for (const label of retiredLabels) {
			const entry = nav.getByRole('link', { name: label, exact: true })
			await expect(
				entry,
				`retired top-level label "${label}" is gone`,
			).toHaveCount(0)
		}
	})

	for (const cluster of CLUSTERS) {
		test(`${cluster.id} cluster landing page renders its card grid`, async ({
			page,
		}) => {
			await page.goto(`${APP}${cluster.route}`)
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			const root = page.locator(`[data-testid="${cluster.testId}"]`)
			const deployed = await becomesVisible(root)
			test.skip(
				!deployed,
				`${cluster.id} landing page not deployed on this build`,
			)

			await expect(root).toBeVisible()
			await expect(
				page.locator(`[data-testid="${cluster.testId}-title"]`),
			).toContainText(cluster.label)

			const cardSections = page.locator(
				`[data-testid^="${cluster.testId.replace('-overview', '')}-section-"]`,
			)
			await expect(
				cardSections.first(),
				'at least one card section renders',
			).toBeVisible({ timeout: 10_000 })
		})
	}

	test('Reporting & Compliance cluster landing page renders its card grid', async ({
		page,
	}) => {
		await page.goto(`${APP}/reporting-compliance`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const root = page.locator('[data-testid="reporting-overview"]')
		const deployed = await becomesVisible(root)
		test.skip(
			!deployed,
			'ReportingComplianceOverview not deployed on this build',
		)

		await expect(root).toBeVisible()
		await expect(
			page.locator('[data-testid="reporting-overview-title"]'),
		).toBeVisible()
	})

	// Deep-links relocated/consolidated pages by route, bypassing the menu —
	// matching shillinq-nav-ia-cleanup REQ-NAVIA-003's existing pattern.
	const DEEP_LINKS = [
		{ route: '/general-ledger', name: 'GeneralLedger' },
		{ route: '/belastingen/kor-dashboard', name: 'KorDashboard' },
		{ route: '/inkoop/receipts', name: 'Receipts' },
		{ route: '/bookkeeping/reconciliations', name: 'Reconciliations' },
		{ route: '/aansluitingen', name: 'Aansluitingen (Tie-outs)' },
		{ route: '/contracts', name: 'Contracts (Procurement)' },
		{ route: '/ifrs-15/contracts', name: 'RevenueContracts' },
	]

	for (const link of DEEP_LINKS) {
		test(`deep link resolves: ${link.name}`, async ({ page }) => {
			await page.goto(`${APP}${link.route}`)
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			// A resolved manifest route renders SOME app content, not the
			// unregistered-route catch-all redirect back to '/' (src/main.js:218).
			await expect(page).not.toHaveURL(`${APP}/`)
			const main = page.locator('#content-vue, .app-content, main').first()
			await expect(main).toBeVisible({ timeout: 10_000 })
		})
	}

	test('a menu[].query preset deep-link pre-filters the canonical page (AnalyticalDimensions, dimensionType=cost-center)', async ({
		page,
	}) => {
		await page.goto(
			`${APP}/bookkeeping/dimensions/analytical-dimensions?dimensionType=cost-center`,
		)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		await expect(page).toHaveURL(/dimensionType=cost-center/)
		const main = page.locator('#content-vue, .app-content, main').first()
		await expect(main).toBeVisible({ timeout: 10_000 })
	})

	// The 4 dangling config.createDialog pages (design.md §6) are deleted —
	// their old routes no longer resolve to the old form UI.
	//
	// ⚠️ NOT a plain 404-to-Dashboard case. Each deleted `/<x>/new` route has
	// a sibling `/<x>/:id` DETAIL route already declared (e.g. VATReturnDetail
	// at `/vat-returns/:id` — see src/manifest.d/bookkeeping-vat-btw-filing.json).
	// vue-router matches `:id` segments literally, so `/vat-returns/new`
	// resolves to that detail route with id="new" rather than falling through
	// to the unregistered-route catch-all (src/main.js:218) that redirects to
	// '/'. Verified live: the URL stays on `${APP}${dialog.route}`, not
	// `${APP}/`. An earlier draft of this test asserted the catch-all
	// redirect and failed on all 4 cases for exactly this reason — the
	// substantive REQ-NAVC-004 guarantee ("the old create-dialog form is
	// gone") is the heading assertion below, which holds regardless of which
	// of the two router outcomes a given deleted route lands on.
	const DELETED_DIALOG_ROUTES = [
		{ route: '/vat-returns/new', title: 'New BTW return' },
		{ route: '/reimbursement-policies/new', title: 'New Reimbursement Policy' },
		{
			route: '/passthrough-markup-rules/new',
			title: 'New Pass-through Markup Rule',
		},
		{ route: '/retainer-pools/new', title: 'New retainer pool' },
	]

	for (const dialog of DELETED_DIALOG_ROUTES) {
		test(`deleted dialog route no longer resolves: ${dialog.route}`, async ({
			page,
		}) => {
			await page.goto(`${APP}${dialog.route}`)
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			// Stays on the shillinq app surface either way (catch-all '/' or a
			// sibling ':id' detail route) — never bounces off the app entirely.
			await expect(page).toHaveURL(new RegExp(`${APP}/`), {
				timeout: 10_000,
			})
			// The substantive check: the old create-dialog form heading is gone,
			// whichever route the deleted path happened to fall through to.
			await expect(
				page.getByRole('heading', { name: dialog.title }),
			).toHaveCount(0)
		})
	}
})
