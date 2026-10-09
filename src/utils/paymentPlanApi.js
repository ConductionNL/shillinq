// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-payment-plans: the requests behind Agree a payment plan, the
// plan's Activate, Record a payment and Cancel actions, and the Payment plan
// tab of Match by hand. Every write goes to PaymentPlanController.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'
const BASE = '/apps/shillinq/api/v1/payment-plans'

/**
 * Whether an invoice can go on a plan: overdue, or issued and past due, with
 * an amount due.
 *
 * @param {object} invoice An ARInvoice.
 * @param {string} today Today as YYYY-MM-DD.
 * @return {boolean} Whether it is eligible.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export function isPlannable(invoice, today) {
	const state = String(invoice?.lifecycleState || '')
	const overdue =
		state === 'overdue'
		|| (state === 'issued' && String(invoice?.dueDate || '') < today)
	return overdue && Number(invoice?.amountDue ?? 0) > 0
}

/**
 * The customer's invoices that can go on a plan, oldest first.
 *
 * @param {string} customerId The customer's id.
 * @param {string} today Today as YYYY-MM-DD.
 * @return {Promise<Array<object>>} The invoices.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export async function loadPlannableInvoices(customerId, today) {
	const response = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/ARInvoice`),
		{ params: { customerId, _limit: 500 } },
	)
	const rows = response.data?.results ?? response.data ?? []
	return rows
		.filter((invoice) => isPlannable(invoice, today))
		.sort((a, b) =>
			String(a.dueDate || '').localeCompare(String(b.dueDate || '')),
		)
}

/**
 * Read one invoice (to find its customer).
 *
 * @param {string} id The invoice id.
 * @return {Promise<object>} The invoice.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export async function loadInvoice(id) {
	const response = await axios.get(
		generateUrl(
			`/apps/openregister/api/objects/${REGISTER_SLUG}/ARInvoice/${encodeURIComponent(id)}`,
		),
	)
	return response.data
}

/**
 * The body PaymentPlanController::create reads.
 *
 * @param {object} form The dialog's values.
 * @return {object} The request body.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export function buildPlanRequest(form) {
	const byAmount = form.mode === 'amount'
	return {
		administrationId: String(form.administrationId || ''),
		customerId: String(form.customerId || ''),
		invoiceIds: [...(form.invoiceIds || [])],
		instalmentCount: byAmount ? null : Number(form.instalmentCount || 0),
		instalmentAmount: byAmount ? Number(form.instalmentAmount || 0) : null,
		frequency: form.frequency === 'weekly' ? 'weekly' : 'monthly',
		firstDueDate: String(form.firstDueDate || ''),
		graceDays: Number(form.graceDays ?? 14),
		includesCharges: Boolean(form.includesCharges),
		chargesAmount: form.includesCharges ? Number(form.chargesAmount || 0) : 0,
		agreedWith: String(form.agreedWith || ''),
		note: String(form.note || ''),
	}
}

/**
 * Draw up a plan in draft.
 *
 * @param {object} form The dialog's values.
 * @return {Promise<object>} `{ plan, instalments }`.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export async function draftPlan(form) {
	const response = await axios.post(generateUrl(BASE), buildPlanRequest(form))
	return response.data
}

/**
 * Activate a drafted plan: dunning pauses and the schedule mailed.
 *
 * @param {string} planId The plan id.
 * @return {Promise<object>} The plan.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export async function activatePlan(planId) {
	const response = await axios.post(
		generateUrl(`${BASE}/${encodeURIComponent(planId)}/activate`),
	)
	return response.data
}

/**
 * Record a payment by hand.
 *
 * @param {string} planId The plan id.
 * @param {object} payment `{ amount, paidDate, reference }`.
 * @return {Promise<object>} The allocation with the plan.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
 */
export async function settlePlan(planId, payment) {
	const response = await axios.post(
		generateUrl(`${BASE}/${encodeURIComponent(planId)}/settle`),
		{
			amount: Number(payment.amount || 0),
			paidDate: String(payment.paidDate || ''),
			reference: String(payment.reference || ''),
		},
	)
	return response.data
}

/**
 * Cancel a plan; its dunning pauses resume.
 *
 * @param {string} planId The plan id.
 * @param {string} reason Why.
 * @return {Promise<object>} The plan.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */
export async function cancelPlan(planId, reason) {
	const response = await axios.post(
		generateUrl(`${BASE}/${encodeURIComponent(planId)}/cancel`),
		{ reason: String(reason || '') },
	)
	return response.data
}

/**
 * The plans a bank line can pay.
 *
 * @param {string} lineId The bank line id.
 * @return {Promise<Array<object>>} The candidates, best first.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
 */
export async function planCandidates(lineId) {
	const response = await axios.get(
		generateUrl(
			`/apps/shillinq/api/v1/bank-lines/${encodeURIComponent(lineId)}/payment-plans`,
		),
	)
	return response.data?.candidates ?? []
}

/**
 * Pay a plan from a bank line.
 *
 * @param {string} planId The plan id.
 * @param {string} lineId The bank line id.
 * @return {Promise<object>} The confirmed match.
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
 */
export async function payPlanFromLine(planId, lineId) {
	const response = await axios.post(
		generateUrl(`${BASE}/${encodeURIComponent(planId)}/bank-line`),
		{ lineId },
	)
	return response.data
}
