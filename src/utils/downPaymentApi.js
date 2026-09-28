// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-down-payments: the calls behind the New down-payment invoice dialog
// and the down payments panel on the invoice page (DownPaymentController).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The VAT rates the dialog asks a net amount for, in percent.
 *
 * @type {Array<number>}
 */
export const VAT_RATES = [21, 9, 0]

/**
 * A number typed by a person: a comma or a dot as the decimal mark.
 *
 * @param {string|number} value The typed value.
 * @return {number} The number, or NaN.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export function parseAmount(value) {
	if (typeof value === 'number') {
		return value
	}
	const text = String(value ?? '')
		.trim()
		.replace(/\s/g, '')
	if (text === '') {
		return NaN
	}
	return Number(text.replace(',', '.'))
}

/**
 * The request POST /api/ar-invoices/down-payments reads.
 *
 * For an order shillinq holds, the server reads the order's lines; for any
 * other order the net per VAT rate goes along (design.md D2).
 *
 * @param {object} form The dialog state: administrationId, customerId,
 *   order ({value, label, shillinq}), mode ('percentage' or 'amount'), value,
 *   invoiceDate, rates ({21: '', 9: '', 0: ''}).
 * @return {object} The request body.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export function buildRaiseRequest(form) {
	const request = {
		administrationId: form.administrationId,
		customerId: form.customerId,
		orderReference: String(form.order?.value ?? ''),
		orderLabel: String(form.order?.label ?? ''),
	}
	const value = parseAmount(form.value)
	if (form.mode === 'amount') {
		request.amount = value
	} else {
		request.percentage = value
	}
	if (form.invoiceDate) {
		request.invoiceDate = form.invoiceDate
	}
	if (form.order && form.order.shillinq !== true) {
		request.orderVatBreakdown = VAT_RATES.map((rate) => ({
			rate: rate / 100,
			net: parseAmount(form.rates?.[rate]),
		})).filter((entry) => Number.isFinite(entry.net) && entry.net > 0)
	}
	return request
}

/**
 * Raise a draft down-payment invoice.
 *
 * @param {object} request From buildRaiseRequest().
 * @return {Promise<object>} The draft invoice.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export async function raiseDownPayment(request) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/ar-invoices/down-payments'),
		request,
	)
	return response.data
}

/**
 * The order's down-payment position and the orders left to deduct.
 *
 * @param {string} invoiceId The invoice uuid.
 * @return {Promise<object>} `{kind, orderLabel, position, openOrders, canDeduct}`.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export async function loadDownPayments(invoiceId) {
	const response = await axios.get(
		generateUrl(
			`/apps/shillinq/api/ar-invoices/${encodeURIComponent(invoiceId)}/down-payments`,
		),
	)
	return response.data
}

/**
 * Make a draft the final invoice of an order.
 *
 * @param {string} invoiceId The draft invoice uuid.
 * @param {string} orderReference The order.
 * @return {Promise<object>} The invoice as saved.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export async function deductDownPayments(invoiceId, orderReference) {
	const response = await axios.post(
		generateUrl(
			`/apps/shillinq/api/ar-invoices/${encodeURIComponent(invoiceId)}/down-payment-deductions`,
		),
		{ orderReference },
	)
	return response.data
}

/**
 * The server's refusal message, or a fallback.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The fallback text.
 * @return {string} The message to show.
 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
 */
export function errorMessage(error, fallback) {
	return String(error?.response?.data?.message || fallback)
}
