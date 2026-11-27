// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-provider-payouts: the request behind Take payment now on
// ARInvoiceDetail, and the QR code of its checkout fallback. The payment
// itself is taken by PointOfSalePaymentController.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import QRCode from 'qrcode'

/**
 * Take an open invoice's payment on the user's registered device.
 *
 * @param {string} invoiceId The invoice's id.
 * @return {Promise<{mode: string, request: object, checkoutUrl: string}>} `device` when the
 *   payment went to the phone, `qr` when it is a checkout to show as a QR code.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.1
 */
export async function takePayment(invoiceId) {
	const response = await axios.post(
		generateUrl(`/apps/shillinq/api/financial-documents/invoices/${encodeURIComponent(String(invoiceId))}/take-payment`),
	)
	const data = response?.data ?? {}
	return {
		mode: String(data.mode || ''),
		request: data.request ?? {},
		checkoutUrl: String(data.checkoutUrl || ''),
	}
}

/**
 * Why the server refused, or the fallback when it gave no reason.
 *
 * @param {object} error The request error.
 * @param {string} fallback The text when the answer names none.
 * @return {string} The message.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.1
 */
export function refusalMessage(error, fallback) {
	const data = error?.response?.data ?? {}
	return String(data.error || data.message || fallback)
}

/**
 * The checkout URL as a QR code image, for the customer to scan.
 *
 * @param {string} checkoutUrl The provider's checkout URL.
 * @return {Promise<string>} A data URL of the image, or '' without a URL.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.1
 */
export async function checkoutQrImage(checkoutUrl) {
	if (!checkoutUrl) {
		return ''
	}
	return QRCode.toDataURL(checkoutUrl, { margin: 1, width: 240 })
}

/**
 * Record a counter sale and start its payment on the user's phone (REQ-RPPO-006).
 *
 * @param {object} sale The sale.
 * @param {string} sale.administrationId The administration that sells.
 * @param {Array<{description: string, quantity: number, unitPrice: number, vatRate: number}>} sale.lines
 *   The lines, unitPrice including VAT.
 * @param {string} [sale.customerId] A customer of the administration; empty for the counter-sales debtor.
 * @return {Promise<{invoice: object, mode: string, request: object, checkoutUrl: string}>} The draft
 *   invoice and the started payment, `device` or `qr` as for Take payment now.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
export async function startCounterSale({ administrationId, lines, customerId = '' }) {
	const body = { administrationId: String(administrationId || ''), lines }
	if (customerId) {
		body.customerId = String(customerId)
	}
	const response = await axios.post(generateUrl('/apps/shillinq/api/financial-documents/counter-sales'), body)
	const data = response?.data ?? {}
	return {
		invoice: data.invoice ?? {},
		mode: String(data.mode || ''),
		request: data.request ?? {},
		checkoutUrl: String(data.checkoutUrl || ''),
	}
}

/**
 * Cancel a counter sale the customer walked away from; nothing is booked.
 *
 * @param {string} invoiceId The draft invoice of the sale.
 * @return {Promise<object>} The cancelled invoice.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
export async function cancelCounterSale(invoiceId) {
	const response = await axios.post(
		generateUrl(`/apps/shillinq/api/financial-documents/counter-sales/${encodeURIComponent(String(invoiceId))}/cancel`),
	)
	return response?.data?.invoice ?? {}
}

/**
 * The total a customer pays for these lines, in cents to avoid float drift.
 *
 * @param {Array<{quantity: number|string, unitPrice: number|string}>} lines The lines, prices including VAT.
 * @return {number} The total, rounded to the cent.
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/tasks.md#task-3.2
 */
export function counterSaleTotal(lines) {
	const cents = (lines || []).reduce((sum, line) => {
		const quantity = Number(line?.quantity ?? 1)
		const price = Number(line?.unitPrice)
		if (!Number.isFinite(quantity) || !Number.isFinite(price)) {
			return sum
		}
		return sum + Math.round(quantity * price * 100)
	}, 0)
	return cents / 100
}
