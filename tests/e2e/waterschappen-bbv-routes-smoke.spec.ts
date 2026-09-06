/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Waterschappen BBV — route smoke tests (slice 11 testing).
 *
 * Verifies the three JSON envelope routes registered by slice 04 of the
 * bookkeeping-waterschappen-bbv-variant chain answer 200 OK with the
 * declared response envelope shape, AND that the three manifest SPA page
 * routes of the same names are still served by the app shell.
 *
 * Smoke tests use Playwright's APIRequestContext directly because the
 * concern is route reachability + response shape, not UI rendering.
 * The UI shell coverage lives in `waterschappen-bbv-variant.spec.ts`.
 *
 * ⚠️ WHY THE ASSERTIONS ARE UNCONDITIONAL
 * ---------------------------------------
 * This file used to assert `expect([200, 302, 401, 412].includes(status))`
 * and hide every envelope assertion behind `if (res.status() === 200)`.
 * That combination cannot fail for any defect this suite is supposed to
 * catch: the run this replaced was GREEN on all four tests while
 * `/apps/shillinq/bbv-dashboard` was serving Nextcloud's
 * "Access forbidden — CSRF check failed" page to every browser, because the
 * slice-04 API routes had been registered ON the SPA page paths and shadowed
 * them. globalSetup writes an authenticated storage state before any spec
 * runs, so 302/401 is not a legitimate outcome here — it is exactly the
 * regression these tests exist to catch. The status is therefore asserted as
 * 200 and the envelope is asserted every time.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-11-testing/tasks.md#smoke-tests
 */

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

/** JSON envelope endpoints — see the `/api/` guard comment in appinfo/routes.php. */
const DASHBOARD_API = APP + '/api/bbv-dashboard'
const MAPPING_INDEX_API = APP + '/api/budget-mappings'
const MAPPING_NEW_API = APP + '/api/budget-mappings/new'
const MAPPING_DETAIL_API = APP + '/api/budget-mappings/smoke-id'

/** SPA page routes declared by the slice-04 manifest fragment. */
const DASHBOARD_PAGE = APP + '/bbv-dashboard'
const MAPPING_INDEX_PAGE = APP + '/budget-mappings'
const MAPPING_DETAIL_PAGE = APP + '/budget-mappings/smoke-id'

const JSON_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }

test.describe('BBV routes — 200 OK + envelope shape', () => {
	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-001/dashboard-route-200
	 */
	test('GET /api/bbv-dashboard responds 200 with the widget envelope', async ({
		request,
	}) => {
		const res = await request.get(DASHBOARD_API, { headers: JSON_HEADERS })
		expect(res.status(), await res.text()).toBe(200)

		const body = await res.json()
		// Slice 08 envelope shape.
		expect(body).toHaveProperty('widgets')
		expect(body).toHaveProperty('programmes')
		expect(body).toHaveProperty('mappings')
		expect(body).toHaveProperty('counts')
		expect(body).toHaveProperty('summary')
		expect(body).toHaveProperty('generatedAt')
		expect(Array.isArray(body.widgets)).toBeTruthy()
		expect(Array.isArray(body.programmes)).toBeTruthy()
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-index-route-200
	 */
	test('GET /api/budget-mappings responds 200 with the index envelope', async ({
		request,
	}) => {
		const res = await request.get(MAPPING_INDEX_API, { headers: JSON_HEADERS })
		expect(res.status(), await res.text()).toBe(200)

		const body = await res.json()
		expect(body).toHaveProperty('register')
		expect(body).toHaveProperty('schema')
		expect(body).toHaveProperty('detailRoute')
		expect(body.schema).toBe('BudgetBBVMapping')
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-new-route-200
	 */
	test('GET /api/budget-mappings/new responds 200 (new = synthetic id)', async ({
		request,
	}) => {
		// The route is `/api/budget-mappings/{id}` — "new" is treated as a
		// synthetic id by the controller (no record lookup; the detail
		// page itself handles the new-vs-edit branching).
		const res = await request.get(MAPPING_NEW_API, { headers: JSON_HEADERS })
		expect(res.status(), await res.text()).toBe(200)

		const body = await res.json()
		expect(body).toHaveProperty('id')
		expect(body.id).toBe('new')
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-route-200
	 */
	test('GET /api/budget-mappings/:id responds 200 with the detail envelope', async ({
		request,
	}) => {
		const res = await request.get(MAPPING_DETAIL_API, { headers: JSON_HEADERS })
		expect(res.status(), await res.text()).toBe(200)

		const body = await res.json()
		expect(body).toHaveProperty('id')
		expect(body).toHaveProperty('register')
		expect(body).toHaveProperty('schema')
		expect(body).toHaveProperty('indexRoute')
		expect(body.id).toBe('smoke-id')
	})
})

test.describe('BBV page routes are served by the SPA shell, not an app route', () => {
	/**
	 * Regression guard for the slice-04 route collision: the three manifest
	 * page routes must reach the Shillinq app shell (an HTML document that
	 * boots the SPA), NOT a JSONResponse controller. A controller registered
	 * on a page path wins over the SPA catch-all, and because such a
	 * controller carries no `#[NoCSRFRequired]`, a plain browser navigation
	 * (no `requesttoken` header, unlike axios) is rejected by
	 * SecurityMiddleware with 403 "CSRF check failed" — which is what users
	 * got for the entire feature. Asserting the content type is HTML and the
	 * body carries the app-shell mount point catches both halves: a JSON
	 * envelope fails the content-type check, a CSRF rejection fails the
	 * status check.
	 *
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-001/page-routes-serve-spa
	 */
	for (const [label, url] of [
		['dashboard', DASHBOARD_PAGE],
		['mapping index', MAPPING_INDEX_PAGE],
		['mapping detail', MAPPING_DETAIL_PAGE],
	] as const) {
		test(`GET ${url} serves the SPA shell (${label})`, async ({ request }) => {
			const res = await request.get(url, { headers: { Accept: 'text/html' } })
			expect(res.status(), await res.text()).toBe(200)
			expect(res.headers()['content-type'] ?? '').toContain('text/html')

			const html = await res.text()
			// `templates/index.php` is the only thing that emits BOTH the SPA
			// mount point and the app bundle. A JSON envelope, a CSRF error
			// page and a login redirect all have neither.
			expect(html).toContain('id="shillinq-app"')
			expect(html).toContain('shillinq-main')
		})
	}
})
