// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// bookings-messages-and-embedding 1.4: the repeat option on the booking form.
// Turns the form's frequency and end into the RRULE that
// POST /api/v1/appointment-series expands (FREQ DAILY|WEEKLY|MONTHLY,
// INTERVAL, COUNT or UNTIL), and sends the series.
//
// @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-recurring-series/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The most occurrences one series may have (RecurringSeriesService::MAX_OCCURRENCES).
 */
export const MAX_OCCURRENCES = 366

const FREQUENCIES = Object.freeze({ daily: 'DAILY', weekly: 'WEEKLY', monthly: 'MONTHLY' })

/**
 * The RRULE for a repeat choice, or '' when the choice is incomplete.
 *
 * @param {object} repeat The choice.
 * @param {string} repeat.frequency daily, weekly or monthly.
 * @param {number} [repeat.interval] Every how many periods (1 or more).
 * @param {string} repeat.endType count or until.
 * @param {number} [repeat.count] How many times in all, for count.
 * @param {string} [repeat.until] The last date, YYYY-MM-DD, for until.
 * @return {string} The RRULE.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export function recurrenceRule(repeat) {
	const freq = FREQUENCIES[repeat?.frequency]
	if (!freq) {
		return ''
	}

	const parts = ['FREQ=' + freq]
	const interval = Math.trunc(Number(repeat.interval || 1))
	if (interval > 1) {
		parts.push('INTERVAL=' + interval)
	}

	if (repeat.endType === 'count') {
		const count = Math.trunc(Number(repeat.count))
		if (!(count >= 2 && count <= MAX_OCCURRENCES)) {
			return ''
		}
		parts.push('COUNT=' + count)
	} else if (repeat.endType === 'until') {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(String(repeat.until || ''))) {
			return ''
		}
		parts.push('UNTIL=' + repeat.until.replaceAll('-', ''))
	} else {
		return ''
	}

	return parts.join(';')
}

/**
 * Why the series cannot be saved yet, or '' when it can.
 *
 * @param {object} form The form.
 * @param {(app: string, text: string, vars?: object) => string} translate The t() of the caller, so the sentence is translated.
 * @return {string} The reason.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export function seriesProblem(form, translate) {
	if (!form.serviceId || !form.resourceId) {
		return translate('shillinq', 'Choose a service and a resource.')
	}
	const start = new Date(form.startTime)
	if (!form.startTime || isNaN(start.getTime())) {
		return translate('shillinq', 'Choose when the first appointment starts.')
	}
	if (!(Math.trunc(Number(form.durationMinutes)) >= 5)) {
		return translate('shillinq', 'An appointment lasts at least 5 minutes.')
	}
	if (form.repeat.endType === 'count') {
		const count = Math.trunc(Number(form.repeat.count))
		if (!(count >= 2 && count <= MAX_OCCURRENCES)) {
			return translate('shillinq', 'Repeat 2 to {max} times.', { max: MAX_OCCURRENCES })
		}
	}
	if (form.repeat.endType === 'until' && !(String(form.repeat.until || '') > form.startTime.slice(0, 10))) {
		return translate('shillinq', 'The last date is after the first appointment.')
	}
	if (recurrenceRule(form.repeat) === '') {
		return translate('shillinq', 'Choose how often the appointment repeats and when it ends.')
	}
	return ''
}

/**
 * A new series id.
 *
 * @return {string} The id.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export function newSeriesId() {
	if (globalThis.crypto?.randomUUID) {
		return 'series-' + globalThis.crypto.randomUUID()
	}
	return 'series-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
}

/**
 * The request body for a form.
 *
 * @param {object} form The form.
 * @param {string} administrationId The active administration.
 * @param {string} seriesId The series id.
 * @return {object} The body.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export function seriesPayload(form, administrationId, seriesId) {
	const payload = {
		administrationId,
		seriesId,
		serviceId: form.serviceId,
		resourceId: form.resourceId,
		startTime: new Date(form.startTime).toISOString(),
		durationMinutes: Math.trunc(Number(form.durationMinutes)),
		recurrenceRule: recurrenceRule(form.repeat),
	}
	if (form.customerId) {
		payload.customerId = form.customerId
	}
	return payload
}

/**
 * Create the series.
 *
 * @param {object} payload The body from seriesPayload().
 * @return {Promise<{seriesId: string, generated: number, skipped: number}>} The answer.
 * @spec openspec/changes/bookings-messages-and-embedding/specs/bookings-recurring-series/spec.md#requirement-the-booking-form-offers-a-repeat-option-that-creates-the-series-req-brs-004
 */
export async function createAppointmentSeries(payload) {
	const response = await axios.post(generateUrl('/apps/shillinq/api/v1/appointment-series'), payload)
	return response.data
}

/**
 * The active services or resources of an administration, as select options.
 *
 * @param {string} schema Service or Resource.
 * @param {string} administrationId The administration.
 * @param {string} idField serviceId or resourceId.
 * @return {Promise<Array<{id: string, label: string, duration: number}>>} The options.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export async function loadOptions(schema, administrationId, idField) {
	const response = await axios.get(generateUrl('/apps/openregister/api/objects/shillinq/' + schema), {
		params: { administrationId, status: 'active', _limit: 200 },
	})
	const rows = Array.isArray(response.data?.results) ? response.data.results : []
	return rows
		.filter((row) => row && row[idField])
		.map((row) => ({ id: String(row[idField]), label: String(row.name || row[idField]), duration: Number(row.duration || 0) }))
}
