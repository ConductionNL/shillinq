/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BookingForm.vue's postBooking(): the request,
 * and what a conflict, a creation and a refusal each leave. Methods run bound
 * to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/bookings/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import BookingForm from '../../src/views/bookings/BookingForm.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => '/index.php' + url.replace('{calendarId}', params.calendarId) }))

/**
 * A fake BookingForm with its methods bound and its side effects recorded.
 *
 * @return {object}
 */
function form() {
	const self = { ...BookingForm.data.call({}), calendarId: 'cal-1', emitted: [], resets: 0 }
	for (const [name, method] of Object.entries(BookingForm.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.emitted.push(args)
	self.reset = () => { self.resets++ }
	self.toUtcIso = (value) => `utc(${value})`
	Object.assign(self.form, { title: 'Intake', startTime: 's', endTime: 'e', attendee: 'a@b', status: 'confirmed' })
	return self
}

/**
 * A fetch response with a status and a JSON body.
 *
 * @param {number} status The HTTP status.
 * @param {*} body The JSON body, or an Error the parse rejects with.
 * @return {object}
 */
function reply(status, body) {
	return { status, json: () => (body instanceof Error ? Promise.reject(body) : Promise.resolve(body)) }
}

describe('BookingForm postBooking', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
		globalThis.t = (app, text) => `${app}:${text}`
		globalThis.fetch = vi.fn()
	})

	it('posts the booking and announces a creation', async () => {
		fetch.mockResolvedValue(reply(201, { id: 'bk-1' }))
		const vm = form()

		await vm.postBooking(true)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v2/calendars/cal-1/bookings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ title: 'Intake', startTime: 'utc(s)', endTime: 'utc(e)', attendee: 'a@b', confirm: true, overrideConflict: true }),
		})
		expect([vm.emitted, vm.resets, vm.submitting]).toEqual([[['booking:created', { id: 'bk-1' }]], 1, false])
	})

	it('opens the conflict dialog on a 409 without forcing', async () => {
		fetch.mockResolvedValue(reply(409, {}))
		const vm = form()
		vm.form.status = 'tentative'

		await vm.postBooking('yes')

		expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ confirm: false, overrideConflict: false })
		expect([vm.conflicts, vm.showConflictDialog, vm.resets, vm.submitting]).toEqual([[], true, 0, false])
	})

	it('shows the server error, or the generic one', async () => {
		const errors = []
		for (const response of [reply(422, { error: 'Start is in the past' }), reply(500, new Error('not json')), reply(400, {})]) {
			fetch.mockResolvedValueOnce(response)
			const vm = form()
			await vm.postBooking(false)
			errors.push(vm.validationError)
		}
		fetch.mockRejectedValueOnce(new Error('offline'))
		const vm = form()
		await vm.postBooking(false)
		errors.push(vm.validationError)

		expect(errors).toEqual(['Start is in the past', 'shillinq:Failed to create booking', 'shillinq:Failed to create booking', 'shillinq:Failed to create booking'])
		expect(vm.submitting).toBe(false)
	})
})
