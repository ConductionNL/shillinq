// SPDX-FileCopyrightText: 2026 Conduction B.V.
// SPDX-License-Identifier: EUPL-1.2
//
// REST client for the invoice-from-time-and-expense feature (issue #111).
// Wraps the Shillinq invoice endpoints with a thin axios layer using
// @nextcloud/axios + generateUrl so requests pick up the framework's
// CSRF token and per-instance base URL.
//
// @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-21

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/shillinq/api/v1/invoices' + path)

/**
 * Draft a new invoice from time + expense + billing model inputs.
 *
 * @param {object} request - InvoiceGenerationRequest body.
 * @return {Promise<object>} The persisted BillableInvoice (with id).
 */
async function generate(request) {
	const { data } = await axios.post(base('/generate'), request)
	return data
}

/**
 * Fetch an invoice + its line items by id.
 *
 * @param {string} invoiceId - BillableInvoice id.
 * @return {Promise<object>} { invoice, lines, auditTrail }.
 */
async function get(invoiceId) {
	const { data } = await axios.get(base('/' + encodeURIComponent(invoiceId)))
	return data
}

/**
 * Move an invoice from draft → posted; creates the Obligation and GL entries.
 *
 * @param {string} invoiceId - BillableInvoice id.
 * @return {Promise<object>} The posted invoice.
 */
async function post(invoiceId) {
	const { data } = await axios.post(
		base('/' + encodeURIComponent(invoiceId) + '/post'),
	)
	return data
}

/**
 * Export an invoice to PDF — opens the document in a new browser tab.
 *
 * @param {string} invoiceId - BillableInvoice id.
 * @return {Promise<void>}
 */
async function exportPdf(invoiceId) {
	const url = base('/' + encodeURIComponent(invoiceId) + '/pdf')
	if (typeof window !== 'undefined' && typeof window.open === 'function') {
		window.open(url, '_blank', 'noopener')
		return
	}
	await axios.get(url)
}

/**
 * List invoices filtered by date / billing model / status.
 *
 * @param {object} filters - Optional filters.
 * @return {Promise<Array>} BillableInvoice rows.
 */
async function list(filters = {}) {
	const { data } = await axios.get(base(''), { params: filters })
	return Array.isArray(data) ? data : data?.invoices || []
}

export default { generate, get, post, exportPdf, list }
