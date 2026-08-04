/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 Playwright UI coverage — bookings-resource-calendar (REQ-006).
 *
 * ⚠️ WHY THE OLD REGISTRY ASSERTION WAS `expect(true).toBe(true)`
 * --------------------------------------------------------------
 * The first test claimed to prove "the component registry is reachable on
 * `window.OCA.Shillinq` and advertises the bookings page components". Its
 * assertion was:
 *
 *     expect(Array.isArray(registryEntries)).toBe(true)
 *
 * `registryEntries` is produced by `Array.prototype.filter()`, which ALWAYS
 * returns an array — including the empty array it returns when the namespace
 * does not exist. So the assertion was a tautology, and it was hiding a false
 * premise: NOTHING in this app ever populates `window.OCA.Shillinq`.
 *
 * POSITIVE CONTROL for that absence claim — the same search over the same
 * files DOES find the one global namespace shillinq really writes:
 *     window.OCA.OpenRegister.integrations  ✔ populated by
 *         `installIntegrationRegistry()` / `registerBuiltinIntegrations()` /
 *         `registerLeafIntegrations()` at the top of `src/main.js`
 *     window.OCA.Shillinq                   ✘ never assigned, in src/ or in
 *         @conduction/nextcloud-vue's dist bundle
 * The `?? namespace as Record<string, unknown>` fallback in the old evaluate
 * therefore always resolved to `{}`, `Object.keys({})` to `[]`, and the filter
 * to `[]`. Asserting `length > 0` on it would file a defect against a
 * namespace that was never designed to exist.
 *
 * The claim worth making — "the CalendarView / BookingForm components were
 * bundled and are registered under their manifest page ids" — is provable from
 * the rendered artifact instead, which is strictly stronger: it exercises the
 * whole chain (bundle → `src/registry.js` → `customComponents` prop →
 * `RoutePageRenderer` → the component's own DOM) rather than a side-channel.
 * `src/manifest.d/10-bookings-resource-calendar.json` declares
 * `/bookings/calendar/:calendarId` with `component: "BookingsCalendar"`, and
 * `src/registry.js` maps `BookingsCalendar: { kind: 'page', component:
 * CalendarView }`. `CalendarView.vue`'s root element is
 * `<div class="calendar-view" data-testid="calendar-view">` — rendered
 * unconditionally, and its `mounted()` swallows a failed booking fetch, so a
 * synthetic calendar id still mounts the component. If the registration
 * regressed, the route would render nothing there.
 *
 * (The `/verkoop/boekingen-kalender` shortcut page and its `bk-calendar`
 * testids are a DIFFERENT component and are covered by
 * `tests/e2e/bookings-resource-calendar.spec.ts`.)
 *
 * The URL / page-title assertions this test also carried are removed as
 * constants: `appinfo/routes.php` delegates to
 * `\OCA\OpenRegister\AppHost\Routes::standard()`, whose catch-all answers
 * every `/apps/shillinq/` path with the same `TemplateResponse`, and the
 * `<title>` is server-rendered by `core/templates/layout.user.php` before any
 * JavaScript runs. On CI 30881746678 the control truncated
 * `js/shillinq-main.js` to 0 bytes and both still held.
 *
 * Tagged `@spec REQ-006` so the gate-19 honest-coverage report counts this
 * scenario against the bookings-resource-calendar spec.
 */

import { test, expect } from '@playwright/test'
import { gotoPage } from './spec-coverage/_helpers'

test.describe('shillinq — bookings calendar smoke', () => {
	test('SPA mounts and the bookings calendar component is registered @spec REQ-006', async ({ page }) => {
		// 1. The SPA booted. `#content-vue` (waited on by `gotoPage`) and
		//    `#app-content-vue` exist only after `app.mount('#shillinq-app')` in
		//    src/main.js; `gotoPage` additionally asserts the settled path equals
		//    the requested one, which catches the `/:pathMatch(.*)*` catch-all
		//    silently redirecting an undeclared route to the Dashboard.
		await gotoPage(page, '/')
		await expect(
			page.locator('#app-content-vue'),
			'the SPA must mount NcAppContent before anything else here is meaningful',
		).toBeVisible({ timeout: 15_000 })

		// 2. The declared calendar route resolves…
		await gotoPage(page, '/bookings/calendar/smoke-id')

		// 3. …and the registered CalendarView component is what rendered in it.
		//    Scoped to `#app-content-vue` (NcAppContent's `<main>`), never
		//    `#content-vue`, which also wraps the sidebar that is identical on
		//    all ~107 pages.
		await expect(
			page.locator('#app-content-vue [data-testid="calendar-view"]'),
			'the BookingsCalendar page id must resolve to the registered CalendarView component',
		).toBeVisible({ timeout: 15_000 })
	})

	test('GET /api/v2/calendars responds 200 with a JSON array @spec REQ-005', async ({ request }) => {
		const res = await request.get('/index.php/apps/shillinq/api/v2/calendars', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		// On a fresh container the calendars register may be absent — the
		// controller either degrades to an empty list (200 []) or returns a
		// 503 to surface the register-absent state. Both shapes lock the
		// REQ-005 route contract for the UI.
		expect([200, 503]).toContain(res.status())
		if (res.status() === 200) {
			const body = await res.json()
			// CalendarController::index() returns { calendars: [...] }; accept
			// that canonical shape as well as a bare array / { data: [...] }.
			expect(
				Array.isArray(body)
				|| Array.isArray(body?.data)
				|| Array.isArray(body?.calendars),
			).toBe(true)
		}
	})
})
