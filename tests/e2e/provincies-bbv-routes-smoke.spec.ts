/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Provincies BBV — route smoke tests.
 *
 * Verifies the manifest pages declared by the
 * `bookkeeping-provincies-bbv-variant` change are served by the Shillinq
 * SPA shell. Those pages are fully declarative (ADR-037) so they have no
 * PHP routes of their own; their URLs must fall through to the app shell
 * rather than to a controller, a login redirect or an error page.
 *
 *  - GET /apps/shillinq/bbv-provincie/compliance-dashboard
 *  - GET /apps/shillinq/bbv-provincie/budget-to-programme
 *  - GET /apps/shillinq/bbv-provincie/budget-to-programme/{id}
 *  - GET /apps/shillinq/admin
 *
 * ⚠️ WHY THE ASSERTIONS CHANGED
 * -----------------------------
 * Every test here used to assert
 *
 *     expect(res.status()).toBeLessThan(500)
 *     expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()
 *
 * which accepts a login redirect, an expired session and a rejected
 * visibility predicate as success. `global-setup.ts` writes an
 * authenticated storage state before any spec runs, so 302/401 is not a
 * legitimate outcome here — it is the regression these tests exist to
 * catch. The status is now asserted as exactly 200, and the body is
 * checked, so the test can distinguish "reached the app" from "reached
 * something that was not 5xx".
 *
 * The old docblock also claimed these tests verified that "the manifest
 * renderer hands the page envelope to `CnDashboardPage` / `CnIndexPage` /
 * `CnDetailPage` correctly". They never did: an APIRequestContext does not
 * execute JavaScript, so no renderer runs in this file at all. The claim
 * is removed rather than left to mislead the next reader.
 *
 * ⚠️ AND WHAT THIS FILE STILL CANNOT TELL YOU
 * -------------------------------------------
 * These four tests pass with `js/shillinq-main.js` truncated to 0 bytes —
 * measured, negative-control run 30881358951. That is CORRECT for a
 * route-reachability smoke test: the app shell is emitted server-side by
 * `templates/index.php`, so reaching it proves ROUTING, not RENDERING.
 * What would be wrong is to read a pass here as evidence a page mounted.
 * Mount/render coverage lives in `provincies-bbv-variant.spec.ts`, which
 * drives a real browser.
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-26
 */

import { test, expect, type APIRequestContext } from '@playwright/test'

const APP = '/apps/shillinq'
const DASHBOARD_ROUTE = APP + '/bbv-provincie/compliance-dashboard'
const LINKER_INDEX_ROUTE = APP + '/bbv-provincie/budget-to-programme'
const LINKER_DETAIL_ROUTE = APP + '/bbv-provincie/budget-to-programme/smoke-id'
const ADMIN_ROUTE = APP + '/admin'

const HTML_HEADERS = { 'OCS-APIREQUEST': 'true', Accept: 'text/html' }

/**
 * Assert a URL is served by the Shillinq SPA shell.
 *
 * `templates/index.php` is the only thing that emits BOTH the mount point
 * `#shillinq-app` (mounted by `src/main.js`) and the `shillinq-main`
 * bundle reference. A JSON envelope from a controller registered on a page
 * path, a CSRF error page, and a login redirect all have neither — so this
 * one assertion separates the app shell from every way of failing to reach
 * it.
 *
 * @param request The Playwright API request context.
 * @param url     App-relative URL to probe.
 *
 * @return Resolves when every assertion has passed.
 */
async function expectSpaShell(
	request: APIRequestContext,
	url: string,
): Promise<void> {
	const res = await request.get(url, { headers: HTML_HEADERS })
	expect(res.status(), `${url}: ${await res.text()}`).toBe(200)
	expect(res.headers()['content-type'] ?? '', `${url}: content-type`).toContain(
		'text/html',
	)

	const html = await res.text()
	expect(html, `${url}: app shell mount point`).toContain('id="shillinq-app"')
	expect(html, `${url}: app bundle reference`).toContain('shillinq-main')
}

test.describe('Provincies BBV routes — served by the SPA shell', () => {
	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-001/dashboard-route-200
	 */
	test('GET compliance-dashboard is served by the SPA shell', async ({
		request,
	}) => {
		await expectSpaShell(request, DASHBOARD_ROUTE)
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-001/linker-index-route-200
	 */
	test('GET budget-to-programme is served by the SPA shell', async ({
		request,
	}) => {
		await expectSpaShell(request, LINKER_INDEX_ROUTE)
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-003/linker-detail-route-200
	 */
	test('GET budget-to-programme/:id is served by the SPA shell', async ({
		request,
	}) => {
		await expectSpaShell(request, LINKER_DETAIL_ROUTE)
	})

	/**
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-004/admin-settings-route-200
	 */
	test('GET /admin (admin settings) is served by the SPA shell', async ({
		request,
	}) => {
		await expectSpaShell(request, ADMIN_ROUTE)
	})
})
