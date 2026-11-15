// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-invoice-sending 3.3: the pure half of New invoice batch. One set of
// lines goes to every selected customer through POST /api/ar-invoices/batch;
// the batch reference is made once per dialog, so pressing Create again after
// a dropped connection retries the same batch and writes nothing twice.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The most customers one batch bills (InvoiceBatchService::MAX_CUSTOMERS).
 */
export const MAX_BATCH_CUSTOMERS = 200

/**
 * The VAT rates a batch line offers.
 */
export const BATCH_VAT_RATES = Object.freeze([21, 9, 0])

/**
 * The batch-create endpoint.
 *
 * @return {string} The app-relative path.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function createBatchEndpoint() {
	return '/apps/shillinq/api/ar-invoices/batch'
}

/**
 * A new batch reference: BATCH- and twelve hexadecimal characters.
 *
 * @param {function(): number} random A source of numbers in [0, 1).
 * @return {string} The reference.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function newBatchId(random = Math.random) {
	let hex = ''
	for (let i = 0; i < 12; i++) {
		hex += Math.floor(random() * 16).toString(16)
	}
	return 'BATCH-' + hex.toUpperCase()
}

/**
 * An empty batch line: one unit at the high VAT rate.
 *
 * @return {{description: string, quantity: number, unitPrice: string, vatRate: number}} The line.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function emptyBatchLine() {
	return { description: '', quantity: 1, unitPrice: '', vatRate: 21 }
}

/**
 * A typed amount as a number: a comma decimal is read as a point.
 *
 * @param {string|number} value The typed value.
 * @return {number} The number, NaN when it is none.
 */
function amountOf(value) {
	if (typeof value === 'number') {
		return value
	}
	const text = String(value ?? '').trim().replace(',', '.')
	return text === '' ? NaN : Number(text)
}

/**
 * The lines that carry a description and a price, as the server reads them.
 *
 * @param {Array<object>} lines The lines as typed.
 * @return {Array<{description: string, quantity: number, unitPrice: number, vatRate: number}>} The priced lines.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function pricedLines(lines) {
	const out = []
	for (const line of Array.isArray(lines) ? lines : []) {
		const description = String(line?.description ?? '').trim()
		const unitPrice = amountOf(line?.unitPrice)
		const quantity = amountOf(line?.quantity ?? 1)
		if (description === '' || !(unitPrice > 0) || !(quantity > 0)) {
			continue
		}
		out.push({ description, quantity, unitPrice, vatRate: Number(line?.vatRate ?? 21) })
	}
	return out
}

/**
 * The request body for POST /api/ar-invoices/batch.
 *
 * @param {object} form The dialog's state.
 * @param {string} form.administrationId The active administration.
 * @param {string} form.batchId The batch reference.
 * @param {Array<{value: string}|string>} form.customers The selected customers.
 * @param {Array<object>} form.lines The lines as typed.
 * @param {string} form.invoiceDate The invoice date (YYYY-MM-DD).
 * @param {string} form.dueDate The due date, '' for each customer's own term.
 * @param {boolean} form.issue Whether the invoices are issued.
 * @param {boolean} form.send Whether the issued invoices are sent.
 * @return {object} The body.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function buildBatchRequest(form) {
	const customerIds = (form.customers || [])
		.map((customer) => (typeof customer === 'string' ? customer : customer?.value))
		.filter((id) => typeof id === 'string' && id !== '')
	const charge = { invoiceDate: form.invoiceDate, lines: pricedLines(form.lines) }
	if (form.dueDate) {
		charge.dueDate = form.dueDate
	}
	const issue = form.issue === true
	return {
		administrationId: form.administrationId || '',
		batchId: form.batchId,
		customerIds,
		charge,
		issue,
		send: issue && form.send === true,
	}
}

/**
 * Why the form cannot be submitted yet, or '' when it can.
 *
 * @param {object} body The request body from buildBatchRequest().
 * @param {function(string, string): string} t The translator.
 * @return {string} The reason.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function batchRequestProblem(body, t) {
	return customerProblem(body, t) || chargeProblem(body, t)
}

/**
 * What is wrong with the request's customers, or ''.
 *
 * @param {object} body The request body.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string}
 */
function customerProblem(body, t) {
	if (body.customerIds.length === 0) {
		return t('shillinq', 'Select at least one customer.')
	}
	if (body.customerIds.length > MAX_BATCH_CUSTOMERS) {
		return t('shillinq', 'A batch bills at most 200 customers.')
	}
	return ''
}

/**
 * What is wrong with the request's charge, or ''.
 *
 * @param {object} body The request body.
 * @param {(app: string, text: string, vars?: object) => string} t The translate function.
 * @return {string}
 */
function chargeProblem(body, t) {
	if (!/^\d{4}-\d{2}-\d{2}$/.test(String(body.charge.invoiceDate || ''))) {
		return t('shillinq', 'Enter a valid invoice date.')
	}
	if (body.charge.dueDate && body.charge.dueDate < body.charge.invoiceDate) {
		return t('shillinq', 'The due date is a date on or after the invoice date.')
	}
	if (body.charge.lines.length === 0) {
		return t('shillinq', 'Add at least one line with a description and a price.')
	}
	return ''
}

/**
 * How many customers ended in each outcome.
 *
 * @param {object} batch The InvoiceBatch the server answered.
 * @return {{created: number, existing: number, skipped: number, failed: number}} The counts.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export function batchOutcomeCounts(batch) {
	const counts = { created: 0, existing: 0, skipped: 0, failed: 0 }
	for (const outcome of Array.isArray(batch?.outcomes) ? batch.outcomes : []) {
		if (Object.hasOwn(counts, String(outcome?.status))) {
			counts[outcome.status]++
		}
	}
	return counts
}

/**
 * Post the batch and answer the stored InvoiceBatch.
 *
 * @param {object} body The request body from buildBatchRequest().
 * @return {Promise<object>} The batch with one outcome per customer.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */
export async function createInvoiceBatch(body) {
	const response = await axios.post(generateUrl(createBatchEndpoint()), body)
	return response.data
}
