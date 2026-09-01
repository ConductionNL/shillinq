/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bookings Resource Calendar — Playwright UI coverage (tasks 10 + 11).
 *
 * Drives the calendar page through the UI: switches between month/week/day
 * views, asserts that seed bookings render, that pending bookings are flagged
 * as conflicts, that clicking a slot opens the booking form, that the form
 * rejects sub-15-min durations and that the conflict dialog appears on 409.
 * The unit tests (Vitest/PHPUnit) own the API + business-logic side; Playwright
 * stays UI-only per the fleet rule.
 *
 * ── Why this file was rewritten ──────────────────────────────────────────────
 * It used to drive `/verkoop/boekingen-kalender` and the `bk-*` test ids of
 * `src/components/CalendarView.vue` + `src/components/BookingForm.vue`. Both
 * the route and the host page that mounted that pair were deleted by
 * `shillinq-manifest-boot-payload-reduction` (REQ-MBP-002); the two components
 * were left in the tree with no importer at all. Because the E2E job had never
 * executed, nothing noticed: the deep link fell through main.js's
 * `/:pathMatch(.*)*` catch-all onto the Dashboard and all 11 tests here died on
 * a 60s `waitFor`. The live surface is the manifest page `BookingsCalendarView`
 * at `/bookings/calendar/:calendarId`
 * (src/manifest.d/10-bookings-resource-calendar.json).
 *
 * The fixtures are created by tests/e2e/ci-seed.sh: calendar `e2e-cal-001`,
 * bookings `bk-001`…`bk-010` anchored on TODAY's UTC midnight. `bk-002`
 * (+01h…+08h) and `bk-003` (+06h…+12h) overlap between +06h and +08h and are
 * both `pending` — that is the seeded conflict this file relies on, and it is
 * why the conflict assertions below compute their window from today rather
 * than hard-coding a date (the old file hard-coded 2026-05-21, which stopped
 * overlapping anything the moment the seed became date-relative).
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-10
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-11
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'

/** Seeded by tests/e2e/ci-seed.sh. */
const CALENDAR_ID = 'e2e-cal-001'
const CALENDAR_ROUTE = `/bookings/calendar/${CALENDAR_ID}`

/**
 * `datetime-local` wire value (local wall time) for `hours` past today's UTC
 * midnight — the same anchor ci-seed.sh uses, so the windows line up with the
 * seeded bookings whatever day the suite runs on.
 */
function slotValue(hours: number): string {
	const d = new Date()
	d.setUTCHours(0, 0, 0, 0)
	d.setUTCHours(hours)
	const pad = (n: number) => String(n).padStart(2, '0')
	return (
		`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
		+ `T${pad(d.getHours())}:${pad(d.getMinutes())}`
	)
}

/**
 * Click a day-view slot so the host opens the booking form.
 *
 * Targets the slot's hour LABEL rather than the slot button itself: the seed
 * lays down a CONTIGUOUS cover of today (bk-001…bk-006 span -4h…+24h), so the
 * centre of any slot button may sit on a booking chip, whose `@click.stop`
 * handler emits `booking:selected` instead of `slot:clicked`. The hour label
 * has no stop handler, so the click bubbles to the slot button.
 */
async function clickSlot(page: Page): Promise<void> {
	await page
		.locator('[data-testid^="calendar-slot-"]')
		.first()
		.locator('.calendar-view__slot-hour')
		.click()
}

async function openCalendar(page: Page): Promise<void> {
	await page.goto(APP + CALENDAR_ROUTE)
	await page.waitForLoadState('domcontentloaded')

	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}

	// The grid is the page component for this route. Assert the URL too: a
	// route that is not declared by the manifest silently redirects to the
	// dashboard via main.js's '/:pathMatch(.*)*' catch-all, and every later
	// selector then times out blaming the widget instead of the routing.
	await expect(page.locator('[data-testid="calendar-view"]')).toBeVisible({
		timeout: 20_000,
	})
	await expect(page).toHaveURL(new RegExp(`${CALENDAR_ROUTE}$`))
}

test.describe('bookings calendar — month/week/day views render', () => {
	test.beforeEach(async ({ page }) => {
		await openCalendar(page)
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/month-view-mounts
	 */
	test('month view renders with seed bookings', async ({ page }) => {
		await page.locator('[data-testid="calendar-view-month"]').click()
		await expect(
			page.locator('[data-testid="calendar-month-grid"]'),
		).toBeVisible()
		const bookings = page.locator('[data-testid^="booking-bk-"]')
		await expect(bookings.first()).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/week-view-mounts
	 */
	test('week view renders 7-column hourly grid', async ({ page }) => {
		await page.locator('[data-testid="calendar-view-week"]').click()
		await expect(
			page.locator('[data-testid="calendar-week-grid"]'),
		).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/day-view-mounts
	 */
	test('day view renders 24-hour grid', async ({ page }) => {
		await page.locator('[data-testid="calendar-view-day"]').click()
		await expect(page.locator('[data-testid="calendar-day-grid"]')).toBeVisible()
	})

	/**
	 * Pending bookings carry the conflict modifier class. bk-002 and bk-003 are
	 * the seeded pending pair.
	 *
	 * @e2e bookings-resource-calendar/REQ-006/conflict-highlight
	 */
	test('pending bookings are highlighted as conflicts', async ({ page }) => {
		await page.locator('[data-testid="calendar-view-month"]').click()
		const conflict = page
			.locator('[data-testid^="booking-bk-"].calendar-view__booking--conflict')
			.first()
		await expect(conflict).toBeVisible({ timeout: 10_000 })
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/booking-selected-event
	 */
	test('clicking a booking is handled by the host without a page error', async ({
		page,
	}) => {
		const pageErrors: string[] = []
		page.on('pageerror', (e) => pageErrors.push(String(e)))

		await page.locator('[data-testid="calendar-view-month"]').click()
		const first = page.locator('[data-testid^="booking-bk-"]').first()
		await expect(first).toBeVisible({ timeout: 10_000 })
		await first.click()

		// The grid emits booking:selected; the host handles it. The old version
		// of this test ended in `expect(true).toBe(true)` and could not fail —
		// assert the absence of an uncaught page error instead.
		expect(
			pageErrors,
			`uncaught page errors: ${pageErrors.join(' | ')}`,
		).toHaveLength(0)
		await expect(page.locator('[data-testid="calendar-view"]')).toBeVisible()
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-006/slot-clicked-event-opens-form
	 */
	test('clicking a slot opens the booking form', async ({ page }) => {
		await page.locator('[data-testid="calendar-view-day"]').click()
		await clickSlot(page)
		await expect(page.locator('[data-testid="booking-form-panel"]')).toBeVisible(
			{ timeout: 5_000 },
		)
	})
})

test.describe('booking form — REQ-007 validation + happy/conflict paths', () => {
	test.beforeEach(async ({ page }) => {
		await openCalendar(page)
		await page.locator('[data-testid="calendar-view-day"]').click()
		await clickSlot(page)
		await expect(page.locator('[data-testid="booking-form"]')).toBeVisible({
			timeout: 5_000,
		})
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/form-fields-present
	 */
	test('form renders title, start, end, attendee and status fields', async ({
		page,
	}) => {
		await expect(
			page.locator('[data-testid="booking-form-title"]'),
		).toBeVisible()
		await expect(
			page.locator('[data-testid="booking-form-start"]'),
		).toBeVisible()
		await expect(page.locator('[data-testid="booking-form-end"]')).toBeVisible()
		await expect(
			page.locator('[data-testid="booking-form-attendee"]'),
		).toBeVisible()
		await expect(
			page.locator('[data-testid="booking-form-status-pending"]'),
		).toBeVisible()
		await expect(
			page.locator('[data-testid="booking-form-status-confirmed"]'),
		).toBeVisible()
	})

	/**
	 * The host pre-fills a whole-hour slot, i.e. already valid — narrow it to
	 * 10 minutes so validate() has something to reject.
	 *
	 * @e2e bookings-resource-calendar/REQ-007/short-duration-rejected
	 */
	test('client-side validation rejects sub-15-minute duration', async ({
		page,
	}) => {
		await page
			.locator('[data-testid="booking-form-title"]')
			.fill('UI test: too short')
		await page.locator('[data-testid="booking-form-attendee"]').fill('UI Tester')
		await page
			.locator('[data-testid="booking-form-start"]')
			.fill('2027-08-01T10:00')
		await page
			.locator('[data-testid="booking-form-end"]')
			.fill('2027-08-01T10:10')
		await page.locator('[data-testid="booking-form-submit"]').click()
		await expect(
			page.locator('[data-testid="booking-form-error"]'),
		).toBeVisible()
		await expect(
			page.locator('[data-testid="booking-form-error"]'),
		).toContainText(/15 minutes/i)
	})

	/**
	 * @e2e bookings-resource-calendar/REQ-007/cancel-closes-form
	 */
	test('cancel button closes the form', async ({ page }) => {
		await page.locator('[data-testid="booking-form-cancel"]').click()
		await expect(page.locator('[data-testid="booking-form-panel"]')).toBeHidden({
			timeout: 5_000,
		})
	})

	/**
	 * Submits a window inside the seeded bk-002 / bk-003 overlap (+06h…+08h from
	 * today's UTC midnight) so the API must answer 409 and the dialog mounts.
	 *
	 * @e2e bookings-resource-calendar/REQ-007/conflict-modal-opens-on-409
	 */
	test('conflict modal opens when API returns 409', async ({ page }) => {
		await page
			.locator('[data-testid="booking-form-title"]')
			.fill('UI test: known conflict')
		await page
			.locator('[data-testid="booking-form-attendee"]')
			.fill('UI Conflict')
		await page.locator('[data-testid="booking-form-start"]').fill(slotValue(6))
		await page.locator('[data-testid="booking-form-end"]').fill(slotValue(7))
		await page.locator('[data-testid="booking-form-status-confirmed"]').check()

		// Assert the STATUS CODE, not just the dialog. "dialog not found" is the
		// same observation whether conflict detection failed to fire (201) or
		// fired and the modal failed to mount (409) — and those are different
		// defects in different layers. Naming the response makes the failure say
		// which one.
		const createPost = page.waitForResponse(
			(response) =>
				/\/api\/v2\/calendars\/[^/]+\/bookings/.test(response.url())
				&& response.request().method() === 'POST',
		)
		await page.locator('[data-testid="booking-form-submit"]').click()
		const createResponse = await createPost
		expect(
			createResponse.status(),
			`a booking inside the seeded bk-002/bk-003 overlap must be refused 409, got ${createResponse.status()}`,
		).toBe(409)

		await expect(page.locator('[data-testid="bk-conflict-dialog"]')).toBeVisible(
			{ timeout: 10_000 },
		)
		await page.locator('[data-testid="bk-conflict-cancel"]').click()
		await expect(page.locator('[data-testid="bk-conflict-dialog"]')).toBeHidden({
			timeout: 5_000,
		})
	})

	/**
	 * Far-future window: outside every seeded booking, so the API returns 201
	 * and the host closes the panel.
	 *
	 * @e2e bookings-resource-calendar/REQ-007/successful-create-closes-form
	 */
	test('successful create closes the form', async ({ page }) => {
		await page
			.locator('[data-testid="booking-form-title"]')
			.fill('UI test: clean slot')
		await page.locator('[data-testid="booking-form-attendee"]').fill('UI Happy')
		await page
			.locator('[data-testid="booking-form-start"]')
			.fill('2028-09-15T09:00')
		await page
			.locator('[data-testid="booking-form-end"]')
			.fill('2028-09-15T09:30')
		await page.locator('[data-testid="booking-form-status-pending"]').check()
		await page.locator('[data-testid="booking-form-submit"]').click()
		await expect(page.locator('[data-testid="booking-form-panel"]')).toBeHidden({
			timeout: 15_000,
		})
	})
})
