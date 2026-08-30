/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — the ReportingComplianceOverview cluster
 * landing page.
 *
 * ## The gap this closes
 *
 * `ReportingComplianceOverview` (`src/manifest.d/reporting-compliance.json`) is
 * the landing page of one of the six top-level clusters, and per
 * `src/manifest.d/nav-six-clusters-landing-pages.json`'s own `_meta` it is the
 * ONE cluster whose landing page was NOT created by `nav-six-clusters`: the
 * other five got a fresh `ClusterOverview.vue` wrapper, Reporting & Compliance
 * "reuses the pre-existing ReportingComplianceOverview as-is". Reuse is exactly
 * why it went uncovered.
 *
 * The only existing reference to it was `NavSixClusters.spec.js`, whose single
 * Reporting test is guarded by
 *
 *     test.skip(!deployed, 'ReportingComplianceOverview not deployed on this build')
 *
 * and asserts nothing beyond the root element plus a title being present. A
 * skip and a pass are indistinguishable in the summary line, so that test
 * cannot report the page as broken — it reports it as absent. Nothing anywhere
 * asserted that the page renders its CONTENT.
 *
 * ## What is covered
 *
 * The page is a card catalogue: `ReportingComplianceOverview.vue` fetches
 * `/api/reporting/types`, merges the static `reportViews` catalogue, and groups
 * the result into `reporting-group-<category>` sections of
 * `reporting-card-<reportId>` cards. Note the failure shape that makes the
 * assertions below non-negotiable: if the catalogue request throws, the
 * component's `catch` renders `reporting-overview-error` and `reports` stays
 * EMPTY — so `reporting-overview` and `reporting-overview-title` are BOTH still
 * visible on a page that shows the user nothing. Asserting the root, or the
 * title, or "some app content exists", cannot fail for the way this page
 * actually breaks. The tests therefore assert the groups and cards.
 *
 * Covered:
 *  - the route resolves and the page renders its category-grouped cards, with
 *    the KPI count agreeing with the number of cards actually on screen;
 *  - the page is reachable through NAVIGATION (the `ReportingCompliance`
 *    top-level entry is a direct link to it — `reporting-compliance.json`'s
 *    `_menu_note`), not only by typed URL;
 *  - the cluster's links are LIVE: a `reporting-open-*` card link navigates to
 *    that report's own page, and the `reporting-overview-generated-link`
 *    reaches `GeneratedReportsIndex`;
 *  - the category filter narrows the rendered groups (the surface's one piece
 *    of local behaviour).
 *
 * Generating a report is NOT driven here: `reporting-generate-*` opens
 * `GenerateReportDialog`, which POSTs `/api/reporting/generate` and writes a
 * persisted `GeneratedReport` plus a Files-backed artefact into the shared
 * administration on every run. That belongs to `reports-via-docudesk`'s own
 * backend suite, not to a landing-page reachability spec.
 *
 * ## Locator discipline
 *
 * Card and group locators are `data-testid`s carrying MANIFEST/catalogue ids
 * (`reporting-card-TrialBalance`), never labels. The catalogue contains a card
 * literally labelled "Scenarios" (`reportViews.js`'s `CashflowScenarios`), and
 * `getByRole('link', { name: 'Scenarios' })` has already matched
 * cashflow-13wk's own nav leaf in this repo and reported a false PASS.
 *
 * @e2e reporting-compliance-consolidation::overview-route-renders-report-catalogue
 * @e2e reporting-compliance-consolidation::overview-reachable-through-navigation
 * @e2e reporting-compliance-consolidation::overview-cluster-links-navigate
 * @e2e reporting-compliance-consolidation::overview-generated-reports-link-resolves
 * @e2e reporting-compliance-consolidation::overview-category-filter-narrows-groups
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const OVERVIEW_ROUTE = '/reporting-compliance'
const GENERATED_ROUTE = '/reporting-compliance/generated'

/**
 * A `kind: "view"` catalogue entry (`src/components/reporting/reportViews.js`)
 * whose target page is a plain manifest index — so clicking its card link is a
 * pure navigation, with no generation side effect.
 */
const SAMPLE_VIEW_CARD = {
	id: 'TrialBalance',
	/** `TrialBalance`'s manifest route. */
	route: '/financial-statements/trial-balance',
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
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
 * Deep-link to a manifest route and prove the SPA resolved it rather than
 * falling through to the `/:pathMatch(.*)*` catch-all redirect to Dashboard
 * (`src/main.js`) — the `budget-core-schema.spec.ts` `gotoRoute()` precedent.
 */
async function gotoRoute(page: Page, route: string): Promise<void> {
	const target = APP + route
	await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	expect(
		normalisePath(page.url()),
		`route ${route} must be declared by the manifest and resolve to itself`,
	).toBe(normalisePath(target))
}

/**
 * Wait for the catalogue fetch to settle, then fail LOUDLY (with the
 * component's own message) if it rendered the error branch — rather than
 * letting the caller's card assertion time out and blame the selector.
 */
async function awaitCatalogue(page: Page): Promise<void> {
	await expect(page.getByTestId('reporting-overview')).toBeVisible({
		timeout: 15_000,
	})
	// 40s, not 20s: `/api/reporting/types` builds a 96-entry catalogue and this
	// wait was MEASURED timing out at 20s on a loaded dev box (a run whose every
	// test took ~31s against ~18s on an idle one). The wait is on the
	// component's own loading branch disappearing, so a generous ceiling costs
	// nothing on a fast instance.
	//
	// ⚠️ A per-assertion timeout can only spend the TEST's remaining budget. The
	// root `playwright.config.ts` sets `timeout: 30_000`, so this 40s ceiling is
	// unreachable on a local run unless the test itself is given more — which is
	// why the describe block below raises it. Without that, a slow catalogue
	// surfaces as "Test timeout of 30000ms exceeded" and the assertion that was
	// merely waiting gets the blame.
	await expect(page.getByTestId('reporting-overview-loading')).toHaveCount(0, {
		timeout: 40_000,
	})

	const error = page.getByTestId('reporting-overview-error')
	if ((await error.count()) > 0) {
		throw new Error(
			`ReportingComplianceOverview rendered its error branch instead of the `
				+ `catalogue: "${await error.innerText()}" — /api/reporting/types failed.`,
		)
	}
}

test.describe('reporting-compliance-consolidation — the ReportingComplianceOverview landing page', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		// The root config's 30s per-test budget is not enough here and was
		// MEASURED failing: the navigation test loads the Dashboard, clicks
		// through, and then waits on a 96-entry catalogue fetch, which came to
		// >30s on a loaded box and reported as a bare "Test timeout". Raised for
		// the whole block (the `budget-scenarios.spec.ts` /
		// `setup-wizard-english.spec.ts` `test.setTimeout()` precedent) rather
		// than trimming the assertions the coverage is actually for.
		test.setTimeout(120_000)
	})

	/**
	 * @e2e reporting-compliance-consolidation::overview-route-renders-report-catalogue
	 *
	 * The route resolves, the custom page mounts, and it renders the
	 * category-grouped catalogue — not just its own shell. The KPI's
	 * "Available reports" count is cross-checked against the cards actually in
	 * the DOM, so a page that reports 96 reports while rendering none fails.
	 */
	test('the route resolves and the page renders its category-grouped report cards', async ({
		page,
	}) => {
		await gotoRoute(page, OVERVIEW_ROUTE)
		await awaitCatalogue(page)

		await expect(page.getByTestId('reporting-overview-title')).toContainText(
			'Reporting & Compliance',
		)
		await expect(page.getByTestId('reporting-overview-filters')).toBeVisible()

		const groups = page.locator('[data-testid^="reporting-group-"]')
		await expect(
			groups.first(),
			'at least one category group renders',
		).toBeVisible({ timeout: 15_000 })
		expect(
			await groups.count(),
			'the catalogue groups reports across several categories',
		).toBeGreaterThan(1)

		const cards = page.locator('[data-testid^="reporting-card-"]')
		const cardCount = await cards.count()
		expect(cardCount, 'report cards render').toBeGreaterThan(0)

		// `reporting-overview-kpi` is a CnStatsBlock over `reports.length`. If
		// the count and the rendered cards disagree, one of the two lies.
		const kpiText = await page.getByTestId('reporting-overview-kpi').innerText()
		const kpiCount = Number(
			(kpiText.match(/\d[\d.,]*/)?.[0] ?? '').replace(/[.,]/g, ''),
		)
		expect(
			kpiCount,
			`the "Available reports" KPI (${kpiText.replace(/\n/g, ' ')}) must match the ${cardCount} cards on screen`,
		).toBe(cardCount)
	})

	/**
	 * @e2e reporting-compliance-consolidation::overview-reachable-through-navigation
	 *
	 * `reporting-compliance.json`'s `menu` fragment sets the top-level
	 * `ReportingCompliance` group's `route` to this page, making the cluster
	 * entry a DIRECT link (the `_menu_note` on that fragment). Prove a user can
	 * get here by clicking, not only by typing the URL.
	 *
	 * The entry is addressed by its manifest id, not by the label "Reporting &
	 * Compliance": `menu-layout.json` relocates nineteen leaves INTO this
	 * cluster, so a label-shaped locator can match a descendant rather than the
	 * cluster entry itself.
	 */
	test('the landing page is reachable by clicking the Reporting & Compliance cluster entry', async ({
		page,
	}) => {
		await page.goto(`${APP}/`, {
			waitUntil: 'domcontentloaded',
			timeout: 25_000,
		})
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await dismissOverlays(page)

		const entry = page.getByTestId('cn-nav-entry-ReportingCompliance')
		await expect(
			entry,
			'ReportingCompliance is a top-level nav entry',
		).toBeVisible({ timeout: 15_000 })

		// The entry's own link, not a relocated child's — scope the click to
		// the entry element and take its direct navigation anchor.
		await entry.locator('a.app-navigation-entry-link').first().click()

		await expect(page).toHaveURL(
			new RegExp(`${OVERVIEW_ROUTE.replace(/\//g, '\\/')}$`),
			{ timeout: 15_000 },
		)
		await awaitCatalogue(page)
		await expect(
			page.locator('[data-testid^="reporting-card-"]').first(),
		).toBeVisible({ timeout: 15_000 })
	})

	/**
	 * @e2e reporting-compliance-consolidation::overview-cluster-links-navigate
	 *
	 * The cluster's cards are `router-link`s to the report's own page. A card
	 * that renders but does not navigate is a picture of a feature, so click
	 * one and assert the destination route resolves and mounts.
	 */
	test('a report card link navigates to that report’s own page', async ({
		page,
	}) => {
		await gotoRoute(page, OVERVIEW_ROUTE)
		await awaitCatalogue(page)

		const card = page.getByTestId(`reporting-card-${SAMPLE_VIEW_CARD.id}`)
		await expect(
			card,
			`the catalogue offers the ${SAMPLE_VIEW_CARD.id} view card`,
		).toBeVisible({ timeout: 15_000 })

		const openLink = page.getByTestId(`reporting-open-${SAMPLE_VIEW_CARD.id}`)
		await expect(openLink, 'the card carries an Open link').toBeVisible()
		await openLink.click()

		await expect(page).toHaveURL(
			new RegExp(`${SAMPLE_VIEW_CARD.route.replace(/\//g, '\\/')}$`),
			{ timeout: 15_000 },
		)
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e reporting-compliance-consolidation::overview-generated-reports-link-resolves
	 *
	 * The overview is the only entry point to `GeneratedReportsIndex` — the
	 * fragment's `_menu_note` records that the former "Generated reports" menu
	 * leaf was deleted because "the overview IS the reports page … and links to
	 * the generated-reports index itself". If this link breaks, that page
	 * becomes unreachable, with no menu leaf left to reach it by.
	 */
	test('the “View generated reports” link reaches GeneratedReportsIndex', async ({
		page,
	}) => {
		await gotoRoute(page, OVERVIEW_ROUTE)
		await awaitCatalogue(page)

		const link = page.getByTestId('reporting-overview-generated-link')
		await expect(link).toBeVisible({ timeout: 15_000 })
		await link.click()

		await expect(page).toHaveURL(
			new RegExp(`${GENERATED_ROUTE.replace(/\//g, '\\/')}$`),
			{ timeout: 15_000 },
		)
		await expect(page.getByTestId('generated-reports')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('generated-reports-table')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e reporting-compliance-consolidation::overview-category-filter-narrows-groups
	 *
	 * The category `<select>` is a plain native select (not `NcSelect`), so it
	 * is driven with `selectOption`. Choosing one category must leave exactly
	 * that group rendered — the filter is the page's one piece of local
	 * behaviour and the only thing that makes the catalogue navigable at 96
	 * cards.
	 */
	test('choosing a category leaves only that category’s group rendered', async ({
		page,
	}) => {
		await gotoRoute(page, OVERVIEW_ROUTE)
		await awaitCatalogue(page)

		const groups = page.locator('[data-testid^="reporting-group-"]')
		await expect(groups.first()).toBeVisible({ timeout: 15_000 })
		const groupsBefore = await groups.count()
		expect(groupsBefore, 'more than one group before filtering').toBeGreaterThan(
			1,
		)

		// Take the category from the select's own options rather than hardcoding
		// one: `categoryOptions` is derived from the loaded catalogue, so a
		// literal could name a category this instance does not offer.
		const select = page.getByTestId('reporting-category-filter')
		await expect(select).toBeVisible()
		const value = await select.locator('option').nth(1).getAttribute('value')
		expect(
			value,
			'the category filter offers at least one category',
		).toBeTruthy()

		await select.selectOption(value as string)

		await expect(
			page.getByTestId(`reporting-group-${value}`),
			`the "${value}" group survives its own filter`,
		).toBeVisible({ timeout: 10_000 })
		await expect(
			groups,
			'every other category group is filtered out',
		).toHaveCount(1, { timeout: 10_000 })
	})
})
