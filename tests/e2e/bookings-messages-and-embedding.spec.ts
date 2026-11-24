/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a booking from a page on another origin, and its
 * confirmation request (bookings-messages-and-embedding task 1.6,
 * REQ-WSW-011, REQ-BCF-013).
 *
 * The live check of the change, through the routes it built:
 *
 *   1. the suite's administrator seeds a public service and a resource under
 *      one administration, mints a widget key for a business of this run
 *      (`POST /api/widget/admin/keys/create`) and allows the partner origin
 *      `http://partner.e2e.test` on it (`POST /api/widget/admin/keys/origins`);
 *   2. the CORS preflight (`OPTIONS /api/widget/appointments`) answers 204
 *      with `Access-Control-Allow-Origin` for that origin, and 403 without
 *      the header for an origin the key does not name (REQ-WSW-011);
 *   3. a partner page on that origin (served by `page.route`, so no second
 *      server is needed) loads `widget.js` from the instance and mounts the
 *      widget with the key; the visitor picks the service, the first open
 *      slot in the next two weeks, fills in name and email and confirms. The
 *      browser makes the cross-origin calls itself, so the booking only goes
 *      through when the preflight and the answers carry the CORS headers;
 *      the widget then shows "Booking confirmed" and the booking answer is 201
 *      with the partner origin allowed (REQ-WSW-011);
 *   4. the appointment is stored pending confirmation, and the confirmation
 *      request is recorded on it: `confirmationRequestStatus` and
 *      `confirmationRequestAt` (REQ-BCF-013). With `E2E_MAIL_CONFIGURED=1`
 *      (an instance whose mail server accepts mail) the status must be
 *      `sent`; otherwise `failed` is accepted too and noted in the report;
 *   5. the mail arriving in the customer's mailbox has no surface in the
 *      suite (no mail catcher): test.fixme with that reason.
 *
 * The administration is `E2E_ADMINISTRATION_ID` when set, else the first one
 * the administration context lists. Every object written here is removed
 * afterwards (the appointment, the service, the resource) and the widget key
 * is revoked. The confirmation token the listener issues stays; it expires.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-self-service-widget/spec.md
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-confirm-flow/spec.md
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md
 *
 * @e2e bookings-self-service-widget::a-booking-from-the-customer-website
 * @e2e bookings-confirm-flow::a-self-booked-appointment-sends-the-request
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const ADMIN_KEYS = `${APP}/api/widget/admin/keys`
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const OCS = { 'OCS-APIRequest': 'true' }
const PARTNER_ORIGIN = 'http://partner.e2e.test'
const OTHER_ORIGIN = 'http://elsewhere.e2e.test'
const BUSINESS_ID = `${UNIQUE_PREFIX}-embed`
const SERVICE_ID = `${UNIQUE_PREFIX}-knippen`
const RESOURCE_ID = `${UNIQUE_PREFIX}-stoel`
const CUSTOMER_EMAIL = `${UNIQUE_PREFIX}@example.org`
const DAYS_AHEAD = 14

/** The appointment fields this spec reads back. */
interface StoredAppointment {
	id?: string
	'@self'?: { id?: string }
	appointmentId?: string
	status?: string
	source?: string
	customerEmail?: string
	confirmationRequestStatus?: string
	confirmationRequestAt?: string
}

test.describe('bookings-messages-and-embedding: a booking from another website', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let administrationId = ''
	let apiKey = ''
	let setupGap = ''
	let appointment: StoredAppointment | null = null

	/**
	 * The administration to book into: the env's, else the first the caller holds.
	 *
	 * @return The administration id, or '' when the caller holds none.
	 */
	async function chooseAdministration(): Promise<string> {
		const wanted = process.env.E2E_ADMINISTRATION_ID ?? ''
		if (wanted !== '') {
			return wanted
		}
		const res = await api.get(`${APP}/api/administrations/context`, { headers: OCS })
		if (res.status() !== 200) {
			return ''
		}
		const first = ((await res.json())?.administrations ?? [])[0] as Record<string, unknown> | undefined
		return String(first?.administrationId ?? first?.id ?? '')
	}

	/**
	 * The first date in the next two weeks with an open slot for the seeded service.
	 *
	 * @return The date (YYYY-MM-DD), or '' when there is none.
	 */
	async function firstOpenDate(): Promise<string> {
		for (let d = 1; d <= DAYS_AHEAD; d++) {
			const date = new Date(Date.now() + d * 86400000).toISOString().slice(0, 10)
			const slots = await api.get(
				`${APP}/api/widget/slots?businessId=${encodeURIComponent(BUSINESS_ID)}&serviceId=${encodeURIComponent(SERVICE_ID)}&resourceId=${encodeURIComponent(RESOURCE_ID)}&date=${date}`,
				{ headers: { Authorization: `Bearer ${apiKey}` } },
			)
			if (slots.status() === 200 && ((await slots.json())?.slots ?? []).length > 0) {
				return date
			}
		}
		return ''
	}

	/**
	 * The stored appointment with this widget appointment id.
	 *
	 * @param appointmentId The id the booking answer named.
	 * @return The appointment, or null.
	 */
	async function storedAppointment(appointmentId: string): Promise<StoredAppointment | null> {
		const res = await api.get(`${OBJECTS}/Appointment?appointmentId=${encodeURIComponent(appointmentId)}&_limit=5`, { headers: OCS })
		if (!res.ok()) {
			return null
		}
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as StoredAppointment[]
		return rows.find((row) => row.appointmentId === appointmentId) ?? null
	}

	/**
	 * Send the CORS preflight of a booking from an origin.
	 *
	 * @param origin The page's origin.
	 * @return The answer.
	 */
	async function preflight(origin: string) {
		return api.fetch(`${APP}/api/widget/appointments?businessId=${encodeURIComponent(BUSINESS_ID)}`, {
			method: 'OPTIONS',
			headers: {
				Origin: origin,
				'Access-Control-Request-Method': 'POST',
				'Access-Control-Request-Headers': 'authorization,content-type',
			},
		})
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)

		const missing = await fx.missingSchema(['Service', 'Resource', 'Appointment', 'WidgetAccessKey'])
		if (missing !== null) {
			setupGap = `the shillinq register lacks ${missing}`
			return
		}
		administrationId = await chooseAdministration()
		if (administrationId === '') {
			setupGap = 'the administrator holds no administration; set E2E_ADMINISTRATION_ID'
			return
		}

		await fx.create('Service', {
			administrationId,
			serviceId: SERVICE_ID,
			code: SERVICE_ID,
			name: `Knippen ${UNIQUE_PREFIX}`,
			duration: 30,
			prepareTime: 0,
			bufferBefore: 0,
			bufferAfter: 0,
			basePrice: 25,
			currency: 'EUR',
			dynamicPricing: false,
			serviceCategory: 'Hair Services',
			status: 'active',
			isPublic: true,
		})
		await fx.create('Resource', {
			administrationId,
			resourceId: RESOURCE_ID,
			name: `Stoel ${UNIQUE_PREFIX}`,
			type: 'furniture',
			status: 'active',
			openingTime: '09:00',
			closingTime: '17:00',
		})

		const created = await api.post(`${ADMIN_KEYS}/create`, { headers: OCS, data: { businessId: BUSINESS_ID, administrationId } })
		if (created.status() !== 200) {
			setupGap = `the widget key could not be minted: ${created.status()} ${await created.text()}`
			return
		}
		apiKey = String((await created.json())?.apiKey ?? '')
	})

	test.afterAll(async () => {
		const id = String(appointment?.id ?? appointment?.['@self']?.id ?? '')
		if (id !== '') {
			await fx?.remove('Appointment', id)
		}
		if (apiKey !== '') {
			await api?.post(`${ADMIN_KEYS}/revoke`, { headers: OCS, data: { businessId: BUSINESS_ID } }).catch(() => undefined)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('the key allows the partner origin and the preflight refuses any other @spec REQ-WSW-011', async () => {
		test.skip(setupGap !== '', setupGap)

		const origins = await api.post(`${ADMIN_KEYS}/origins`, { headers: OCS, data: { businessId: BUSINESS_ID, allowedOrigins: [PARTNER_ORIGIN] } })
		expect(origins.status(), await origins.text()).toBe(200)

		const allowed = await preflight(PARTNER_ORIGIN)
		expect(allowed.status()).toBe(204)
		expect(allowed.headers()['access-control-allow-origin']).toBe(PARTNER_ORIGIN)
		expect(allowed.headers()['access-control-allow-methods'] ?? '').toContain('POST')
		expect((allowed.headers()['access-control-allow-headers'] ?? '').toLowerCase()).toContain('authorization')

		const refused = await preflight(OTHER_ORIGIN)
		expect(refused.status()).toBe(403)
		expect(refused.headers()['access-control-allow-origin']).toBeUndefined()
	})

	test('a visitor on the partner site books, sees the confirmation, and the request is recorded @spec REQ-WSW-011 @spec REQ-BCF-013', async ({ page, baseURL }) => {
		test.skip(setupGap !== '', setupGap)
		const date = await firstOpenDate()
		test.skip(date === '', `the seeded resource has no open slot in the next ${DAYS_AHEAD} days`)

		const origins = await api.post(`${ADMIN_KEYS}/origins`, { headers: OCS, data: { businessId: BUSINESS_ID, allowedOrigins: [PARTNER_ORIGIN] } })
		expect(origins.status(), await origins.text()).toBe(200)

		const instance = new URL(baseURL ?? 'http://localhost:8080').origin
		await page.route(`${PARTNER_ORIGIN}/**`, (route) => route.fulfill({
			status: 200,
			contentType: 'text/html',
			body: `<!doctype html><html><head><meta charset="utf-8"><title>Kapsalon</title></head><body>
<div id="booking-widget"></div>
<script src="${instance}/apps/shillinq/js/widget.js"></script>
<script>
BookingWidget.init({
	businessId: ${JSON.stringify(BUSINESS_ID)},
	apiBase: ${JSON.stringify(instance + APP)},
	apiKey: ${JSON.stringify(apiKey)},
	resourceId: ${JSON.stringify(RESOURCE_ID)},
	containerId: 'booking-widget',
	lang: 'en',
})
</script></body></html>`,
		}))
		await page.goto(`${PARTNER_ORIGIN}/afspraak`)

		const widget = page.locator('#booking-widget')
		await widget.getByLabel('Select a service').selectOption(SERVICE_ID)
		await widget.getByRole('button', { name: 'Next' }).click()

		await widget.getByLabel('Select a date').fill(date)
		await widget.getByLabel('Select a date').dispatchEvent('change')
		await widget.getByRole('radio').first().click()
		await widget.getByRole('button', { name: 'Next' }).click()

		await widget.getByLabel('Your name').fill(`E2E Embed ${UNIQUE_PREFIX}`)
		await widget.getByLabel('Email address').fill(CUSTOMER_EMAIL)
		await widget.getByRole('button', { name: 'Next' }).click()

		const booked = page.waitForResponse((res) => res.url().includes('/api/widget/appointments') && res.request().method() === 'POST')
		await widget.getByRole('button', { name: 'Confirm booking' }).click()
		const answer = await booked
		expect(answer.status(), await answer.text()).toBe(201)
		expect(answer.headers()['access-control-allow-origin']).toBe(PARTNER_ORIGIN)
		const appointmentId = String((await answer.json())?.appointmentId ?? '')
		expect(appointmentId).not.toBe('')

		await expect(widget.getByRole('status')).toContainText('Booking confirmed')

		// The appointment is stored and waits for the customer's confirmation.
		await expect.poll(async () => {
			appointment = await storedAppointment(appointmentId)
			return appointment?.confirmationRequestStatus ?? ''
		}, {
			message: 'the confirmation request is recorded on the appointment',
			timeout: 15_000,
		}).not.toBe('')
		expect(appointment?.status).toBe('pending_confirmation')
		expect(appointment?.source).toBe('widget')
		expect(appointment?.customerEmail).toBe(CUSTOMER_EMAIL)
		expect(String(appointment?.confirmationRequestAt ?? '')).not.toBe('')

		const status = String(appointment?.confirmationRequestStatus)
		test.info().annotations.push({ type: 'confirmation request', description: `${appointmentId}: ${status} at ${String(appointment?.confirmationRequestAt)}` })
		if (process.env.E2E_MAIL_CONFIGURED === '1') {
			expect(status, 'the mail server accepted the confirmation request').toBe('sent')
		} else {
			expect(['sent', 'failed']).toContain(status)
		}
	})

	test('the confirmation mail arrives in the customer\'s mailbox @spec REQ-BCF-013', async () => {
		test.fixme(true, 'the suite has no mail catcher, and `sent` only records that the mail server accepted the request; the arrival is checked by hand in the live pass')
	})
})
