/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The bookings calendar grid as it renders (server-side, no DOM) in its
 * month, week and day views, and its booking fetch and slot events.
 *
 * @spec openspec/specs/bookings/spec.md
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => '/index.php' + url.replace('{calendarId}', params.calendarId) }))

globalThis.t = (app, text) => text
globalThis.OC = { requestToken: 'rt' }

const { default: CalendarView } = await import('../../src/views/bookings/CalendarView.vue')

const bookings = [
	{ bookingId: 'bk-1', title: 'Intake', startTime: new Date(2026, 11, 2, 9, 0).toISOString(), status: 'confirmed' },
	{ id: 'row-2', title: 'Overlap', startTime: new Date(2026, 11, 2, 9, 30).toISOString(), status: 'pending' },
	{ '@self': { uuid: 'u-3' }, title: 'Later', startTime: new Date(2026, 11, 20, 14, 0).toISOString() },
]

/**
 * Render the grid for 2 December 2026 in that view.
 *
 * @param {string} view The view.
 * @return {Promise<string>} The HTML.
 */
function render(view) {
	return renderToString(createSSRApp(CalendarView, { calendarId: 'cal-1', view, startDate: '2026-12-02T12:00:00', initialBookings: bookings }))
}

/**
 * A plain instance of the grid.
 *
 * @param {object} props The props.
 * @return {object} The instance.
 */
function grid(props = {}) {
	const self = { calendarId: 'cal-1', view: 'month', startDate: '2026-12-02T12:00:00', initialBookings: [], ...props, $emit: vi.fn() }
	Object.assign(self, CalendarView.data.call(self))
	for (const [name, method] of Object.entries(CalendarView.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(CalendarView.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('CalendarView render', () => {
	it('draws every day of the month with its bookings, conflicts marked', async () => {
		const html = await render('month')
		expect(html).toContain('data-testid="calendar-month-grid"')
		expect((html.match(/class="calendar-view__cell"/g) || []).length).toBe(31)
		expect(html).toMatch(/data-date="2026-12-02"[\s\S]*data-testid="booking-bk-1"[\s\S]*data-testid="booking-row-2"/)
		expect(html).toMatch(/calendar-view__booking--conflict[^>]*data-testid="booking-row-2"/)
		expect(html).toContain('data-testid="booking-u-3"')
		expect(html).toMatch(/calendar-view__view-button--active[^>]*data-testid="calendar-view-month"/)
		expect(html).toContain('>Week<')
	})

	it('draws the week from Monday with hour slots and the bookings in their hour', async () => {
		const html = await render('week')
		expect(html).toContain('data-testid="calendar-week-grid"')
		expect((html.match(/calendar-view__week-column/g) || []).length).toBe(7)
		expect(html).toContain('data-testid="calendar-slot-2026-11-30-0"')
		expect(html).toContain('data-testid="calendar-slot-2026-12-06-23"')
		expect(html).toMatch(/calendar-slot-2026-12-02-9"[\s\S]*?09:00[\s\S]*?booking-bk-1[\s\S]*?booking-row-2[\s\S]*?calendar-slot-2026-12-02-10"/)
		expect(html).not.toContain('booking-u-3')
	})

	it('draws the day with its date in the title', async () => {
		const html = await render('day')
		expect(html).toContain('data-testid="calendar-day-grid"')
		expect(html).toContain('data-testid="calendar-slot-2026-12-02-9"')
		expect(html).toContain(new Date(2026, 11, 2).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }))
		expect(html).toContain('data-testid="booking-bk-1"')
	})
})

describe('CalendarView behaviour', () => {
	afterEach(() => {
		delete globalThis.fetch
	})

	it('fetches the calendar bookings from either envelope', async () => {
		const view = grid()
		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ bookings }) }))
		await view.fetchBookings()
		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v2/calendars/cal-1/bookings', { headers: { requesttoken: 'rt' } })
		expect(view.bookings).toEqual(bookings)

		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => [bookings[0]] }))
		await view.fetchBookings()
		expect(view.bookings).toEqual([bookings[0]])
		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ results: [bookings[1]] }) }))
		await view.fetchBookings()
		expect(view.bookings).toEqual([bookings[1]])
		globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }))
		await view.fetchBookings()
		expect(view.bookings).toEqual([])
	})

	it('keeps the bookings on a refused answer and clears them on a failed fetch', async () => {
		const view = grid({ initialBookings: bookings })
		globalThis.fetch = vi.fn(async () => ({ ok: false }))
		await view.fetchBookings()
		expect(view.bookings).toEqual(bookings)
		globalThis.fetch = vi.fn(async () => {
			throw new Error('offline')
		})
		await view.fetchBookings()
		expect(view.bookings).toEqual([])
	})

	it('emits an hour-long slot from the clicked hour', () => {
		const view = grid()
		view.emitSlot('2026-12-02', 9)
		const [event, start, end] = view.$emit.mock.calls[0]
		expect(event).toBe('slot:clicked')
		expect(new Date(start).getHours()).toBe(9)
		expect(new Date(end) - new Date(start)).toBe(3600 * 1000)
	})

	it('falls back to an empty booking id, the view name as label, and follows prop changes', () => {
		const view = grid()
		expect(view.bookingId({ '@self': {} })).toBe('')
		expect(view.bookingId({})).toBe('')
		expect(view.viewLabel('year')).toBe('year')
		CalendarView.watch.view.call(view, 'day')
		CalendarView.watch.initialBookings.call(view, [bookings[2]])
		expect([view.currentView, view.bookings]).toEqual(['day', [bookings[2]]])
		expect(grid({ startDate: '' }).dayIso).toBe(view.toIsoDate(new Date()))
	})
})
