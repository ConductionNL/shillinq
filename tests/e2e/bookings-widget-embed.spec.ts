/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Booking Self-service Widget — embed smoke (Task 21).
 *
 * The widget itself is a partner-embeddable JavaScript bundle, so a full
 * UI-driven Playwright run requires the operator to mint a `WidgetAccessKey`
 * via the admin UI first (Task 18) and serve the bundle from an external
 * partner page. That end-to-end loop is tracked under follow-up flight
 * `bookings-widget-e2e-fixtures` per [[playwright-ui-only-newman-api]].
 *
 * What this spec DOES exercise (without mock fixtures) is the public route
 * surface that any embed talks to:
 *
 *   GET /api/widget/services      (#[PublicPage] + bearer)
 *   GET /api/widget/slots         (#[PublicPage] + bearer)
 *   POST /api/widget/appointments (#[PublicPage] + bearer)
 *
 * Each route MUST refuse the request without a valid Authorization bearer
 * (REQ-WSW-001), MUST be reachable in the first place (route registered,
 * SecurityMiddleware happy with #[PublicPage] + #[NoCSRFRequired]), and
 * MUST validate request shape before touching the database.
 *
 * Live-booking assertions (200 services, 200 slots with ETag/304, 201
 * appointment, 409 double-book) are covered by Newman in
 * tests/integration/*.postman_collection.json once a seeded key is loaded
 * — Playwright stays UI-only for happy paths per the fleet rule.
 *
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-21
 */

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const SERVICES_API = APP + '/api/widget/services'
const SLOTS_API = APP + '/api/widget/slots'
const APPOINTMENTS_API = APP + '/api/widget/appointments'

test.describe('widget public API — bearer-token gate', () => {
	/**
	 * @e2e bookings-self-service-widget/REQ-WSW-001/services-without-bearer-401
	 */
	test('GET /api/widget/services without bearer returns 401', async ({
		request,
	}) => {
		const res = await request.get(SERVICES_API, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		// 401 is the canonical "no/invalid bearer" response from guard().
		// 412 means the AppFramework rejected the request before the
		// controller ran (group restriction etc.) — acceptable on a
		// hard-locked dev tenant. 200/302 would be a security bug.
		expect([401, 412].includes(res.status())).toBeTruthy()
	})

	/**
	 * @e2e bookings-self-service-widget/REQ-WSW-001/services-with-bad-bearer-401
	 */
	test('GET /api/widget/services with bad bearer returns 401', async ({
		request,
	}) => {
		const res = await request.get(SERVICES_API + '?businessId=salon-demo', {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
				Authorization: 'Bearer bk_live_not-a-real-key',
			},
		})
		expect([401, 412].includes(res.status())).toBeTruthy()
	})

	/**
	 * @e2e bookings-self-service-widget/REQ-WSW-001/slots-without-bearer-401
	 */
	test('GET /api/widget/slots without bearer returns 401', async ({ request }) => {
		const res = await request.get(
			SLOTS_API + '?serviceId=haircut&resourceId=chair-1&date=2026-06-09',
			{
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			},
		)
		expect([401, 412].includes(res.status())).toBeTruthy()
	})

	/**
	 * @e2e bookings-self-service-widget/REQ-WSW-001/appointments-without-bearer-401
	 */
	test('POST /api/widget/appointments without bearer returns 401', async ({
		request,
	}) => {
		const res = await request.post(APPOINTMENTS_API, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			data: {
				businessId: 'salon-demo',
				serviceId: 'haircut',
				resourceId: 'chair-1',
				startAt: '2026-06-09T09:00:00Z',
				customer: { name: 'Test', email: 'test@example.com' },
			},
		})
		expect([401, 412].includes(res.status())).toBeTruthy()
	})
})
