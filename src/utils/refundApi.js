// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-object-request-refund-and-credit 3.2: the two refund steps'
// URLs and the server's reason for a refusal, shared by the Refunds to pay
// row actions and RefundPaidModal.

import { generateUrl } from '@nextcloud/router'

/**
 * The URL of one refund step on a payment request.
 *
 * @param {string} id The payment request id.
 * @param {string} step `approve` or `paid`.
 * @return {string} The URL.
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md
 */
export function refundStepUrl(id, step) {
	return generateUrl(
		`/apps/shillinq/api/payment-requests/${encodeURIComponent(id)}/refund/${step}`,
	)
}

/**
 * The server's reason for a refused step, else the fallback.
 *
 * @param {unknown} error The axios error.
 * @param {string} fallback The text when the server gave none.
 * @return {string} The reason.
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md
 */
export function refundError(error, fallback) {
	const data = error?.response?.data
	return String(data?.error || data?.message || fallback)
}
