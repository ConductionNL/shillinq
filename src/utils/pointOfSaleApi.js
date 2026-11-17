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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
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
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
 */
export async function checkoutQrImage(checkoutUrl) {
	if (!checkoutUrl) {
		return ''
	}
	return QRCode.toDataURL(checkoutUrl, { margin: 1, width: 240 })
}
