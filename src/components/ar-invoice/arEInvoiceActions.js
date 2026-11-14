/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pure-logic helper layer for AREInvoiceActions.vue (REQ-EINV-007): the Send
 * action enable/disable rule, the delivery-status resolution (server value
 * vs. an optimistic local override applied right after a successful send),
 * the REST endpoint builder and the send-result -> UI-state mapper. Kept
 * side-effect-free and framework-free so it is unit-testable without
 * mounting the SFC (mirrors src/modals/invoiceQuickDraft.js).
 */

/**
 * Whether the Send e-invoice action is enabled for the given ARInvoice.
 * Server-authoritative (ADR-005): this only governs the button's disabled
 * state on the client, never business-rule enforcement — EInvoiceService
 * re-checks lifecycleState === 'issued' on every call.
 *
 * @param {object|null|undefined} object The resolved ARInvoice record.
 * @return {boolean}
 */
export function canSendEInvoice(object) {
	return object?.lifecycleState === 'issued'
}

/**
 * Resolve the delivery-status chip's value: an optimistic local override (set
 * right after a successful send response) takes precedence over the object's
 * own field, which in turn falls back to 'not-sent' for a never-sent invoice.
 *
 * @param {object|null|undefined} object The resolved ARInvoice record.
 * @param {string|null} localOverride Optimistic local override, or null.
 * @return {string}
 */
export function resolveDeliveryStatus(object, localOverride) {
	return localOverride || object?.deliveryStatus || 'not-sent'
}

/**
 * Build the send-einvoice REST endpoint path for one invoice number.
 *
 * @param {string} invoiceNumber ARInvoice.invoiceNumber.
 * @return {string}
 */
export function sendEInvoiceEndpoint(invoiceNumber) {
	return `/apps/shillinq/api/ar-invoices/${invoiceNumber}/send-einvoice`
}

/**
 * Map a successful `send-einvoice` response into the UI state the component
 * needs: the new chip status and, when the server offered the null-Peppol-
 * participant fallback (REQ-EINV-003), a human-readable notice.
 *
 * @param {object} result The parsed response body ({ deliveryStatus, fallback, ... }).
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {{deliveryStatus: string, fallbackNotice: string}}
 */
export function mapSendResult(result, t) {
	const deliveryStatus = result?.deliveryStatus || 'queued'
	const fallbackNotice =
		result?.fallback === true
			? t(
					'shillinq',
					'No Peppol participant was found for this customer. Send the invoice by email instead.',
				)
			: ''
	return { deliveryStatus, fallbackNotice }
}

/**
 * Extract a user-facing error message from a failed axios response, falling
 * back to a generic message when the server did not return a structured error.
 *
 * @param {object} error The caught axios error.
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {string}
 */
export function extractSendErrorMessage(error, t) {
	return error?.response?.data?.error || t('shillinq', 'Failed to send e-invoice')
}

/**
 * Whether the Send by email action is enabled: only an issued or overdue
 * invoice can be sent (sales-invoice-sending REQ-SIS-001). The server checks
 * again on every call.
 *
 * @param {object|null|undefined} object The resolved ARInvoice record.
 * @return {boolean}
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
export function canSendInvoice(object) {
	return ['issued', 'overdue'].includes(object?.lifecycleState)
}

/**
 * The id the send endpoint takes: the object's id, else its uuid.
 *
 * @param {object|null|undefined} object The resolved ARInvoice record.
 * @return {string}
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
export function invoiceIdOf(object) {
	return String(object?.id || object?.uuid || object?.['@self']?.id || '')
}

/**
 * The send endpoint of one invoice.
 *
 * @param {string} id The ARInvoice id.
 * @return {string}
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
export function sendInvoiceEndpoint(id) {
	return `/apps/shillinq/api/ar-invoices/${encodeURIComponent(id)}/send`
}

/**
 * The line under the actions that says how the invoice went out, or why it did not.
 *
 * @param {object|null|undefined} sending The invoice's `sending` group.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string} The line, or '' when the invoice was never sent.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
export function sendingLine(sending, t) {
	if (!sending || typeof sending !== 'object') {
		return ''
	}
	if (sending.lastError) {
		return t('shillinq', 'The invoice could not be sent: {error}', { error: sending.lastError })
	}
	const at = sending.sentAt ? new Date(sending.sentAt).toLocaleString() : ''
	switch (sending.sentVia) {
	case 'email':
		return t('shillinq', 'Sent by email to {address} on {at}.', { address: sending.sentTo || '', at })
	case 'peppol':
		return t('shillinq', 'Sent through Peppol on {at}.', { at })
	case 'post':
		return t('shillinq', 'Delivery method post: the letter is printed and posted by hand.')
	case 'none':
		return t('shillinq', 'Delivery method none: the invoice is not sent from here.')
	default:
		return ''
	}
}

/**
 * What a send answered, for the page: the stored `sending` group, whether to
 * offer the mail instead of Peppol, and the error.
 *
 * @param {object} result The response body `{status, sending, error}`.
 * @return {{sending: object|null, offerEmail: boolean, error: string}}
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */
export function mapInvoiceSendResult(result) {
	const status = result?.status || ''
	return {
		sending: status === 'fallback' ? null : result?.sending || null,
		offerEmail: status === 'fallback',
		error: status === 'failed' || status === 'fallback' ? String(result?.error || '') : '',
	}
}
