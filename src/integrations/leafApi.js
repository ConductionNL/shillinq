// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The HTTP calls shillinq's two finance leaves make from a host object's
// detail page (ADR-019 / ADR-066).
//
// Two different servers answer here, and the split is the point:
//
//   * READS go to OpenRegister's generic per-object integration endpoint,
//     `/apps/openregister/api/objects/{register}/{schema}/{id}/integrations/
//     {leafId}`, which routes to shillinq's own IntegrationProvider. That is
//     why the DATA-PROVIDER leaf ids (`shillinq-payment-requests`,
//     `shillinq-contracts`) are named here rather than registered as tabs of
//     their own: the panel is the surface, the provider is its source.
//   * WRITES go to shillinq's own controller, because both actions refuse
//     first on `payment.administer` and then book money. Neither belongs on a
//     read-only leaf read (REQ-SOPR-004).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The data-provider leaf that lists the payment requests standing on an object.
 * Declared server-side in `PaymentRequestLeafProvider::LEAF_ID`.
 *
 * @type {string}
 */
export const PAYMENT_REQUESTS_DATA_LEAF = 'shillinq-payment-requests'

/**
 * The data-provider leaf that lists the contracts a host object is handled
 * under. Declared server-side in `ContractLeafProvider::LEAF_ID`.
 *
 * @type {string}
 */
export const CONTRACTS_DATA_LEAF = 'shillinq-contracts'

/**
 * Read one leaf's rows for a host object.
 *
 * @param {object} context      The host object identity.
 * @param {string} context.register The host object's register.
 * @param {string} context.schema   The host object's schema.
 * @param {string} context.objectId The host object's id.
 * @param {string} leafId       The data-provider leaf to read.
 *
 * @return {Promise<object>} The provider's own envelope.
 */
export async function readLeaf({ register, schema, objectId }, leafId) {
	const url = generateUrl(
		'/apps/openregister/api/objects/{register}/{schema}/{objectId}/integrations/{leafId}',
		{ register, schema, objectId, leafId },
	)
	const response = await axios.get(url)
	return response.data || {}
}

/**
 * Mail the payment link to the debtor and record when it went out.
 *
 * @param {string} requestId The payment request id.
 *
 * @return {Promise<object>} `{ sent, linkSentAt }`.
 */
export async function sendPaymentLink(requestId) {
	const url = generateUrl('/apps/shillinq/api/payment-requests/{requestId}/send', {
		requestId,
	})
	const response = await axios.post(url, {})
	return response.data || {}
}

/**
 * Record that the money arrived another way: cash or pin at the counter, a
 * bank transfer, or a waiver. The record is appended, never overwritten, so a
 * later gateway capture still reports as an overpayment (REQ-FPCR-003).
 *
 * @param {string} requestId The payment request id.
 * @param {object} settlement The settlement.
 * @param {string} settlement.method How the money arrived.
 * @param {string} settlement.settlementReference What a bank reconciliation matches on.
 * @param {number} settlement.amount How much arrived; 0 means the whole request.
 * @param {string} settlement.reason Why, which a waiver needs.
 *
 * @return {Promise<object>} The derived report.
 */
export async function settleByOtherMeans(requestId, settlement) {
	const url = generateUrl('/apps/shillinq/api/payment-requests/{requestId}/settle', {
		requestId,
	})
	const response = await axios.post(url, settlement)
	return response.data || {}
}

/**
 * Read the host object identity out of the props the registry hands a leaf.
 * Discrete props win; `integrationContext` is the fallback the object sidebar
 * and the widget grid both still pass.
 *
 * @param {object} props The component's own props.
 *
 * @return {{register: string, schema: string, objectId: string}} The identity.
 */
export function hostIdentity(props) {
	const context = props.integrationContext || {}
	return {
		register: String(props.register || context.register || ''),
		schema: String(props.schema || context.schema || ''),
		objectId: String(props.objectId || context.objectId || ''),
	}
}

/**
 * Whether a host identity is complete enough to read a leaf with.
 *
 * @param {{register: string, schema: string, objectId: string}} identity The identity.
 *
 * @return {boolean} True when all three parts are present.
 */
export function isResolvable(identity) {
	return (
		identity.register !== ''
		&& identity.schema !== ''
		&& identity.objectId !== ''
	)
}

/**
 * Format an amount the way a Dutch finance desk reads it.
 *
 * @param {number} amount   The amount.
 * @param {string} currency The ISO currency code.
 *
 * @return {string} The formatted amount.
 */
export function formatAmount(amount, currency) {
	const value = Number(amount)
	if (Number.isFinite(value) === false) {
		return ''
	}
	try {
		return new Intl.NumberFormat('nl-NL', {
			style: 'currency',
			currency: currency || 'EUR',
		}).format(value)
	} catch (e) {
		return `${currency || 'EUR'} ${value.toFixed(2)}`
	}
}
