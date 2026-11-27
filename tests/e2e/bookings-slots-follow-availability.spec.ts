/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the offered slots follow the availability rules, breaks and
 * booking constraints (bookings-slots-follow-availability task 2.1, REQ-BAR-011).
 *
 * The live pass of the change, driven through GET /api/widget/slots, the
 * route the booking widget reads its slots from, with a widget key minted for
 * the run through POST /api/widget/admin/keys/create:
 *
 *   1. stylist Sanne has an active rule Monday to Friday 09:00-17:00 and an
 *      active rule Wednesday 09:00-12:30 (her Wednesday afternoon off); for a
 *      45 minute haircut on a Wednesday the last slot starts at 11:45 and no
 *      afternoon slot is offered;
 *   2. with a daily lunch break 12:00-12:30, a 30 minute service on the
 *      Thursday after offers 09:00 to 16:30 except 11:45, 12:00 and 12:15, and
 *      the Wednesday offers neither the afternoon nor the lunch break;
 *   3. with a booking constraint of two hours minimum notice, no slot offered
 *      today starts within two hours from now.
 *
 * The scenarios name Wednesday 2 and Thursday 3 December 2026; the test takes
 * the first Wednesday at least two days ahead and the Thursday after it, so it
 * keeps finding slots in the future after those dates have passed. Slots are
 * computed in UTC (SlotService), so the times are compared in UTC.
 *
 * The scenario's exact clock (the widget opened at 10:10 on Friday 4 December,
 * first slot 12:30 or later) needs a server clock the test cannot set; it is a
 * test.fixme below, and step 3 checks the same rule against the real clock.
 *
 * The administration defaults to the seed's `ADM-001`; `E2E_ADMINISTRATION_ID`
 * names another. Every object written here, the widget key included, is
 * removed afterwards (the key is revoked first).
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/bookings-slots-follow-availability/specs/bookings-availability-rules/spec.md
 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md
 *
 * @e2e bookings-availability-rules::a-staff-member-works-mornings-only-on-wednesday
 * @e2e bookings-availability-rules::a-lunch-break-is-not-offered
 * @e2e bookings-availability-rules::the-minimum-notice-keeps-todays-next-hour-free
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP_API = '/index.php/apps/shillinq/api'
const ADMINISTRATION_ID = process.env.E2E_ADMINISTRATION_ID ?? 'ADM-001'
const BUSINESS_ID = `${UNIQUE_PREFIX}-salon`
const RESOURCE_ID = `${UNIQUE_PREFIX}-sanne`
const HAIRCUT_ID = `${UNIQUE_PREFIX}-haircut-45`
const SHORT_ID = `${UNIQUE_PREFIX}-trim-30`
const RULE_WEEK = `${UNIQUE_PREFIX}-rule-week`
const RULE_WEDNESDAY = `${UNIQUE_PREFIX}-rule-wed`

/** One offered slot, as the widget's slot route answers it. */
interface Slot {
	startTime: string
	endTime: string
}

/**
 * A date as YYYY-MM-DD in UTC.
 *
 * @param date The date.
 * @return The ISO day.
 */
function isoDay(date: Date): string {
	return date.toISOString().slice(0, 10)
}

/**
 * The first Wednesday at least two days after today (UTC).
 *
 * @return The Wednesday as a Date at midnight UTC.
 */
function nextWednesday(): Date {
	const day = new Date(`${isoDay(new Date())}T00:00:00Z`)
	day.setUTCDate(day.getUTCDate() + 2)
	while (day.getUTCDay() !== 3) {
		day.setUTCDate(day.getUTCDate() + 1)
	}
	return day
}

/**
 * The HH:MM start times of a list of slots.
 *
 * @param slots The slots.
 * @return The start times, in UTC.
 */
function starts(slots: Slot[]): string[] {
	return slots.map((slot) => slot.startTime.slice(11, 16))
}

test.describe('bookings-slots-follow-availability: slots follow rules, breaks and constraints', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let apiKey = ''
	let keyRecordId = ''

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * POST a JSON body to a shillinq route as the administrator.
	 *
	 * @param path The route below the app's API.
	 * @param data The body.
	 * @return The response.
	 */
	async function post(path: string, data: Record<string, unknown>) {
		return api.post(APP_API + path, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data,
		})
	}

	/**
	 * The slots the widget is offered for a service with Sanne on a date.
	 *
	 * @param serviceId The service.
	 * @param date      The date (YYYY-MM-DD).
	 * @return The slots, by start time.
	 */
	async function slots(serviceId: string, date: string): Promise<Slot[]> {
		const query = new URLSearchParams({ businessId: BUSINESS_ID, serviceId, resourceId: RESOURCE_ID, date })
		const res = await api.get(`${APP_API}/widget/slots?${query.toString()}`, {
			headers: { Authorization: `Bearer ${apiKey}`, Accept: 'application/json' },
		})
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		expect(body.date).toBe(date)
		return (body.slots ?? []) as Slot[]
	}

	/**
	 * A public, active service of the administration.
	 *
	 * @param serviceId The service's logical key.
	 * @param name      Its name.
	 * @param duration  Its duration in minutes.
	 * @return The service's object id.
	 */
	async function service(serviceId: string, name: string, duration: number): Promise<string> {
		const { id } = await fx.create('Service', {
			administrationId: ADMINISTRATION_ID,
			serviceId,
			code: serviceId.toLowerCase(),
			name,
			duration,
			prepareTime: 0,
			bufferBefore: 0,
			bufferAfter: 0,
			basePrice: 35,
			currency: 'EUR',
			dynamicPricing: false,
			serviceCategory: 'hair',
			status: 'active',
			isPublic: true,
			priceVisible: false,
			resourceId: RESOURCE_ID,
		})
		return id
	}

	/**
	 * An active availability rule of Sanne.
	 *
	 * @param ruleId     The rule's logical key.
	 * @param daysOfWeek The weekdays it covers.
	 * @param startTime  Its start (HH:MM).
	 * @param endTime    Its end (HH:MM).
	 * @return The rule's object id.
	 */
	async function rule(ruleId: string, daysOfWeek: string[], startTime: string, endTime: string): Promise<string> {
		const { id } = await fx.create('AvailabilityRule', {
			administrationId: ADMINISTRATION_ID,
			availabilityRuleId: ruleId,
			resource: RESOURCE_ID,
			status: 'active',
			effectiveFrom: '2026-01-01',
			description: `${UNIQUE_PREFIX} ${daysOfWeek.join(', ')} ${startTime}-${endTime}`,
			daysOfWeek,
			startTime,
			endTime,
		})
		return id
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		if (apiKey !== '') {
			await post('/widget/admin/keys/revoke', { businessId: BUSINESS_ID }).catch(() => undefined)
		}
		if (keyRecordId !== '') {
			await fx?.remove('WidgetAccessKey', keyRecordId)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a Wednesday afternoon off and a lunch break are not offered, and the minimum notice holds @spec REQ-BAR-011', async () => {
		const missing = await fx.missingSchema(['Service', 'Resource', 'AvailabilityRule', 'ResourceBreak', 'BookingConstraint', 'WidgetAccessKey'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const minted = await post('/widget/admin/keys/create', { businessId: BUSINESS_ID, administrationId: ADMINISTRATION_ID })
		test.skip(minted.status() === 403, 'the suite\'s user may not administer shillinq\'s widget keys')
		expect(minted.status(), await minted.text()).toBe(200)
		const key = await minted.json()
		apiKey = String(key.apiKey ?? '')
		const record = (key.record ?? {}) as Record<string, unknown>
		keyRecordId = String((record['@self'] as Record<string, unknown> | undefined)?.id ?? record.id ?? '')
		expect(apiKey, 'the key is returned once in plain text').not.toBe('')

		await fx.create('Resource', {
			administrationId: ADMINISTRATION_ID,
			resourceId: RESOURCE_ID,
			name: `Sanne ${UNIQUE_PREFIX}`,
			type: 'staff',
			openingTime: '08:00',
			closingTime: '20:00',
			allowOverlap: false,
			status: 'active',
			timezone: 'UTC',
		})
		await service(HAIRCUT_ID, `Haircut ${UNIQUE_PREFIX}`, 45)
		await service(SHORT_ID, `Trim ${UNIQUE_PREFIX}`, 30)
		await rule(RULE_WEEK, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], '09:00', '17:00')
		await rule(RULE_WEDNESDAY, ['wednesday'], '09:00', '12:30')

		const wednesdayDate = nextWednesday()
		const wednesday = isoDay(wednesdayDate)
		const thursdayDate = new Date(wednesdayDate)
		thursdayDate.setUTCDate(thursdayDate.getUTCDate() + 1)
		const thursday = isoDay(thursdayDate)

		// Scenario 1: the Wednesday rule replaces the weekly one; the last 45 minute slot starts at 11:45.
		const haircut = starts(await slots(HAIRCUT_ID, wednesday))
		expect(haircut[0], 'the first slot opens the morning').toBe('09:00')
		expect(haircut[haircut.length - 1], 'the last slot ends at 12:30').toBe('11:45')
		expect(haircut.filter((time) => time >= '12:30'), 'no afternoon slot').toEqual([])
		expect(haircut).toHaveLength(12)

		// Scenario 2: a daily lunch break 12:00-12:30, linked to the weekly rule.
		await fx.create('ResourceBreak', {
			administrationId: ADMINISTRATION_ID,
			resourceBreakId: `${UNIQUE_PREFIX}-lunch`,
			availabilityRule: RULE_WEEK,
			breakType: 'lunch',
			dayOfWeek: 'daily',
			startTime: '12:00',
			endTime: '12:30',
			isRecurring: true,
			status: 'active',
		})
		// Scenario 3's constraint: two hours minimum notice.
		await fx.create('BookingConstraint', {
			administrationId: ADMINISTRATION_ID,
			bookingConstraintId: `${UNIQUE_PREFIX}-notice`,
			availabilityRule: RULE_WEEK,
			minAdvanceNoticeHours: 2,
			minAdvanceNoticeDays: 0,
			status: 'active',
		})

		const trimThursday = starts(await slots(SHORT_ID, thursday))
		expect(trimThursday[0]).toBe('09:00')
		expect(trimThursday[trimThursday.length - 1]).toBe('16:30')
		expect(trimThursday).toContain('11:30')
		expect(trimThursday).toContain('12:30')
		for (const time of ['11:45', '12:00', '12:15']) {
			expect(trimThursday, `no slot overlaps the lunch break (${time})`).not.toContain(time)
		}
		expect(trimThursday).toHaveLength(28)

		// The task's live pass: on the Wednesday neither the afternoon nor the lunch break is offered.
		const trimWednesday = starts(await slots(SHORT_ID, wednesday))
		expect(trimWednesday[trimWednesday.length - 1]).toBe('11:30')
		expect(trimWednesday.filter((time) => time >= '11:45'), 'no lunch-break or afternoon slot').toEqual([])
		expect(trimWednesday).toHaveLength(11)

		// Scenario 3, against the real clock: nothing today starts within two hours.
		const earliest = Date.now() + 2 * 60 * 60 * 1000 - 60 * 1000
		const today = await slots(SHORT_ID, isoDay(new Date()))
		for (const slot of today) {
			expect(Date.parse(slot.startTime), `${slot.startTime} lies inside the two hours notice`).toBeGreaterThanOrEqual(earliest)
		}
	})

	// The scenario fixes the moment the widget is opened (10:10 on Friday 4
	// December 2026). SlotService reads the server clock (ITimeFactory), which a
	// Playwright run cannot set; the rule is checked against the real clock above.
	test.fixme('opened at 10:10 on Friday 4 December 2026, the first slot starts at 12:30 or later @spec REQ-BAR-011', async () => {})
})
