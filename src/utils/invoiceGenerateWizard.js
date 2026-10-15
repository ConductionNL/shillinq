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

const REGISTER_SLUG = 'shillinq'

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
 * Rows out of an OpenRegister list response.
 *
 * @param {object} response The axios response.
 * @return {Array<object>} The rows.
 */
function rowsOf(response) {
	const rows =
		response?.data?.results ?? response?.data?.objects ?? response?.data ?? []
	return Array.isArray(rows) ? rows : []
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
 * Whether an ISO date lies in the period, both ends included. An open end
 * does not limit.
 *
 * @param {string} date The date (YYYY-MM-DD, a longer ISO string is cut).
 * @param {string} fromDate The first day, or ''.
 * @param {string} toDate The last day, or ''.
 * @return {boolean} True when it does.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function inPeriod(date, fromDate, toDate) {
	const day = String(date || '').slice(0, 10)
	if (day === '') {
		return false
	}
	return (!fromDate || day >= fromDate) && (!toDate || day <= toDate)
}

/**
 * The time entries of a project in the period.
 *
 * @param {Array<object>} entries UrenRegistratie objects.
 * @param {string} projectId The project, or '' for every project.
 * @param {string} fromDate The first day.
 * @param {string} toDate The last day.
 * @return {Array<object>} The entries, oldest first.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function timeEntriesFor(entries, projectId, fromDate, toDate) {
	return entries
		.filter((entry) => !projectId || String(entry.projectId || '') === projectId)
		.filter((entry) => inPeriod(entry.date, fromDate, toDate))
		.sort((a, b) => String(a.date).localeCompare(String(b.date)))
}

/**
 * The expense claims passed on to a customer, submitted in the period.
 *
 * @param {Array<object>} claims ExpenseClaimEntry objects.
 * @param {string} customerId The customer.
 * @param {string} fromDate The first day.
 * @param {string} toDate The last day.
 * @return {Array<object>} The claims.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export function passThroughClaimsFor(claims, customerId, fromDate, toDate) {
	return claims.filter((claim) => {
		const customers = Array.isArray(claim.passThroughCustomerIds)
			? claim.passThroughCustomerIds.map(String)
			: []
		return (
			customers.includes(customerId)
			&& inPeriod(
				claim.submittedDate || claim.toDate || claim.fromDate,
				fromDate,
				toDate,
			)
		)
	})
}

/**
 * Load what the lines step offers: the time entries, the passed-on expense
 * claims and, for the usage model, the rated meter readings.
 *
 * @param {object} source The source step: customerId, projectId, fromDate, toDate, billingModel.
 * @return {Promise<{timeEntries: Array<object>, expenses: Array<object>, readings: Array<object>}>} The candidates.
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */
export async function loadCandidates(source) {
	const { customerId, projectId, fromDate, toDate, billingModel } = source
	const list = (schema, params) =>
		axios.get(
			generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}`),
			{ params: { _limit: 500, ...params } },
		)
	const [entries, claims, readings] = await Promise.all([
		list('UrenRegistratie', projectId ? { projectId } : {}),
		list('ExpenseClaimEntry', {}),
		billingModel === 'usage'
			? loadBillableReadings(customerId, fromDate, toDate)
			: Promise.resolve([]),
	])
	return {
		timeEntries: timeEntriesFor(rowsOf(entries), projectId, fromDate, toDate),
		expenses: passThroughClaimsFor(rowsOf(claims), customerId, fromDate, toDate),
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
