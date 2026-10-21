// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Generate invoices (board ShFactuurGenereren): the header action on Sales
// invoices opens a three-step dialog, Source, Choose lines and Review. The
// helpers here load the lines a customer can be billed for and build the
// request POST /api/v1/invoices/generate reads (InvoiceGenerationRequest).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import InvoiceGenerateWizard from '../modals/InvoiceGenerateWizard.vue'
import { loadBillableReadings } from './usageBilling.js'


/** Billing models InvoiceGenerationRequest accepts, in the order the select shows them. */
export const BILLING_MODELS = Object.freeze([
	't_and_m',
	'fixed_fee',
	'milestone',
	'retainer',
	'mixed',
	'usage',
])

/**
 * Header action: open the Generate invoices dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function openInvoiceGenerateWizard() {
	return spawnDialog(InvoiceGenerateWizard, {})
}

/**
 * An object's id, wherever OpenRegister put it.
 *
 * @param {object} row The object.
 * @return {string} The id.
 */
export function idOf(row) {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}

/**
 * Load what the lines step offers: the customer's time entries and passed-on
 * expense claims, read on the server for the caller's administration, and,
 * for the usage model, the rated meter readings.
 *
 * @param {object} source The source step: customerId, projectId, fromDate, toDate, billingModel.
 * @return {Promise<{timeEntries: Array<object>, expenses: Array<object>, readings: Array<object>}>} The candidates.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export async function loadCandidates(source) {
	const { customerId, projectId, fromDate, toDate, billingModel } = source
	const [lines, readings] = await Promise.all([
		axios.get(generateUrl('/apps/shillinq/api/v1/invoices/billable-lines'), {
			params: { customerId, projectId: projectId || '', fromDate, toDate },
		}),
		billingModel === 'usage'
			? loadBillableReadings(customerId, fromDate, toDate)
			: Promise.resolve([]),
	])
	return {
		timeEntries: Array.isArray(lines.data?.timeEntries) ? lines.data.timeEntries : [],
		expenses: Array.isArray(lines.data?.expenses) ? lines.data.expenses : [],
		readings,
	}
}

/**
 * The generation request for the chosen source and lines.
 *
 * @param {object} source The source step.
 * @param {object} chosen The ids chosen per kind: timeEntryIds, expenseIds, meterReadingIds.
 * @return {object} The request body InvoiceGenerationRequest::fromArray reads.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function buildGenerateRequest(source, chosen) {
	const request = {
		billingModel: source.billingModel,
		customerId: source.customerId,
		fromDate: source.fromDate,
		toDate: source.toDate,
		timeEntryIds: [...(chosen.timeEntryIds || [])],
		expenseIds: [...(chosen.expenseIds || [])],
		meterReadingIds:
			source.billingModel === 'usage'
				? [...(chosen.meterReadingIds || [])]
				: [],
	}
	for (const key of [
		'projectId',
		'rateCardId',
		'retainerScheduleId',
		'milestoneId',
	]) {
		if (source[key]) {
			request[key] = source[key]
		}
	}
	const fee = Number(String(source.fixedFee ?? '').replace(',', '.'))
	if (
		['fixed_fee', 'mixed'].includes(source.billingModel)
		&& Number.isFinite(fee)
		&& fee > 0
	) {
		request.fixedFeeCents = Math.round(fee * 100)
	}
	return request
}

/**
 * How many lines are chosen.
 *
 * @param {object} chosen The ids chosen per kind.
 * @return {number} The count.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function chosenCount(chosen) {
	return ['timeEntryIds', 'expenseIds', 'meterReadingIds'].reduce(
		(sum, key) => sum + (Array.isArray(chosen[key]) ? chosen[key].length : 0),
		0,
	)
}

/**
 * Draft the invoice on the server.
 *
 * @param {object} request The generation request.
 * @return {Promise<object>} The draft invoice with its summary.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export async function draftInvoice(request) {
	const { data } = await axios.post(
		generateUrl('/apps/shillinq/api/v1/invoices/generate'),
		request,
	)
	return data
}

/**
 * Post a drafted invoice to accounts receivable.
 *
 * @param {string} invoiceId The draft's id.
 * @return {Promise<object>} The server's answer.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export async function postInvoice(invoiceId) {
	const { data } = await axios.post(
		generateUrl(
			'/apps/shillinq/api/v1/invoices/'
				+ encodeURIComponent(invoiceId)
				+ '/post',
		),
	)
	return data
}

/**
 * The server's message out of a failed request, else the fallback.
 *
 * @param {unknown} error The axios error.
 * @param {string} fallback The text to show otherwise.
 * @return {string} The message.
 */
export function errorText(error, fallback) {
	const message = error?.response?.data?.message
	return typeof message === 'string' && message !== '' ? message : fallback
}
