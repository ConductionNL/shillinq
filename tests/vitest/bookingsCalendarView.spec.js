/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Offline unit tests for the two CalendarView defects that made the bookings
 * calendar render an empty grid on a healthy HTTP 200
 * (bookings-resource-calendar REQ-006).
 *
 * Both are single-accessor bugs, and both are invisible from the network tab:
 *
 *   1. `fetchBookings()` unwrapped `data.results`. CalendarController::bookings()
 *      answers `{"bookings": [...]}` (lib/Controller/CalendarController.php:281),
 *      so the list was ALWAYS empty while the request itself succeeded.
 *   2. `bookingId()` returned `booking.id` — the OpenRegister row UUID — so every
 *      chip rendered `data-testid="booking-<uuid>"` and emitted a UUID on
 *      `booking:selected`, unaddressable by anything that knows a booking by its
 *      domain id. `bookingId` is the identifier ci-seed.sh writes,
 *      ConflictDetectionService returns in `conflicts[]`, and
 *      BookingConflictDialog keys its rows off.
 *
 * Per this repo's vitest convention the SFC's `methods` are exercised against a
 * fake component instance — no DOM mount, so the environment stays `node`.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-5
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CalendarView from '../../src/views/bookings/CalendarView.vue'

const { fetchBookings, bookingId, isConflict } = CalendarView.methods

/** Minimal `this` for the methods under test. */
function instance() {
	return { calendarId: 'e2e-cal-001', bookings: [] }
}

/** Stub global fetch with one JSON body, mirroring a 200 response. */
function mockFetchJson(body) {
	globalThis.fetch = vi.fn().mockResolvedValue({
		ok: true,
		json: async () => body,
	})
}

describe('CalendarView.fetchBookings — response envelope', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'test-token' }
	})

	afterEach(() => {
		delete globalThis.fetch
		delete globalThis.OC
	})

	it('unwraps the `bookings` key the controller actually returns', async () => {
		const ctx = instance()
		mockFetchJson({ bookings: [{ bookingId: 'bk-001', title: 'A' }] })

		await fetchBookings.call(ctx)

		expect(ctx.bookings).toHaveLength(1)
		expect(ctx.bookings[0].bookingId).toBe('bk-001')
	})

	it('still accepts a bare array and the legacy `results` key', async () => {
		const a = instance()
		mockFetchJson([{ bookingId: 'bk-001' }])
		await fetchBookings.call(a)
		expect(a.bookings).toHaveLength(1)

		const b = instance()
		mockFetchJson({
			results: [{ bookingId: 'bk-002' }, { bookingId: 'bk-003' }],
		})
		await fetchBookings.call(b)
		expect(b.bookings).toHaveLength(2)
	})

	it('yields an empty list — not a throw — on an unrecognised envelope', async () => {
		const ctx = instance()
		mockFetchJson({ somethingElse: [{ bookingId: 'bk-001' }] })
		await fetchBookings.call(ctx)
		expect(ctx.bookings).toEqual([])
	})
})

describe('CalendarView.bookingId — identifier preference', () => {
	it('prefers the domain bookingId over the OpenRegister row uuid', () => {
		expect(bookingId({ bookingId: 'bk-001', id: '9f1c-uuid' })).toBe('bk-001')
	})

	it('falls back to id, then @self, then empty', () => {
		expect(bookingId({ id: '9f1c-uuid' })).toBe('9f1c-uuid')
		expect(bookingId({ '@self': { uuid: 'self-uuid' } })).toBe('self-uuid')
		expect(bookingId({})).toBe('')
	})
})

describe('CalendarView.isConflict', () => {
	it('flags exactly the pending bookings (bk-002 / bk-003 in the seed)', () => {
		expect(isConflict({ status: 'pending' })).toBe(true)
		expect(isConflict({ status: 'confirmed' })).toBe(false)
		expect(isConflict({})).toBe(false)
	})
})
