// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2
//
// REST client for the bookings-confirm-flow feature (REQ-BCF-004/006/007).
// Wraps the three confirmation endpoints with a thin axios layer using
// @nextcloud/axios + generateUrl so requests pick up the framework's
// CSRF token and per-instance base URL.
//
// @spec openspec/changes/bookings-confirm-flow/tasks.md#task-14

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/shillinq/api/v1/appointments' + path)

/**
 * Dry-run a confirmation token against an appointment — no side effects.
 * Used by the confirmation portal on mount so the user sees their
 * appointment details before pressing "Confirm" (REQ-BCF-007).
 *
 * @param {string} appointmentId - Appointment id.
 * @param {string} token - The plaintext confirmation token from the URL.
 * @return {Promise<object>} { ok: boolean, appointment?, reason? }
 */
async function validateConfirmationToken(appointmentId, token) {
	const { data } = await axios.get(base('/validate-confirmation-token'), {
		params: { token, appointmentId },
	})
	return data
}

/**
 * Confirm a pending appointment via its plaintext token (REQ-BCF-004).
 *
 * @param {string} appointmentId - Appointment id (path segment).
 * @param {string} token - The plaintext confirmation token.
 * @return {Promise<object>} { appointment: { ...confirmed appointment } }
 */
async function confirmAppointment(appointmentId, token) {
	const { data } = await axios.patch(
		base('/' + encodeURIComponent(appointmentId) + '/confirm'),
		null,
		{ params: { token } },
	)
	return data
}

/**
 * Request a fresh confirmation email — revokes the current token and
 * dispatches a new email with a new token (REQ-BCF-006).
 *
 * @param {string} appointmentId - Appointment id (path segment).
 * @return {Promise<object>} { ok: true, tokenId, sent, message }
 */
async function resendConfirmationEmail(appointmentId) {
	const { data } = await axios.post(
		base('/' + encodeURIComponent(appointmentId) + '/resend-confirmation'),
	)
	return data
}

export default {
	validateConfirmationToken,
	confirmAppointment,
	resendConfirmationEmail,
}
