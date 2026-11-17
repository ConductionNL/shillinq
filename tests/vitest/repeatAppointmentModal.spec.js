/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * bookings-messages-and-embedding 1.4: the repeat option on the booking form.
 * The RRULE the choice becomes, the checks before saving, what the dialog
 * sends to /api/v1/appointment-series and what it renders after.
 *
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-recurring-series/spec.md#requirement-the-booking-form-offers-a-repeat-option-that-creates-the-series-req-brs-004
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'inputLabel', 'disabled', 'modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-label': this.inputLabel || this.label, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button'), NcTextField: slotted('NcTextField'), NcSelect: slotted('NcSelect') }
})

const { default: RepeatAppointmentModal } = await import('../../src/modals/RepeatAppointmentModal.vue')
const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
const { openRepeatAppointment } = await import('../../src/utils/appointmentSeriesActions.js')
const { recurrenceRule, seriesPayload, seriesProblem, newSeriesId } = await import('../../src/utils/appointmentSeriesApi.js')

const echo = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key]))

/**
 * A plain instance of the dialog, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function instance(over = {}) {
	const self = { $emit: vi.fn() }
	Object.assign(self, RepeatAppointmentModal.data.call(self), over)
	for (const [name, method] of Object.entries(RepeatAppointmentModal.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(RepeatAppointmentModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

/**
 * Render the dialog with its data replaced.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	return renderToString(createSSRApp({ ...RepeatAppointmentModal, data: () => ({ ...RepeatAppointmentModal.data(), ...state }) }))
}

const weekly = { frequency: 'weekly', interval: 1, endType: 'count', count: 6, until: '' }
const filled = { serviceId: 'svc-cut', resourceId: 'res-chair', startTime: '2026-12-01T10:00', durationMinutes: 30, repeat: weekly }

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('recurrenceRule', () => {
	it('writes the RRULE the series endpoint expands', () => {
		expect(recurrenceRule(weekly)).toBe('FREQ=WEEKLY;COUNT=6')
		expect(recurrenceRule({ frequency: 'daily', interval: 2, endType: 'count', count: 10 })).toBe('FREQ=DAILY;INTERVAL=2;COUNT=10')
		expect(recurrenceRule({ frequency: 'monthly', interval: '1', endType: 'until', until: '2027-06-30' })).toBe('FREQ=MONTHLY;UNTIL=20270630')
	})

	it('answers nothing for an incomplete or out-of-range choice', () => {
		expect(recurrenceRule({ ...weekly, frequency: 'yearly' })).toBe('')
		expect(recurrenceRule({ ...weekly, count: 1 })).toBe('')
		expect(recurrenceRule({ ...weekly, count: 367 })).toBe('')
		expect(recurrenceRule({ ...weekly, endType: 'until', until: '30-06-2027' })).toBe('')
		expect(recurrenceRule({ ...weekly, endType: 'never' })).toBe('')
		expect(recurrenceRule(null)).toBe('')
	})
})

describe('seriesProblem and seriesPayload', () => {
	it('names the first thing missing', () => {
		expect(seriesProblem({ ...filled, serviceId: '' }, echo)).toBe('Choose a service and a resource.')
		expect(seriesProblem({ ...filled, startTime: '' }, echo)).toBe('Choose when the first appointment starts.')
		expect(seriesProblem({ ...filled, durationMinutes: 2 }, echo)).toBe('An appointment lasts at least 5 minutes.')
		expect(seriesProblem({ ...filled, repeat: { ...weekly, count: 400 } }, echo)).toBe('Repeat 2 to 366 times.')
		expect(seriesProblem({ ...filled, repeat: { ...weekly, endType: 'until', until: '2026-12-01' } }, echo)).toBe('The last date is after the first appointment.')
		expect(seriesProblem({ ...filled, repeat: { ...weekly, frequency: '' } }, echo)).toBe('Choose how often the appointment repeats and when it ends.')
		expect(seriesProblem(filled, echo)).toBe('')
	})

	it('builds the request body with the start in UTC', () => {
		const payload = seriesPayload({ ...filled, customerId: 'cus-9' }, 'adm-1', 'series-1')
		expect(payload).toEqual({
			administrationId: 'adm-1',
			seriesId: 'series-1',
			serviceId: 'svc-cut',
			resourceId: 'res-chair',
			startTime: new Date('2026-12-01T10:00').toISOString(),
			durationMinutes: 30,
			recurrenceRule: 'FREQ=WEEKLY;COUNT=6',
			customerId: 'cus-9',
		})
		expect(seriesPayload(filled, 'adm-1', 's')).not.toHaveProperty('customerId')
		expect(newSeriesId()).toMatch(/^series-.{8,}$/)
		expect(newSeriesId()).not.toBe(newSeriesId())
	})
})

describe('RepeatAppointmentModal', () => {
	it('is what the header action opens', () => {
		openRepeatAppointment()
		expect(spawnDialog).toHaveBeenCalledWith(RepeatAppointmentModal, {})
	})

	it('loads the administration, its services and resources, and takes a service duration', async () => {
		const dialog = instance()
		axios.get
			.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-7' } })
			.mockResolvedValueOnce({ data: { results: [{ serviceId: 'svc-cut', name: 'Haircut', duration: 45 }, { name: 'no id' }] } })
			.mockResolvedValueOnce({ data: { results: [{ resourceId: 'res-chair', name: 'Chair 1' }] } })
		await RepeatAppointmentModal.mounted.call(dialog)

		expect(axios.get).toHaveBeenNthCalledWith(1, '/index.php/apps/shillinq/api/administrations/context')
		expect(axios.get).toHaveBeenNthCalledWith(2, '/index.php/apps/openregister/api/objects/shillinq/Service', { params: { administrationId: 'adm-7', status: 'active', _limit: 200 } })
		expect(dialog.services).toEqual([{ id: 'svc-cut', label: 'Haircut', duration: 45 }])
		expect(dialog.resources).toEqual([{ id: 'res-chair', label: 'Chair 1', duration: 0 }])
		expect(dialog.loading).toBe(false)

		dialog.takeServiceDuration(dialog.services[0])
		expect(dialog.durationMinutes).toBe(45)
		dialog.takeServiceDuration(null)
		expect(dialog.durationMinutes).toBe(45)
	})

	it('books a weekly series of six in one go', async () => {
		const dialog = instance({
			administrationId: 'adm-7',
			service: { id: 'svc-cut' },
			resource: { id: 'res-chair' },
			startTime: '2026-12-01T10:00',
		})
		axios.post.mockResolvedValueOnce({ data: { seriesId: 'x', generated: 6, skipped: 0 } })

		await dialog.submit()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/v1/appointment-series')
		expect(body).toMatchObject({ administrationId: 'adm-7', serviceId: 'svc-cut', resourceId: 'res-chair', durationMinutes: 30, recurrenceRule: 'FREQ=WEEKLY;COUNT=6' })
		expect(body.seriesId).toMatch(/^series-/)
		expect(dialog.result).toEqual({ seriesId: 'x', generated: 6, skipped: 0 })
		expect(dialog.submitting).toBe(false)

		dialog.close()
		expect(dialog.$emit).toHaveBeenCalledWith('close', { seriesId: 'x', generated: 6, skipped: 0 })
	})

	it('refuses an incomplete form without a request, and shows a failed save', async () => {
		const incomplete = instance({ administrationId: 'adm-7' })
		await incomplete.submit()
		expect(axios.post).not.toHaveBeenCalled()
		expect(incomplete.error).toBe('Choose a service and a resource.')

		const failing = instance({ administrationId: 'adm-7', service: { id: 's' }, resource: { id: 'r' }, startTime: '2026-12-01T10:00' })
		axios.post.mockRejectedValueOnce({ response: { status: 400, data: { message: 'Unable to create the appointment series' } } })
		await failing.submit()
		expect(failing.result).toBeNull()
		expect(failing.error).toBe('Unable to create the appointment series')
	})

	it('renders the form, then the planned and skipped counts', async () => {
		const html = await render({ administrationId: 'adm-7' })
		expect(html).toContain('data-label="Service"')
		expect(html).toContain('data-label="How often"')
		expect(html).toContain('data-label="Number of appointments"')
		expect(html).not.toContain('data-label="Last date"')
		expect(await render({ endType: 'until' })).toContain('data-label="Last date"')
		expect(await render({ administrationId: '' })).toMatch(/data-testid="repeat-appointment-submit"[^>]*disabled/)

		const done = await render({ result: { generated: 5, skipped: 1 } })
		expect(done).toContain('5 appointments were planned in the series.')
		expect(done).toContain('1 time was skipped because it falls outside the opening hours or is already booked.')
		expect(done).not.toContain('repeat-appointment-submit')
	})
})
