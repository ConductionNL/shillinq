/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Provincies BBV variant — Playwright UI coverage.
 *
 * Covers the three manifest pages declared by the
 * `bookkeeping-provincies-bbv-variant` change:
 *
 *  - BBV Compliance Dashboard (`/bbv-provincie/compliance-dashboard`)
 *  - Budget-to-Programme Linker index (`/bbv-provincie/budget-to-programme`)
 *  - Linker detail (`/bbv-provincie/budget-to-programme/:id`)
 *
 * ⚠️ WHY EVERY ASSERTION IN THIS FILE WAS REWRITTEN
 * -------------------------------------------------
 * The previous version asserted eleven bespoke `data-testid`s —
 * `bbv-compliance-dashboard`, `bbv-dashboard-exceptions`,
 * `bbv-dashboard-filters`, `bbv-linker-index`, `bbv-linker-table`,
 * `bbv-linker-filters`, `bbv-linker-detail`, `bbv-linker-mapping-status`,
 * `bbv-kpi-*`, `bbv-chart-*`, `bbv-filter-*`. Grep the repository: **none of
 * them exists anywhere outside this spec file.** All three pages are declared
 * with NO `component` (see `src/manifest.d/bookkeeping-provincies-bbv-variant
 * .json`), so they are rendered by the GENERIC manifest renderer, which emits
 * the library's own testids (`cn-dashboard-page`, `cn-index-page`,
 * `cn-detail-page`, `.grid-stack-item[gs-id=…]`) and never an app-invented one.
 * The one id that does exist elsewhere, `bbv-compliance-dashboard`, belongs to
 * `src/components/Dashboard/BBVComplianceDashboard.vue`, which is bound to the
 * WATERSCHAPPEN route `/bbv-dashboard`, not to any provincie route.
 *
 * The old file also contained four tests shaped
 *   `if (await x.isVisible().catch(() => false)) { await expect(x).toBeVisible() }`
 * which cannot fail under any circumstance — they report green whether the
 * affordance exists or not. Those are gone; each is now a real assertion or is
 * documented as uncovered.
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const DASHBOARD_ROUTE = '/bbv-provincie/compliance-dashboard'
const LINKER_INDEX_ROUTE = '/bbv-provincie/budget-to-programme'
const LINKER_DETAIL_ROUTE = '/bbv-provincie/budget-to-programme/smoke-id'

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
	const support = page
		.locator('[data-testid-modal="cn-support-dialog"], .cn-support-dialog')
		.first()
	if (await support.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await support.waitFor({ state: 'hidden', timeout: 3_000 }).catch(() => {})
	}
}

/** Strip `/index.php`, query and hash, and any trailing slash. */
function normalisePath(urlOrPath: string): string {
	const path = urlOrPath.startsWith('http')
		? new URL(urlOrPath).pathname
		: urlOrPath.split(/[?#]/)[0]
	return path.replace('/index.php', '').replace(/\/+$/, '') || '/'
}

/**
 * Deep-link to a manifest route and prove the SPA RESOLVED it.
 *
 * `src/main.js` ends its route table with `{ path: '/:pathMatch(.*)*',
 * redirect: '/' }`, so an undeclared route silently lands on the Dashboard and
 * satisfies any "are we still inside shillinq" check. Comparing the settled
 * path to the requested one turns that redirect back into a failure.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	const target = APP + route
	await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	expect(
		normalisePath(page.url()),
		`route ${route} must be declared by the manifest and resolve to itself — `
			+ "a different path means vue-router hit the '/:pathMatch(.*)*' catch-all "
			+ 'in src/main.js and redirected to the Dashboard',
	).toBe(normalisePath(target))
}

test.describe('Provincies BBV — Compliance Dashboard shell', () => {
	test.beforeEach(async ({ page }) => {
		await gotoRoute(page, DASHBOARD_ROUTE)
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-001/dashboard-route-resolves
	 *
	 * The route itself is declared and resolves — this is the half of the page
	 * that genuinely works, and it is asserted separately so the four tests
	 * below fail with a narrower meaning than "the page is broken".
	 */
	test('dashboard route resolves and mounts a dashboard page', async ({
		page,
	}) => {
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('cn-dashboard-page-header')).toContainText(
			/BBV Compliance Dashboard/i,
		)
	})

	/**
	 * ⚠️ THE FOUR TESTS BELOW FAIL, AND THAT IS THE SPEC WORKING — see #866.
	 *
	 * `src/manifest.d/bookkeeping-provincies-bbv-variant.json` declares this
	 * dashboard's whole body under `config.dashboard.{kpis,charts,exceptions,
	 * filters}`. **No renderer implements that vocabulary.**
	 *
	 * Measured, not assumed:
	 *   - `CnDashboardPage` declares exactly two content props, `widgets` and
	 *     `layout` (plus `dateRange` / `headerActions`). It has no `kpis`,
	 *     `charts`, `exceptions` or `filters` prop.
	 *   - `CnPageRenderer` has zero references to `config.dashboard`.
	 *   - `grep -r kpis` across all of @conduction/nextcloud-vue's `src/`
	 *     returns two hits, both in a docblock EXAMPLE naming a widget id.
	 *   - Of the twelve `type:"dashboard"` pages shillinq declares, ELEVEN use
	 *     `widgets` + `layout`. This one page is the only `config.dashboard`
	 *     in the manifest — a one-off written against a schema that does not
	 *     exist.
	 *
	 * So the page mounts an EMPTY CnDashboardPage: no KPI, no chart, no
	 * exceptions block, no filter facet, for every visitor. The tests are
	 * correct and the product is not.
	 *
	 * This is NOT a mechanical translation and is deliberately NOT attempted
	 * here: two of the four KPIs (`committed`, `spent`) and both chart series
	 * are sourced from the named OpenRegister aggregation
	 * `programmeBudgetVsActuals`, and `CnStatsBlockWidget` / `CnChartWidget`
	 * fetch `/api/objects/aggregations/{register}/{schema}/value` with a
	 * `metric` + `field`, not a named aggregation. Converting the fragment
	 * therefore needs a product decision about how programme budget-vs-actuals
	 * is surfaced — escalated rather than guessed.
	 */
	test('dashboard mounts the four KPI cards declared by the manifest', async ({
		page,
	}) => {
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})
		// The manifest declares KPIs "Total budget", "Committed", "Spent" and
		// "Remaining". Whatever renders them, their labels must be on the page.
		const body = page.locator('#app-content-vue, main').first()
		for (const label of ['Total budget', 'Committed', 'Spent', 'Remaining']) {
			await expect(
				body.getByText(label, { exact: false }).first(),
				`KPI "${label}" is declared in the manifest but does not render`,
			).toBeVisible({ timeout: 15_000 })
		}
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-001/dashboard-charts-render
	 */
	test('dashboard renders the budget-vs-actuals and trend charts', async ({
		page,
	}) => {
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})
		const body = page.locator('#app-content-vue, main').first()
		// Two charts are declared: "Budget vs. actuals" (bar) and "Trend"
		// (line). Each must mount a chart widget body — the ApexCharts SVG, or
		// CnChartWidget's own empty state on a data-less instance. A dashboard
		// with no chart widget at all fails.
		await expect(
			body.getByText('Budget vs. actuals', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
		await expect(body.getByText('Trend', { exact: false }).first()).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			body
				.locator(
					'.cn-chart-widget svg, [data-testid="cn-chart-widget-empty"]',
				)
				.first(),
		).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-003/exceptions-block-renders
	 */
	test('dashboard mounts the exceptions block with its declared empty state', async ({
		page,
	}) => {
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})
		const body = page.locator('#app-content-vue, main').first()
		// The manifest declares `exceptions.title: "Exceptions"` with
		// `emptyState: "No overspends"`. Either the populated list or that
		// empty-state copy is a valid mount; neither present is a failure.
		await expect(
			body.getByText('Exceptions', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-002/dashboard-filters-render
	 */
	test('dashboard renders the three declared filter facets', async ({ page }) => {
		await expect(page.getByTestId('cn-dashboard-page')).toBeVisible({
			timeout: 15_000,
		})
		const body = page.locator('#app-content-vue, main').first()
		// `filters[]` declares Programme (multi-select), Fiscal year (select)
		// and Budget status (multi-select).
		for (const label of ['Programme', 'Fiscal year', 'Budget status']) {
			await expect(
				body.getByText(label, { exact: false }).first(),
				`filter facet "${label}" is declared in the manifest but does not render`,
			).toBeVisible({ timeout: 15_000 })
		}
	})
})

test.describe('Provincies BBV — Budget-to-Programme Linker index', () => {
	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-004/linker-queries-its-declared-source
	 *
	 * The linker must be bound to the GL-line source the manifest declares
	 * (`register: "shillinq"`, `schema: "GLLine"`). Asserting the OUTBOUND
	 * query rather than rendered rows or column headers keeps this
	 * data-independent: CI seeds no GL lines, and an empty CnIndexPage renders
	 * its empty-content block instead of a table, so a column-header assertion
	 * would fail for a reason that has nothing to do with the binding. A page
	 * wired to the wrong schema, or wired to nothing, fails here.
	 *
	 * This test navigates itself — the wait must be armed BEFORE the
	 * navigation or the request is already gone by the time we listen, so it
	 * cannot live under the `beforeEach` the other two use.
	 */
	test('linker index queries the GL-line source declared by the manifest', async ({
		page,
	}) => {
		const query = page.waitForResponse(
			(r) =>
				/\/apps\/openregister\/api\/objects\/shillinq\/GLLine/i.test(
					r.url(),
				),
			{ timeout: 25_000 },
		)
		await gotoRoute(page, LINKER_INDEX_ROUTE)
		const response = await query
		expect(response.status()).toBeLessThan(500)
	})

	test.describe('mounted surface', () => {
		test.beforeEach(async ({ page }) => {
			await gotoRoute(page, LINKER_INDEX_ROUTE)
		})

		/**
		 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-001/linker-index-renders
		 *
		 * The linker index is a `type:"index"` page with no `component`, so it is
		 * rendered by CnIndexPage. Assert the library's real index surface: the
		 * page title, and one of the recognised index bodies (a data table, an
		 * empty-content block, a list, or the primary-action toolbar). This holds
		 * on a bare CI instance with no seeded GL lines — an empty CnIndexPage
		 * still renders its empty-content block and its toolbar.
		 */
		test('linker index mounts a real index surface', async ({ page }) => {
			await expect(page.getByTestId('cn-index-page')).toBeVisible({
				timeout: 15_000,
			})

			const host = page.locator('#app-content-vue, main').first()
			await expect(
				host.getByText('Budget Links', { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })

			const tables = await host.locator('table:visible').count()
			const empty = await host
				.locator('.empty-content, .emptycontent, [class*="empty-content" i]')
				.count()
			const rows = await host.locator('[role="row"]').count()
			const actionsBar = await page.getByTestId('cn-actions-bar').count()
			expect(
				tables + empty + rows + actionsBar,
				'the linker index rendered no table, no empty state, no rows and no actions bar',
			).toBeGreaterThan(0)
		})

		/**
		 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-001/linker-filter-facets
		 *
		 * `config.filters[]` declares three facets — Account type, Programme,
		 * Assignment status. `filters` IS a supported CnIndexPage concept (unlike
		 * `bulkActions` / `mappingStatus` below), so their declared labels must
		 * appear on the page. The previous version of this test asserted
		 * `bbv-linker-filters` and three `bbv-linker-filter-*` testids, none of
		 * which exists anywhere outside that spec file.
		 *
		 * ⚠️ IT IS RED, and the rewrite is what proved why — #866. The page itself
		 * is fine: the two tests above pass, including the one asserting that this
		 * index really does query `/apps/openregister/api/objects/shillinq/GLLine`.
		 * Only the DECLARED BODY fails to appear, so `config.filters[]` joins
		 * `config.dashboard.*`, `config.bulkActions[]` and `config.mappingStatus`
		 * on this fragment's list of keys nothing reads.
		 */
		test('linker index renders the three declared filter facets', async ({
			page,
		}) => {
			await expect(page.getByTestId('cn-index-page')).toBeVisible({
				timeout: 15_000,
			})
			const host = page.locator('#app-content-vue, main').first()
			for (const label of ['Account type', 'Programme', 'Assignment status']) {
				await expect(
					host.getByText(label, { exact: false }).first(),
					`filter facet "${label}" is declared in config.filters[] but does not render`,
				).toBeVisible({ timeout: 15_000 })
			}
		})

		/**
		 * ⚠️ NOT COVERED HERE, AND SAID SO RATHER THAN FAKED.
		 *
		 * `config.bulkActions[]` (the "Link to Programme" CTA + its CnFormDialog
		 * with Target Programme / Effective Date) and `config.mappingStatus` are
		 * declared by the fragment and are NOT props of CnIndexPage — the library
		 * has zero references to either key, exactly like `config.dashboard` on the
		 * dashboard page above (#866). The previous file "covered" both with
		 * `if (await cta.isVisible()) { expect(cta).toBeVisible() }`, an assertion
		 * that cannot fail and reported green while nothing rendered.
		 *
		 * Rather than keep a test that measures nothing, the claim is withdrawn:
		 * there is no `@e2e` tag for REQ-BBL-001's bulk-link scenario in this file
		 * any more. It belongs to #866 with the dashboard vocabulary.
		 */
	})
})

test.describe('Provincies BBV — Linker detail (single GL-line edit)', () => {
	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-003/linker-detail-handles-missing-record
	 *
	 * `smoke-id` is a synthetic id that resolves to no object. A `type:"detail"`
	 * page must still mount its route and report the miss — it must not blank
	 * the surface, and it must not offer an editable form for a record that
	 * does not exist. Driving a real record needs a seeded GL line, which the
	 * CI seed currently drops (DECISION-3 on the fleet board), so the
	 * populated-detail scenario is not claimed as covered here.
	 */
	test('detail route resolves and reports a record it cannot load', async ({
		page,
	}) => {
		await gotoRoute(page, LINKER_DETAIL_ROUTE)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('cn-detail-page-header')).toBeVisible()
	})
})

/**
 * ⚠️ REQ-BBC-004 (Dashboard Refresh Interval, admin settings) HAS NO COVERAGE
 * HERE, AND THE FILE NO LONGER PRETENDS IT DOES.
 *
 * The removed test navigated to `${APP}/admin` — a route the manifest does not
 * declare at all (checked against all 590 declared page routes), so vue-router
 * sent it to the Dashboard via the catch-all — and then ran
 *   `if (await refresh.isVisible()) { await expect(refresh).toBeVisible() }`
 * which is green whatever is on screen. The dropdown it looked for,
 * `shillinq-dashboard-refresh-interval`, exists nowhere in `src/`.
 *
 * A test that navigates to a route that does not exist and then asserts
 * nothing is the purest form of an invisible pass. Withdrawing the claim is
 * the honest outcome; building the setting belongs to #866.
 */
