/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bookings Resource Calendar — Playwright UI coverage (tasks 10 + 11).
 *
 * Drives the calendar page through the UI: picks a calendar, switches
 * between month/week/day views, asserts that seed bookings render in the
 * right cells, that pending bookings are flagged as conflicts, that
 * clicking a slot opens the booking form, that the form rejects sub-15-min
 * durations and that the conflict dialog appears on 409. The unit tests
 * (Jest/PHPUnit) own the API + business-logic side; Playwright stays
 * UI-only per the fleet rule.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-10
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-11
 */

import { test, expect } from '@playwright/test'

const APP = '/apps/shillinq'

/**
 * The canonical bookings-calendar route, declared by the `BookingsCalendarView`
 * page in `src/manifest.d/10-bookings-resource-calendar.json`.
 *
 * This spec used to navigate to `/verkoop/boekingen-kalender`, a path declared
 * in no manifest fragment and in no route table. `src/main.js` ends its routes
 * with `{ path: '/:pathMatch(.*)*', redirect: '/' }`, so that URL never 404'd —
 * it silently redirected to the Financial dashboard and every selector below
 * timed out against the wrong page.
 *
 * The `:calendarId` parameter is deep-linked on purpose rather than reaching the
 * page param-less and picking from the dropdown: resolving by id goes through
 * `CalendarController::loadCalendar()`, which filters on `calendarId` alone,
 * whereas the picker's index is scoped to the caller's active administration.
 * Deep-linking keeps these assertions about the CALENDAR rather than about
 * administration membership.
 */
const CALENDAR_ID = 'e2e-cal-001'
const CALENDAR_ROUTE = `/bookings/calendar/${CALENDAR_ID}`

/**
 * Format a Date as the `YYYY-MM-DDTHH:mm` local wall-clock value a
 * `datetime-local` input accepts.
 *
 * @param date The date to format.
 */
function toLocalInput(date: Date): string {
	const pad = (n: number) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
		+ `T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

test.describe('bookings calendar — month/week/day views render', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto(APP + CALENDAR_ROUTE)
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}

		await page.locator('[data-testid="bk-calendar"]').waitFor({ state: 'visible' })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/month-view-mounts
	 */
	test('month view renders with seed bookings', async ({ page }) => {
		await page.locator('[data-testid="bk-calendar-view-month"]').click()
		await expect(page.locator('[data-testid="bk-calendar-month"]')).toBeVisible()
		// `ci-seed.sh` seeds bk-001..bk-010 across today and tomorrow (the grid
		// anchors on today and has no month navigation, so the fixtures are
		// generated relative to today rather than hardcoded). Asserting that at
		// least one is visible keeps the test robust to a month boundary falling
		// between the seeded days.
		const bookings = page.locator('[data-testid^="bk-booking-bk-"]')
		await expect(bookings.first()).toBeVisible({ timeout: 5_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/week-view-mounts
	 */
	test('week view renders 7-column hourly grid', async ({ page }) => {
		await page.locator('[data-testid="bk-calendar-view-week"]').click()
		await expect(page.locator('[data-testid="bk-calendar-week"]')).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/day-view-mounts
	 */
	test('day view renders 24-hour grid', async ({ page }) => {
		await page.locator('[data-testid="bk-calendar-view-day"]').click()
		await expect(page.locator('[data-testid="bk-calendar-day"]')).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/conflict-highlight
	 */
	test('pending bookings are highlighted as conflicts', async ({ page }) => {
		await page.locator('[data-testid="bk-calendar-view-month"]').click()
		const conflict = page.locator('[data-testid^="bk-booking-bk-"].is-conflict').first()
		await expect(conflict).toBeVisible({ timeout: 5_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/booking-selected-event
	 */
	test('clicking a booking emits booking:selected (host page handles it)', async ({ page }) => {
		// The host page acknowledges the event by logging it. Capturing that log
		// is what makes this a real assertion: this spec's whole subject is that
		// the grid's events reach a HOST, and for `slot:clicked` they did not —
		// the manifest mounted the grid bare with nothing listening. A test that
		// ended in `expect(true).toBe(true)` could not tell the wired case from
		// the unwired one.
		const logged: string[] = []
		page.on('console', (message) => logged.push(message.text()))

		await page.locator('[data-testid="bk-calendar-view-month"]').click()
		const first = page.locator('[data-testid^="bk-booking-bk-"]').first()
		await first.click()

		await expect.poll(
			() => logged.some((line) => line.includes('shillinq: booking selected')),
			{ timeout: 5_000 },
		).toBe(true)
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/slot-clicked-event-opens-form
	 */
	test('clicking an empty slot opens the booking form', async ({ page }) => {
		await page.locator('[data-testid="bk-calendar-view-day"]').click()
		const slot = page.locator('[data-testid^="bk-slot-"]').first()
		await slot.click()
		await expect(page.locator('[data-testid="bk-form-panel"]')).toBeVisible({ timeout: 3_000 })
	})

})

test.describe('booking form — REQ-007 validation + happy/conflict paths', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto(APP + CALENDAR_ROUTE)
		await page.waitForLoadState('domcontentloaded')

		const wizard = page.locator('#firstrunwizard')
		if (await wizard.isVisible().catch(() => false)) {
			await page.keyboard.press('Escape').catch(() => {})
			await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
		}

		await page.locator('[data-testid="bk-calendar"]').waitFor({ state: 'visible' })
		// Open the form via a slot click.
		await page.locator('[data-testid="bk-calendar-view-day"]').click()
		await page.locator('[data-testid^="bk-slot-"]').first().click()
		await page.locator('[data-testid="bk-form"]').waitFor({ state: 'visible' })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/form-fields-present
	 */
	test('form renders title, start, end, attendee and status fields', async ({ page }) => {
		await expect(page.locator('[data-testid="bk-form-title"]')).toBeVisible()
		await expect(page.locator('[data-testid="bk-form-start"]')).toBeVisible()
		await expect(page.locator('[data-testid="bk-form-end"]')).toBeVisible()
		await expect(page.locator('[data-testid="bk-form-attendee"]')).toBeVisible()
		await expect(page.locator('[data-testid="bk-form-status-pending"]')).toBeVisible()
		await expect(page.locator('[data-testid="bk-form-status-confirmed"]')).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/short-duration-rejected
	 */
	test('client-side validation rejects sub-15-minute duration', async ({ page }) => {
		await page.locator('[data-testid="bk-form-title"]').fill('UI test: too short')
		await page.locator('[data-testid="bk-form-attendee"]').fill('UI Tester')
		// Type a 10-minute window — the form's validate() must surface an error.
		await page.locator('[data-testid="bk-form-start"]').fill('2027-08-01T10:00')
		await page.locator('[data-testid="bk-form-end"]').fill('2027-08-01T10:10')
		await page.locator('[data-testid="bk-form-submit"]').click()
		await expect(page.locator('[data-testid="bk-form-error"]')).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/cancel-closes-form
	 */
	test('cancel button closes the form', async ({ page }) => {
		await page.locator('[data-testid="bk-form-cancel"]').click()
		await expect(page.locator('[data-testid="bk-form"]')).not.toBeVisible({ timeout: 3_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/conflict-modal-opens-on-409
	 * Submits a window the seed fixtures already occupy, to force the API into
	 * a 409 response — the BookingConflictDialog modal must mount.
	 *
	 * `ci-seed.sh` tiles the resource with bookings from `today-1 20:00Z` to
	 * `today+2 00:00Z` without a gap, so midday TODAY is booked in every UTC
	 * offset. That matters because these inputs are LOCAL wall-clock time and
	 * `playwright.config.ts` pins no `timezoneId`: a hardcoded UTC instant would
	 * make the 409 depend on the runner's zone.
	 */
	test('conflict modal opens when API returns 409', async ({ page }) => {
		const conflictStart = new Date()
		conflictStart.setHours(12, 0, 0, 0)
		const conflictEnd = new Date(conflictStart.getTime() + 30 * 60 * 1000)

		await page.locator('[data-testid="bk-form-title"]').fill('UI test: known conflict')
		await page.locator('[data-testid="bk-form-attendee"]').fill('UI Conflict')
		await page.locator('[data-testid="bk-form-start"]').fill(toLocalInput(conflictStart))
		await page.locator('[data-testid="bk-form-end"]').fill(toLocalInput(conflictEnd))
		await page.locator('[data-testid="bk-form-status-confirmed"]').check()
		await page.locator('[data-testid="bk-form-submit"]').click()
		await expect(page.locator('[data-testid="bk-conflict-dialog"]')).toBeVisible({ timeout: 5_000 })
		// Cancel returns the operator to the form without retrying.
		await page.locator('[data-testid="bk-conflict-cancel"]').click()
		await expect(page.locator('[data-testid="bk-conflict-dialog"]')).not.toBeVisible({ timeout: 3_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/successful-create-closes-form
	 * Submits a clean, non-conflicting window in the far future so the API
	 * returns 201 — the form must close on success.
	 *
	 * The window is ~400 days out (far beyond the seeded 2-day fixture block)
	 * and its minute-of-day is salted from the run's clock. A fixed literal
	 * would be occupied by the booking THIS test created last time, so on any
	 * instance that is not thrown away between runs the second run would get a
	 * 409 and this test would fail for a reason that has nothing to do with the
	 * behaviour it covers.
	 */
	test('successful create closes the form', async ({ page }) => {
		const salt = Date.now() % (23 * 60)
		const cleanStart = new Date()
		cleanStart.setDate(cleanStart.getDate() + 400)
		cleanStart.setHours(Math.floor(salt / 60), salt % 60, 0, 0)
		const cleanEnd = new Date(cleanStart.getTime() + 30 * 60 * 1000)

		await page.locator('[data-testid="bk-form-title"]').fill('UI test: clean slot')
		await page.locator('[data-testid="bk-form-attendee"]').fill('UI Happy')
		await page.locator('[data-testid="bk-form-start"]').fill(toLocalInput(cleanStart))
		await page.locator('[data-testid="bk-form-end"]').fill(toLocalInput(cleanEnd))
		await page.locator('[data-testid="bk-form-status-pending"]').check()
		await page.locator('[data-testid="bk-form-submit"]').click()
		// On 201, the host view closes the form panel.
		await expect(page.locator('[data-testid="bk-form-panel"]')).not.toBeVisible({ timeout: 7_000 })
	})

})
