// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Requests and feedback of the pipelinq integration settings panel
// (bookings-pipelinq-customer-bridge member 01). Kept out of
// PipelinqIntegration.vue so they are unit-testable on their own.

import { generateUrl } from '@nextcloud/router'

/**
 * POST JSON to a shillinq pipelinq settings route with the request token.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 * @param {string} path The app route, e.g. `/apps/shillinq/api/pipelinq/settings`.
 * @param {object} [body] The JSON body; the request carries none when omitted.
 * @return {Promise<Response>}
 */
export function postPipelinq(path, body) {
	const init = {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
	}
	if (body !== undefined) {
		init.body = JSON.stringify(body)
	}
	return fetch(generateUrl(path), init)
}

/**
 * The save body: the endpoint, and the token only when one was typed —
 * an absent token preserves the stored one.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 * @param {{endpoint: string, token: string}} form The panel form.
 * @return {{endpoint: string, token?: string}}
 */
export function settingsBody(form) {
	const body = { endpoint: form.endpoint }
	if (form.token !== '') {
		body.token = form.token
	}
	return body
}

/**
 * Error feedback with the given message.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 * @param {string} message The message shown.
 * @return {{kind: string, message: string}}
 */
export function errorFeedback(message) {
	return { kind: 'error', message }
}

/**
 * The feedback of a test-connection verdict: the backend's own outcome and message.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 * @param {{success: boolean, message: string}} data The test-connection response body.
 * @return {{kind: string, message: string}}
 */
export function testVerdict(data) {
	return {
		kind: data.success ? 'success' : 'error',
		message: data.message,
	}
}
