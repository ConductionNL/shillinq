/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The booking form's validation, its submit gate and the conflict
 * override, and what it renders (server-side, no DOM) while saving, on a
 * refusal and with a conflict.
 *
 * @spec openspec/specs/bookings/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render() { return h('button', this.$attrs, this.$slots.default?.()) } },
}))
vi.mock('../../src/modals/BookingConflictDialog.vue', () => ({
	default: { name: 'BookingConflictDialog', props: ['open', 'conflicts'], render() { return h('div', { 'data-stub': 'conflicts', 'data-count': this.conflicts.length, 'data-open': String(this.open) }) } },
}))

globalThis.t = (app, text) => text

const { default: BookingForm } = await import('../../src/views/bookings/BookingForm.vue')

/**
 * A plain form instance with that data.
 *
 * @param {object} form The form values.
 * @return {object} The instance.
 */
function bookingForm(form = {}) {
	const self = { calendarId: 'cal-1', initialStart: '', initialEnd: '', $emit: vi.fn() }
	Object.assign(self, BookingForm.data.call(self))
	Object.assign(self.form, { title: 'Intake', attendee: 'jan@example.org', startTime: '2026-12-02T09:00', endTime: '2026-12-02T10:00' }, form)
	for (const [name, method] of Object.entries(BookingForm.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

/**
 * Render the form with its data replaced.
 *
 * @param {object} over The data to lay over the defaults.
 * @return {Promise<string>} The HTML.
 */
function render(over) {
	const state = { form: { title: '', startTime: '', endTime: '', attendee: '', status: 'pending' }, validationError: '', submitting: false, showConflictDialog: false, conflicts: [], ...over }
	const app = createSSRApp({ ...BookingForm, data: () => state }, { calendarId: 'cal-1' })
	app.config.globalProperties.t = globalThis.t
	return renderToString(app)
}

describe('BookingForm validation', () => {
	it('names the first missing or wrong field', () => {
		const cases = [
			[{ title: '  ' }, 'Title is required'],
			[{ attendee: '' }, 'Attendee is required'],
			[{ endTime: '' }, 'Start and end time are required'],
			[{ startTime: 'tomorrow' }, 'Start and end time must be valid'],
			[{ endTime: '2026-12-02T09:00' }, 'End time must be after start time'],
			[{ endTime: '2026-12-02T09:14' }, 'Booking duration must be at least 15 minutes'],
			[{ endTime: '2026-12-02T09:15' }, ''],
		]
		for (const [form, message] of cases) {
			expect(bookingForm(form).validate()).toBe(message)
		}
	})

	it('posts only a valid form, and posts again with the override after a conflict', async () => {
		const invalid = bookingForm({ title: '' })
		invalid.postBooking = vi.fn()
		await invalid.submit()
		expect(invalid.validationError).toBe('Title is required')
		expect(invalid.postBooking).not.toHaveBeenCalled()

		const valid = bookingForm()
		valid.postBooking = vi.fn()
		await valid.submit()
		expect(valid.validationError).toBe('')
		expect(valid.postBooking).toHaveBeenCalledWith(false)

		valid.showConflictDialog = true
		await valid.confirmDespiteConflict()
		expect(valid.showConflictDialog).toBe(false)
		expect(valid.postBooking).toHaveBeenLastCalledWith(true)
	})
})

describe('BookingForm render', () => {
	it('shows the create button and no error or dialog at rest', async () => {
		const html = await render({})
		expect(html).toContain('Create Booking')
		expect(html).not.toContain('booking-form-error')
		expect(html).not.toContain('data-stub="conflicts"')
	})

	it('shows the saving label, the refusal and the conflict dialog', async () => {
		const html = await render({ submitting: true, validationError: 'Title is required', showConflictDialog: true, conflicts: [{ bookingId: 'bk-2' }] })
		expect(html).toContain('Saving…')
		expect(html).toMatch(/data-testid="booking-form-error"[^>]*>\s*Title is required/)
		expect(html).toContain('data-count="1"')
		expect(html).toContain('data-open="true"')
	})
})
