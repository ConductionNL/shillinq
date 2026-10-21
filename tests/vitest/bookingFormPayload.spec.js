/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BookingForm posts what the server reads: never a `status` (the server
 * decides it), `confirm` for "confirm at once", and the conflict override as
 * `overrideConflict` in the body (the server never read `?force=1`).
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BookingForm from '../../src/views/bookings/BookingForm.vue'

/**
 * A fake component instance for postBooking().
 *
 * @param {string} status The status radio value.
 * @return {object}
 */
function makeForm(status) {
	return {
		calendarId: 'cal-001',
		form: {
			title: 'Consult',
			startTime: '2026-05-21T14:00',
			endTime: '2026-05-21T14:30',
			attendee: 'Bob Jansen',
			status,
		},
		submitting: false,
		validationError: '',
		conflicts: [],
		showConflictDialog: false,
		$emit: vi.fn(),
		reset: vi.fn(),
		toUtcIso: BookingForm.methods.toUtcIso,
	}
}

describe('BookingForm.postBooking', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
		globalThis.t = (app, text) => text
		globalThis.fetch = vi.fn(async () => ({ status: 201, json: async () => ({ id: 'bk-new' }) }))
	})

	afterEach(() => {
		delete globalThis.OC
		delete globalThis.t
		delete globalThis.fetch
	})

	it('sends no status and asks to confirm only when Confirmed is chosen', async () => {
		await BookingForm.methods.postBooking.call(makeForm('confirmed'), false)
		const [url, init] = globalThis.fetch.mock.calls[0]
		const body = JSON.parse(init.body)
		expect(url).toMatch(/\/apps\/shillinq\/api\/v2\/calendars\/cal-001\/bookings$/)
		expect(body).not.toHaveProperty('status')
		expect(body.confirm).toBe(true)
		expect(body.overrideConflict).toBe(false)

		await BookingForm.methods.postBooking.call(makeForm('pending'), false)
		expect(JSON.parse(globalThis.fetch.mock.calls[1][1].body).confirm).toBe(false)
	})

	it('sends the conflict override as overrideConflict, not as ?force=1', async () => {
		await BookingForm.methods.postBooking.call(makeForm('pending'), true)
		const [url, init] = globalThis.fetch.mock.calls[0]
		expect(url).not.toContain('force')
		expect(JSON.parse(init.body).overrideConflict).toBe(true)
	})
})
