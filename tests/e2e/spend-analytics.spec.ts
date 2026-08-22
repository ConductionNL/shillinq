/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — the SpendAnalytics dashboard page, the
 * first frontend consumer of `GET /apps/shillinq/api/analytics/spend`.
 *
 * ## The gap this closes
 *
 * The endpoint shipped fully implemented, routed and unit-tested with ZERO
 * consumers: at the base of this branch `grep -rn "analytics/spend" src/`
 * returned nothing and no `src/manifest.d/*.json` declared it. That absence
 * is what `glline-administration-scope`'s own spec cited when it excluded its
 * two UI scenarios — "`/api/analytics/spend` has NO frontend consumer". This
 * page is that consumer.
 *
 * ## What is actually asserted, and why it is not the happy path
 *
 * `glline-administration-scope` REQ-GLS-003 makes the three GL-backed views
 * RAISE while the `GLLine.administrationId` backfill is unproven, which
 * `SpendAnalyticsController::spend()` turns into
 * `HTTP 500 { "error": "Failed to compute spend analysis" }`. The raise exists
 * because filtering on a property some rows still lack matches nothing for
 * those rows — a silent zero in a bookkeeping total, a wrong number that looks
 * like a real one. A UI that renders that 500 as an empty chart or a `€0,00`
 * tile re-arms exactly that bug from the other side, which is why the
 * gate-shut test below asserts the ABSENCE of a total and of a table, not just
 * the presence of a message.
 *
 * ## Why the endpoint is stubbed
 *
 * Whether this instance's backfill gate is open is instance state, not a
 * property of this page. Driving both states through `page.route` makes each
 * assertion deterministic and, more importantly, makes the gate-shut case
 * reachable at all on an instance where the gate happens to be open. The
 * navigation test below uses no stub, so the page is still proven to mount
 * against the real deployment.
 *
 * ## Locator discipline
 *
 * Every locator is a `data-testid` carrying the manifest/dimension id
 * (`spend-analytics-error-costCentre`), never a label. Labels are unsafe here
 * twice over: this instance renders Dutch (`Menu openen`, `Verwijderen`), so
 * an `{ name: /english/i }` matcher matches nothing; and a bare label locator
 * has already reported a false PASS in this repo by matching another
 * feature's element (`reportViews.js`'s "Scenarios" card). The one non-testid
 * locator is the nav collapse toggle, addressed by its structural class
 * `button.icon-collapse` for the same reason.
 *
 * @e2e spend-analytics::page-reachable-from-reporting-compliance
 * @e2e spend-analytics::four-views-render-from-the-endpoint
 * @e2e spend-analytics::a-shut-gate-renders-as-unavailable-not-as-zero
 * @e2e spend-analytics::an-empty-result-is-not-an-unavailable-one
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const SPEND_ROUTE = '/reporting-compliance/spend-analytics'

/** The three views sourced from `GLLine`, i.e. the ones REQ-GLS-003 gates. */
const GL_BACKED = ['category', 'costCentre', 'period'] as const

/** The controller's own message for any `\Throwable` out of the service. */
const GATE_SHUT_MESSAGE = 'Failed to compute spend analysis'

/**
 * One successful envelope, in the shape `SpendAnalyticsService::shape()`
 * produces: `{ dimension, label, groups:[{key,amount}], total, backend }`.
 *
 * @param dimension The dimension key.
 * @return The response body.
 */
function okPayload(dimension: string) {
	return {
		dimension,
		label: `Spend by ${dimension}`,
		groups: [
			{ key: `${dimension}-A`, amount: 1200.5 },
			{ key: `${dimension}-B`, amount: 300.25 },
		],
		total: 1500.75,
		backend: 'postgres',
	}
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if ((await wizard.count()) > 0) {
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
 * Pin the administration context so the panel always has an administration to
 * report on. Without this the panel's `none` branch is reachable on an
 * instance whose seeded user holds no membership, and every figure assertion
 * would fail for a reason that has nothing to do with this page.
 *
 * @param page The page.
 */
async function stubAdministrationContext(page: Page): Promise<void> {
	await page.route('**/api/administrations/context*', (route: Route) =>
		route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				administrations: [
					{ administrationId: 'ADM-E2E', name: 'E2E Administratie' },
				],
				activeAdministrationId: 'ADM-E2E',
			}),
		}),
	)
}

/**
 * Answer `/api/analytics/spend` per dimension. `failing` names the dimensions
 * that answer 500 with the controller's envelope; `empty` names the ones that
 * answer 200 with no groups; everything else answers 200 with rows.
 *
 * @param page The page.
 * @param options Which dimensions fail and which come back empty.
 */
async function stubSpendEndpoint(
	page: Page,
	options: { failing?: readonly string[]; empty?: readonly string[] } = {},
): Promise<void> {
	const failing = new Set(options.failing ?? [])
	const empty = new Set(options.empty ?? [])
	await page.route('**/api/analytics/spend*', (route: Route) => {
		const dimension =
			new URL(route.request().url()).searchParams.get('dimension') ?? ''
		if (failing.has(dimension)) {
			return route.fulfill({
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify({ error: GATE_SHUT_MESSAGE }),
			})
		}
		if (empty.has(dimension)) {
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					dimension,
					label: `Spend by ${dimension}`,
					groups: [],
					total: 0,
					backend: 'postgres',
				}),
			})
		}
		return route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify(okPayload(dimension)),
		})
	})
}

/**
 * Deep-link to the page and prove the SPA resolved the route rather than
 * falling through to `src/main.js`'s `/:pathMatch(.*)*` redirect to Dashboard
 * (the `budget-core-schema.spec.ts` `gotoRoute()` precedent).
 *
 * @param page The page.
 */
async function gotoSpendPage(page: Page): Promise<void> {
	const target = APP + SPEND_ROUTE
	await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await page.waitForSelector('#content-vue', { timeout: 15_000 })
	await dismissOverlays(page)
	expect(
		normalisePath(page.url()),
		`route ${SPEND_ROUTE} must be declared by the manifest and resolve to itself`,
	).toBe(normalisePath(target))
	await expect(page.getByTestId('spend-analytics-panel')).toBeVisible({
		timeout: 15_000,
	})
}

test.describe('spend-analytics-ui — the SpendAnalytics dashboard page', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
		// The root playwright.config.ts sets `timeout: 30_000` per test, so any
		// per-assertion ceiling above that is unreachable. The navigation test
		// loads the Dashboard, expands a 66-child cluster and then waits on
		// four parallel fetches, which has been measured past 30s on a loaded
		// box and reports as a bare "Test timeout" that blames whichever
		// assertion happened to be waiting.
		test.setTimeout(120_000)
	})

	/**
	 * @e2e spend-analytics::page-reachable-from-reporting-compliance
	 *
	 * shillinq is at ADR-097's six-cluster ceiling, so this page is a LEAF
	 * under `ReportingCompliance` rather than a seventh top-level entry. A
	 * page nobody can click is not shipped, so prove the click path — and
	 * prove it without a stub, so the real deployment is what answers.
	 *
	 * The cluster and the leaf are addressed by their manifest ids.
	 * `menu-layout.json` relocates sixty-odd leaves INTO this cluster, so a
	 * label-shaped locator can match a sibling instead.
	 */
	test('the page is reachable by clicking the Spend analysis leaf under Reporting & Compliance', async ({
		page,
	}) => {
		await page.goto(`${APP}/`, {
			waitUntil: 'domcontentloaded',
			timeout: 25_000,
		})
		await page.waitForSelector('#content-vue', { timeout: 15_000 })
		await dismissOverlays(page)

		const cluster = page.getByTestId('cn-nav-entry-ReportingCompliance')
		await expect(
			cluster,
			'ReportingCompliance is a top-level nav entry',
		).toBeVisible({ timeout: 15_000 })

		// EXPAND ON THE CLUSTER'S OWN STATE, NOT ON THE LEAF'S PRESENCE.
		//
		// The first version of this gated the toggle on
		// `(await leaf.count()) === 0`, and CI proved that wrong: the leaf IS
		// in the DOM while the cluster is collapsed, so `count()` returned 1,
		// the expand was skipped, and the assertion below timed out against an
		// element it had already resolved —
		//
		//     28 × locator resolved to <li … data-cn-route="SpendAnalytics">
		//        - unexpected value "hidden"
		//
		// "present" and "visible" are different questions, and only the second
		// one is what a user can click.
		//
		// `aria-expanded` is the deterministic signal. Clicking unconditionally
		// would COLLAPSE an already-open cluster and produce the identical
		// failure, so the state has to be read before acting.
		//
		// IT IS ON THE LINK, NOT THE BUTTON. Measured against the live DOM:
		// `[data-testid="cn-nav-entry-ReportingCompliance"] [aria-expanded]`
		// resolves to exactly one element, `a.app-navigation-entry-link`. The
		// collapse BUTTON carries no aria-expanded at all, so reading it there
		// returns null, `null !== 'true'` is always true, and the toggle would
		// be clicked every time — collapsing an already-open cluster and
		// reproducing this very failure on the next run.
		//
		// The click still goes to the button (the link navigates rather than
		// expanding), and the button is addressed by its structural class
		// because this instance renders Dutch — an English accessible name
		// matches nothing here.
		const leaf = page.getByTestId('cn-nav-entry-SpendAnalytics')
		const expandedFlag = cluster.locator('a.app-navigation-entry-link').first()
		if ((await expandedFlag.getAttribute('aria-expanded')) !== 'true') {
			await cluster.locator('button.icon-collapse').first().click()
		}

		await expect(
			leaf,
			'the Spend analysis leaf is nested under the Reporting & Compliance cluster',
		).toBeVisible({ timeout: 15_000 })
		await leaf.locator('a.app-navigation-entry-link').first().click()

		// A predicate, not `new RegExp(SPEND_ROUTE.replace(/\//g, '\\/'))`. That
		// hand-rolled escaping only handled forward slashes, so every other
		// regex metacharacter in the route (`.`, `?`, `+`, and a backslash
		// itself) would have been interpreted rather than matched — CodeQL
		// flagged it as js/incomplete-sanitization. Asking the URL directly
		// whether its path ends with the route needs no escaping at all and
		// says what the assertion actually means.
		await expect(page).toHaveURL((url) => url.pathname.endsWith(SPEND_ROUTE), {
			timeout: 15_000,
		})
		await expect(page.getByTestId('spend-analytics-panel')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * @e2e spend-analytics::four-views-render-from-the-endpoint
	 *
	 * All four dimensions render, each with the endpoint's OWN `total` rather
	 * than a client-side sum. A page that renders four headings and no data is
	 * the failure shape here, so the assertions are on the rows and the total.
	 */
	test('all four dimensions render their groups and the endpoint’s own total', async ({
		page,
	}) => {
		await stubAdministrationContext(page)
		await stubSpendEndpoint(page)
		await gotoSpendPage(page)

		await expect(
			page.getByTestId('spend-analytics-administration'),
		).toContainText('E2E Administratie')

		for (const dimension of ['supplier', ...GL_BACKED]) {
			const view = page.getByTestId(`spend-analytics-view-${dimension}`)
			await expect(view, `the ${dimension} view renders`).toBeVisible({
				timeout: 15_000,
			})
			await expect(
				page.getByTestId(`spend-analytics-table-${dimension}`),
				`the ${dimension} view renders its groups`,
			).toBeVisible({ timeout: 15_000 })
			await expect(
				page
					.getByTestId(`spend-analytics-table-${dimension}`)
					.locator('tbody tr'),
			).toHaveCount(2)
			// 1500,75 in nl-NL currency formatting. Asserting the NUMBER, not
			// merely that a total element exists: an element containing "—"
			// would satisfy a presence-only check.
			await expect(
				page.getByTestId(`spend-analytics-total-${dimension}`),
				`the ${dimension} total is the endpoint's`,
			).toContainText('1.500,75')
			await expect(
				page.getByTestId(`spend-analytics-error-${dimension}`),
				`a view that answered must not also claim to be unavailable`,
			).toHaveCount(0)
		}
	})

	/**
	 * @e2e spend-analytics::a-shut-gate-renders-as-unavailable-not-as-zero
	 *
	 * THE LOAD-BEARING TEST. With the completeness gate shut, the three
	 * GL-backed views answer HTTP 500 — deliberately, per REQ-GLS-003, so that
	 * a filter over a half-backfilled ledger cannot report a silent zero.
	 *
	 * The page must say so and show NO figure. The absence assertions are the
	 * point: a message rendered above a `€0,00` total would pass a
	 * presence-only test while committing the exact error the gate prevents.
	 * `supplier` reads `APTransaction`, is not gated, and must still show its
	 * own numbers — a page that blanks everything because one view failed is
	 * its own kind of dishonest.
	 */
	test('a shut completeness gate renders as unavailable, with no total and no table', async ({
		page,
	}) => {
		await stubAdministrationContext(page)
		await stubSpendEndpoint(page, { failing: GL_BACKED })
		await gotoSpendPage(page)

		for (const dimension of GL_BACKED) {
			const error = page.getByTestId(`spend-analytics-error-${dimension}`)
			await expect(
				error,
				`the ${dimension} view states that it is unavailable`,
			).toBeVisible({ timeout: 15_000 })
			await expect(
				page.getByTestId(`spend-analytics-error-detail-${dimension}`),
				`the ${dimension} view carries the server's own message`,
			).toContainText(GATE_SHUT_MESSAGE)

			await expect(
				page.getByTestId(`spend-analytics-total-${dimension}`),
				`the ${dimension} view must render NO total — a 0 here is the silent zero REQ-GLS-003 exists to prevent`,
			).toHaveCount(0)
			await expect(
				page.getByTestId(`spend-analytics-table-${dimension}`),
				`the ${dimension} view must render no rows`,
			).toHaveCount(0)
			await expect(
				page.getByTestId(`spend-analytics-empty-${dimension}`),
				`"unavailable" must not be reported as "no rows"`,
			).toHaveCount(0)
		}

		// The ungated view is unaffected.
		await expect(
			page.getByTestId('spend-analytics-total-supplier'),
		).toContainText('1.500,75')
		await expect(page.getByTestId('spend-analytics-error-supplier')).toHaveCount(
			0,
		)
	})

	/**
	 * @e2e spend-analytics::an-empty-result-is-not-an-unavailable-one
	 *
	 * The mirror of the test above. A 200 with `groups: []` IS a measurement —
	 * the aggregation ran and matched nothing — and must read differently from
	 * a request that never produced a number. Collapsing the two states in
	 * either direction destroys the distinction the gate is built on.
	 */
	test('a measured empty result reads as "no rows", not as unavailable', async ({
		page,
	}) => {
		await stubAdministrationContext(page)
		await stubSpendEndpoint(page, { empty: ['supplier'] })
		await gotoSpendPage(page)

		await expect(
			page.getByTestId('spend-analytics-empty-supplier'),
			'an empty aggregation says it ran and matched nothing',
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByTestId('spend-analytics-error-supplier'),
			'an empty result is not an error',
		).toHaveCount(0)
		await expect(
			page.getByTestId('spend-analytics-total-supplier'),
			'an empty result renders no total either — the figure belongs to the rows block',
		).toHaveCount(0)

		// The other three still answered, so the empty state is scoped to the
		// view it belongs to rather than to the page.
		await expect(
			page.getByTestId('spend-analytics-total-category'),
		).toContainText('1.500,75')
	})
})
