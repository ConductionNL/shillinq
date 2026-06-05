// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Client for the bookings-confirm-flow API endpoints (REQ-BCF-007).
// Wraps the validate / confirm / resend routes exposed by
// ConfirmationApiController. The raw token never leaves the query string.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Validate a confirmation token without redeeming it (dry-run).
 *
 * @param {string} appointmentId The appointment identifier.
 * @param {string} token The raw confirmation token.
 * @return {Promise<object>} The validation result with masked appointment.
 */
export async function validateConfirmationToken(appointmentId, token) {
	const url = generateUrl('/apps/shillinq/api/appointments/validate-confirmation-token')
	const response = await axios.get(url, {
		params: { appointmentId, token },
	})
	return response.data
}

/**
 * Redeem a token and confirm the appointment.
 *
 * @param {string} appointmentId The appointment identifier.
 * @param {string} token The raw confirmation token.
 * @return {Promise<object>} The confirmation result with masked appointment.
 */
export async function confirmAppointment(appointmentId, token) {
	const url = generateUrl('/apps/shillinq/api/appointments/{appointmentId}/confirm', { appointmentId })
	const response = await axios.patch(url, {}, {
		params: { token },
	})
	return response.data
}

/**
 * Request a fresh confirmation email (revokes the prior token).
 *
 * @param {string} appointmentId The appointment identifier.
 * @return {Promise<object>} The resend result.
 */
export async function resendConfirmationEmail(appointmentId) {
	const url = generateUrl('/apps/shillinq/api/appointments/{appointmentId}/resend-confirmation', { appointmentId })
	const response = await axios.post(url, {})
	return response.data
}
