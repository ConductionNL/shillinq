/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Provincies BBV — SPA-SHELL INTEGRITY smoke tests.
 *
 * ⚠️ WHAT THESE TESTS COVER — AND WHAT THEY DO NOT
 * ------------------------------------------------
 * These are HTTP probes against the app shell. They assert that each URL is
 * served by `templates/index.php` (the Shillinq SPA shell) as a 200 HTML
 * document — i.e. that no PHP controller has been registered on a manifest
 * PAGE path and shadowed it, and that the authenticated session is intact.
 * They say NOTHING about whether the page renders, what it renders, or
 * whether the requirement behind it works: the manifest pages are fully
 * declarative (ADR-037) and are painted entirely in the browser.
 *
 * The `@e2e` tags below used to read `…/REQ-BBC-001/dashboard-route-200`,
 * `…/REQ-BBL-001/linker-index-route-200`, `…/REQ-BBL-003/linker-detail-route-200`
 * and `…/REQ-BBC-004/admin-settings-route-200`, as though these probes covered
 * the compliance-dashboard and budget-linker REQUIREMENTS. They never did —
 * and with the old assertions they covered nothing at all (see below). They
 * are retagged to name what they actually verify, shell integrity; the
 * requirement-level coverage for REQ-BBC-001 / REQ-BBL-001 / REQ-BBL-003 /
 * REQ-BBC-004 lives in `tests/e2e/provincies-bbv-variant.spec.ts`, the browser
 * spec that drives these pages through the UI.
 *
 * ⚠️ WHY THE OLD ASSERTIONS WERE CONSTANTS
 * ----------------------------------------
 * Each test asserted `expect(res.status()).toBeLessThan(500)` and
 * `expect([200, 302, 401, 412].includes(res.status())).toBeTruthy()`.
 * `appinfo/routes.php` delegates to
 * `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all
 * (`'/{path}'`, `requirements: ['path' => '.+']`) resolves EVERY path under
 * `/apps/shillinq/` to `DashboardController::page()` →
 * `new TemplateResponse($appName, 'index')`, unconditionally. Those routes
 * therefore answer 200 by construction; the allow-list simply named the other
 * outcomes so that none of them could fail either.
 *
 * A real bug of exactly this shape was found in the waterschappen variant: the
 * slice-04 API routes had been registered ON the SPA page paths, so
 * `/apps/shillinq/bbv-dashboard` served Nextcloud's "Access forbidden — CSRF
 * check failed" page (403) to every browser while a smoke of this shape stayed
 * green. `tests/e2e/waterschappen-bbv-routes-smoke.spec.ts` documents it.
 *
 * `tests/e2e/global-setup.ts` writes an authenticated storage state before any
 * spec runs and `playwright.config.ts` sets it on `use.storageState`, which
 * Playwright's `request` fixture inherits — so 302 and 401 are NOT legitimate
 * outcomes here, they are the regression these tests exist to catch. The
 * status is asserted as 200, the content type as HTML, and the body as
 * carrying the SPA mount point + bundle that only `templates/index.php`
 * emits. A JSON envelope fails the content-type check; a CSRF rejection or a
 * login redirect fails the status check.
 *
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#smoke-tests
 */

import { test, expect, type APIRequestContext } from '@playwright/test'

const APP = '/apps/shillinq'
const DASHBOARD_ROUTE = APP + '/bbv-provincie/compliance-dashboard'
const LINKER_INDEX_ROUTE = APP + '/bbv-provincie/budget-to-programme'
const LINKER_DETAIL_ROUTE = APP + '/bbv-provincie/budget-to-programme/smoke-id'
const ADMIN_ROUTE = APP + '/admin'

/**
 * Assert a URL is served by the Shillinq SPA shell.
 *
 * `templates/index.php` is the only thing that emits BOTH the SPA mount point
 * (`<div id="shillinq-app">`) and the app bundle (`shillinq-main`). A JSON
 * envelope, a CSRF error page and a login redirect all have neither, so the
 * three assertions together catch route shadowing by a JSON controller,
 * CSRF rejection, and session loss.
 *
 * @param {import('@playwright/test').APIRequestContext} request The authenticated request fixture.
 * @param {string} url The shell URL to probe.
 * @return {Promise<void>}
 */
async function expectSpaShell(request: APIRequestContext, url: string): Promise<void> {
	const res = await request.get(url, { headers: { Accept: 'text/html' } })

	// The body is the assertion message so a failure names the page actually
	// served (CSRF error page, login form, JSON envelope), not just a number.
	expect(res.status(), await res.text()).toBe(200)
	expect(res.headers()['content-type'] ?? '').toContain('text/html')

	const html = await res.text()
	expect(html).toContain('id="shillinq-app"')
	expect(html).toContain('shillinq-main')
}

test.describe('Provincies BBV routes — served by the SPA shell, not shadowed by a JSON controller', () => {

	/**
	 * Shell integrity only — NOT requirement coverage. REQ-BBC-001's dashboard
	 * behaviour is covered by `tests/e2e/provincies-bbv-variant.spec.ts`.
	 *
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-001/dashboard-route-serves-spa-shell
	 */
	test('GET compliance-dashboard is served by the SPA shell', async ({ request }) => {
		await expectSpaShell(request, DASHBOARD_ROUTE)
	})

	/**
	 * Shell integrity only — NOT requirement coverage. REQ-BBL-001's linker
	 * index behaviour is covered by `tests/e2e/provincies-bbv-variant.spec.ts`.
	 *
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-001/linker-index-route-serves-spa-shell
	 */
	test('GET budget-to-programme is served by the SPA shell', async ({ request }) => {
		await expectSpaShell(request, LINKER_INDEX_ROUTE)
	})

	/**
	 * Shell integrity only — NOT requirement coverage. REQ-BBL-003's linker
	 * detail behaviour is covered by `tests/e2e/provincies-bbv-variant.spec.ts`.
	 *
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBL-003/linker-detail-route-serves-spa-shell
	 */
	test('GET budget-to-programme/:id is served by the SPA shell', async ({ request }) => {
		await expectSpaShell(request, LINKER_DETAIL_ROUTE)
	})

	/**
	 * Shell integrity only — NOT requirement coverage. REQ-BBC-004's admin
	 * settings behaviour is covered by `tests/e2e/provincies-bbv-variant.spec.ts`.
	 *
	 * @e2e bookkeeping-provincies-bbv-variant/REQ-BBC-004/admin-settings-route-serves-spa-shell
	 */
	test('GET /admin (admin settings) is served by the SPA shell', async ({ request }) => {
		await expectSpaShell(request, ADMIN_ROUTE)
	})

})
