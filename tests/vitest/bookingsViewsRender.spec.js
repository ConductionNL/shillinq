/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The public confirmation portal and the calendar page: what each renders
 * (server-side, so no DOM is needed) in each state, and their actions.
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-14
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), patch: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/views/bookings/CalendarView.vue', () => ({
	default: { name: 'CalendarView', props: ['calendarId'], render() { return h('div', { 'data-stub': 'CalendarView', 'data-calendar': this.calendarId }) } },
}))
vi.mock('../../src/views/bookings/BookingForm.vue', () => ({
	default: { name: 'BookingForm', props: ['calendarId', 'initialStart', 'initialEnd'], render() { return h('form', { 'data-start': this.initialStart, 'data-end': this.initialEnd }) } },
}))

const { default: ConfirmationPortal } = await import('../../src/views/bookings/ConfirmationPortal.vue')
const { default: CalendarPage } = await import('../../src/views/bookings/CalendarPage.vue')
const { default: AfspraakDetail } = await import('../../src/views/bookings/AfspraakDetail.vue')

/**
 * Render a view with its data replaced by `state`.
 *
 * @param {object} view The component.
 * @param {object} state The data.
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
function render(view, state, props = {}) {
	return renderToString(createSSRApp({ ...view, data: () => state }, props))
}

/**
 * A plain instance of a view, its computed values readable.
 *
 * @param {object} view The component.
 * @param {object} over Values to set first.
 * @return {object} The instance.
 */
function instance(view, over = {}) {
	const self = { ...view.data.call({}), ...over, $emit: vi.fn() }
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(view.computed ?? {})) {
		if (!(name in over)) {
			Object.defineProperty(self, name, { get: getter.bind(self) })
		}
	}
	return self
}

const appointment = (over = {}) => ({ appointmentId: 'a-1', serviceName: 'Paspoort', startTime: '2026-12-01T09:30:00Z', endTime: '', location: 'Stadhuis', notes: 'Neem ID mee', status: 'pending_confirmation', confirmationDeadline: '', ...over })
const portal = (over) => ({ loading: false, confirming: false, resending: false, confirmed: false, error: null, appointment: appointment(), ...over })

describe('ConfirmationPortal render', () => {
	it('shows the link check while loading', async () => {
		const html = await render(ConfirmationPortal, portal({ loading: true }))
		expect(html).toContain('data-testid="bk-confirm-loading"')
		expect(html).not.toContain('data-testid="bk-confirm-form"')
	})

	it('shows the appointment with location and notes and a confirm button', async () => {
		const html = await render(ConfirmationPortal, portal({}))
		expect(html).toContain('data-testid="bk-confirm-form"')
		expect(html).toMatch(/<h2[^>]*>Paspoort<\/h2>/)
		expect(html).toContain('Stadhuis')
		expect(html).toContain('Neem ID mee')
		expect(html).toContain('Confirm appointment')

		const bare = await render(ConfirmationPortal, portal({ confirming: true, appointment: appointment({ serviceName: '', location: '', notes: '', startTime: '' }) }))
		expect(bare).toMatch(/<h2[^>]*>Your appointment<\/h2>/)
		expect(bare).not.toContain('Location')
		expect(bare).not.toContain('Notes')
		expect(bare).toMatch(/<button[^>]*disabled[^>]*>\s*Confirming…/)
	})

	it('shows the confirmation with its details', async () => {
		const html = await render(ConfirmationPortal, portal({ confirmed: true }))
		expect(html).toContain('data-testid="bk-confirm-success"')
		expect(html).toContain('Appointment confirmed!')
		expect(html).toContain('Stadhuis')
		expect(await render(ConfirmationPortal, portal({ confirmed: true, appointment: appointment({ location: '' }) }))).not.toContain('Location')
	})

	it('shows the reason a link fails, with the resend button', async () => {
		const html = await render(ConfirmationPortal, portal({ error: 'expired' }))
		expect(html).toContain('data-testid="bk-confirm-error"')
		expect(html).toContain('This confirmation link has expired. Please request a new one.')
		expect(html).toContain('data-testid="bk-confirm-resend"')
		expect(await render(ConfirmationPortal, portal({ error: 'something-new' }))).toContain('This confirmation link is not valid.')
	})
})

describe('ConfirmationPortal actions', () => {
	const original = globalThis.window

	beforeEach(() => {
		axios.get.mockReset()
		axios.patch.mockReset()
		axios.post.mockReset()
	})

	afterEach(() => {
		globalThis.window = original
	})

	/**
	 * Put the browser at that path and query.
	 *
	 * @param {string} pathname The path.
	 * @param {string} search The query.
	 * @return {void}
	 */
	function at(pathname, search) {
		globalThis.window = { location: { pathname, search } }
	}

	it('reads the appointment from the path or the query, and the token from the query', () => {
		at('/apps/shillinq/confirm/a%201', '?token=tok')
		expect([instance(ConfirmationPortal).appointmentIdFromUrl, instance(ConfirmationPortal).token]).toEqual(['a 1', 'tok'])
		at('/apps/shillinq/portal', '?appointmentId=a-2')
		expect([instance(ConfirmationPortal).appointmentIdFromUrl, instance(ConfirmationPortal).token]).toEqual(['a-2', ''])
		at('/x', '')
		expect(instance(ConfirmationPortal).appointmentIdFromUrl).toBe('')
	})

	it('refuses a link without token or appointment before calling the server', async () => {
		const view = instance(ConfirmationPortal, { token: '', appointmentIdFromUrl: 'a-1' })
		await view.validate()
		expect([view.error, view.loading]).toEqual(['invalid_input', false])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('validates the token and takes the appointment it answers', async () => {
		const view = instance(ConfirmationPortal, { token: 'tok', appointmentIdFromUrl: 'a-1' })
		axios.get.mockResolvedValue({ data: { ok: true, appointment: { serviceName: 'Rijbewijs' } } })
		await view.validate()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/appointments/validate-confirmation-token', { params: { token: 'tok', appointmentId: 'a-1' } })
		expect([view.error, view.appointment.serviceName, view.appointment.appointmentId]).toEqual([null, 'Rijbewijs', ''])

		axios.get.mockResolvedValue({ data: { ok: true } })
		await view.validate()
		expect(view.error).toBe(null)
	})

	it('records why a token is refused, or that the service is down', async () => {
		const view = instance(ConfirmationPortal, { token: 'tok', appointmentIdFromUrl: 'a-1' })
		axios.get.mockResolvedValue({ data: { ok: false, reason: 'revoked' } })
		await view.validate()
		expect(view.error).toBe('revoked')
		axios.get.mockResolvedValue({ data: null })
		await view.validate()
		expect(view.error).toBe('invalid')
		axios.get.mockRejectedValue(new Error('down'))
		await view.validate()
		expect([view.error, view.loading]).toEqual(['or_unavailable', false])
	})

	it('confirms once and takes the answered appointment', async () => {
		const view = instance(ConfirmationPortal, { token: 'tok', appointmentIdFromUrl: 'a/1' })
		axios.patch.mockResolvedValue({ data: { appointment: { status: 'confirmed' } } })
		await view.confirm()
		expect(axios.patch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/appointments/a%2F1/confirm', null, { params: { token: 'tok' } })
		expect([view.confirmed, view.confirming, view.appointment.status]).toEqual([true, false, 'confirmed'])

		view.confirming = true
		await view.confirm()
		expect(axios.patch).toHaveBeenCalledTimes(1)
	})

	it('maps a refused confirmation to its reason', async () => {
		const reasonFor = async (failure) => {
			const view = instance(ConfirmationPortal, { token: 'tok', appointmentIdFromUrl: 'a-1' })
			axios.patch.mockRejectedValueOnce(failure)
			await view.confirm()
			expect([view.confirmed, view.confirming]).toEqual([false, false])
			return view.error
		}
		expect(await reasonFor({ response: { status: 403 } })).toBe('expired')
		expect(await reasonFor({ response: { status: 401 } })).toBe('invalid')
		expect(await reasonFor(new Error('down'))).toBe('or_unavailable')
	})

	it('resends the email, clearing the error, and keeps it when that is refused', async () => {
		const view = instance(ConfirmationPortal, { token: '', appointmentIdFromUrl: 'a-1', error: 'expired', confirmed: true })
		axios.post.mockRejectedValueOnce(new Error('403'))
		await view.resend()
		expect([view.error, view.resending]).toEqual(['expired', false])

		axios.post.mockResolvedValueOnce({ data: {} })
		await view.resend()
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/appointments/a-1/resend-confirmation')
		expect([view.error, view.confirmed, view.appointment.status]).toEqual([null, false, 'pending_confirmation'])

		view.resending = true
		await view.resend()
		expect(axios.post).toHaveBeenCalledTimes(2)
	})

	it('labels through t when it exists, and states the time and zone', () => {
		const view = instance(ConfirmationPortal, { appointment: appointment() })
		const previous = globalThis.t
		try {
			delete globalThis.t
			expect(view.label('Service')).toBe('Service')
			globalThis.t = (app, text) => `${app}:${text}`
			expect(view.label('Service')).toBe('shillinq:Service')
		} finally {
			globalThis.t = previous
		}
		expect(view.localTimeLabel).toContain('2026')
		expect(instance(ConfirmationPortal).localTimeLabel).toBe('')
		expect(view.timezoneLabel).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone)
		expect(instance(ConfirmationPortal).errorMessage).toBe('')
	})
})

describe('CalendarPage', () => {
	it('renders the grid, and the form only when open', async () => {
		const closed = await render(CalendarPage, { formOpen: false, formStart: '', formEnd: '', selectedBookingId: '', gridKey: 0 }, { calendarId: 'cal-1' })
		expect(closed).toContain('data-calendar="cal-1"')
		expect(closed).not.toContain('booking-form-panel')

		const open = await render(CalendarPage, { formOpen: true, formStart: '2026-12-01T09:00', formEnd: '2026-12-01T10:00', selectedBookingId: '', gridKey: 1 }, { calendarId: 'cal-1' })
		expect(open).toContain('data-testid="booking-form-panel"')
		expect(open).toMatch(/data-start="2026-12-01T09:00"[^>]*data-end="2026-12-01T10:00"/)
	})

	it('opens the form on a clicked slot in local time, and refreshes the grid after a booking', () => {
		const page = instance(CalendarPage)
		const start = new Date(2026, 11, 1, 9, 5)
		page.onSlotClicked(start.toISOString(), 'not a date')
		expect([page.formOpen, page.formStart, page.formEnd]).toEqual([true, '2026-12-01T09:05', ''])

		page.onBookingSelected('b-7')
		expect(page.selectedBookingId).toBe('b-7')
		page.onBookingCreated()
		expect([page.formOpen, page.gridKey]).toEqual([false, 1])
	})
})

describe('AfspraakDetail', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('names the booking by its appointment id, else the route id', () => {
		expect(instance(AfspraakDetail, { id: 'r-1', payload: { booking: { appointmentId: 'a-9' } } }).bookingReference).toBe('a-9')
		expect(instance(AfspraakDetail, { id: 'r-1' }).bookingReference).toBe('r-1')
		expect(instance(AfspraakDetail, { id: undefined }).bookingReference).toBe('')
	})

	it('offers the back link only when the index route exists', () => {
		expect(instance(AfspraakDetail, { $router: { options: { routes: [{ name: 'Afspraken' }] } } }).hasIndexRoute).toBe(true)
		expect(instance(AfspraakDetail, { $router: { options: { routes: [{ name: 'Other' }] } } }).hasIndexRoute).toBe(false)
		expect(instance(AfspraakDetail, { $router: undefined }).hasIndexRoute).toBe(false)
	})

	it('lists the summary fields as strings, blank when absent', () => {
		const fields = instance(AfspraakDetail, { payload: { booking: { appointmentId: 'a-9', attendee: 7 } } }).summaryFields
		expect(fields.length).toBeGreaterThan(0)
		for (const field of fields) {
			expect(typeof field.value).toBe('string')
		}
		expect(fields.find((f) => f.key === 'appointmentId').value).toBe('a-9')
		expect(instance(AfspraakDetail).summaryFields.every((f) => f.value === '')).toBe(true)
	})

	it('lays the accumulated transactions over the klantbeeld', () => {
		expect(instance(AfspraakDetail).paginatedPayload).toBe(null)
		const bare = { booking: {} }
		expect(instance(AfspraakDetail, { payload: bare }).paginatedPayload).toBe(bare)

		const view = instance(AfspraakDetail, { payload: { booking: {}, klantbeeld: { limit: 2, transactions: [] } }, accumulatedTransactions: [{ id: 1 }] })
		expect(view.paginatedPayload.klantbeeld).toEqual({ limit: 2, transactions: [{ id: 1 }], empty: false })
		const none = instance(AfspraakDetail, { payload: { booking: {}, klantbeeld: { unavailable: true } }, accumulatedTransactions: [] })
		expect(none.paginatedPayload.klantbeeld.empty).toBe(false)
		const empty = instance(AfspraakDetail, { payload: { booking: {}, klantbeeld: {} }, accumulatedTransactions: [] })
		expect(empty.paginatedPayload.klantbeeld.empty).toBe(true)
	})

	it('has more pages only while the last batch filled its limit', () => {
		const more = (klantbeeld, extra = {}) => instance(AfspraakDetail, { payload: { klantbeeld }, ...extra }).hasMore
		expect(more(undefined)).toBe(false)
		expect(more({ unavailable: true, limit: 1, transactions: [1] })).toBe(false)
		expect(more({ limit: 2, transactions: [1, 2] })).toBe(true)
		expect(more({ limit: 2, transactions: [1] })).toBe(false)
		expect(more({ limit: 0, transactions: [1] })).toBe(false)
		expect(more({ limit: 2, transactions: 'x' })).toBe(false)
		expect(more({ limit: 2, transactions: [] }, { _lastBatchSize: 2 })).toBe(true)
	})

	it('reads the pipelinq link from the settings, blank when it cannot', async () => {
		const view = instance(AfspraakDetail)
		axios.get.mockResolvedValueOnce({ data: { pipelinqBaseUrl: 'https://crm.example' } })
		await view.loadConfig()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/settings/pipelinq')
		expect(view.pipelinqBaseUrl).toBe('https://crm.example')
		axios.get.mockResolvedValueOnce({ data: { baseUrl: 42 } })
		await view.loadConfig()
		expect(view.pipelinqBaseUrl).toBe('')
		axios.get.mockRejectedValueOnce(new Error('down'))
		await view.loadConfig()
		expect(view.pipelinqBaseUrl).toBe('')
	})
})
