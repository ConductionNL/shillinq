// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-quote-and-order-to-invoice 2.1: the "Create sales order" action on a
// quote and the "Invoice order" action on a sales order. CnPageRenderer calls a
// detail page's handler with `{ item }` when it has the object, else the id is
// the last path segment, as the other detail actions read it.
//
// Both steps run against SalesOrderController, which refuses a caller outside
// the object's administration (404) and a quote or order in the wrong state
// (422, in the caller's language) before it writes anything. The page shows
// that refusal as the server words it and opens what was written.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The id of the object the action runs on.
 *
 * @param {{item?: object}|undefined} scope The action scope.
 * @return {string} The id, or '' when there is none.
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 */
export function actionObjectId(scope) {
	return String(scope?.item?.id || window.location.pathname.split('/').pop() || '')
}

/**
 * The server's reason for a refused step, else the fallback.
 *
 * @param {unknown} error The axios error.
 * @param {string} fallback The text when the server gave none.
 * @return {string} The reason.
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 */
export function salesOrderError(error, fallback) {
	const data = error?.response?.data
	return String(data?.message || data?.error || fallback)
}

/**
 * Post one step and open what it wrote.
 *
 * @param {object} step The step.
 * @param {string} step.url The endpoint.
 * @param {string} step.done The success message.
 * @param {string} step.failed The fallback refusal.
 * @param {function(string): string} step.openUrl The page of the written object, from its id.
 * @return {Promise<object|null>} The written object, or null when refused.
 */
async function runStep(step) {
	try {
		const { data } = await axios.post(step.url)
		showSuccess(step.done)
		if (data?.id) {
			window.location.assign(step.openUrl(String(data.id)))
		}
		return data ?? null
	} catch (error) {
		showError(salesOrderError(error, step.failed))
		return null
	}
}

/**
 * Detail action: make a sales order from an accepted quote (REQ-QTI-001).
 *
 * @param {{item?: object}} scope The action scope.
 * @return {Promise<object|null>} The sales order, or null when none was made.
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-an-accepted-quote-becomes-a-sales-order-req-qti-001
 */
export async function createSalesOrderFromQuote(scope) {
	const id = actionObjectId(scope)
	if (!id) {
		return null
	}
	return runStep({
		url: generateUrl('/apps/shillinq/api/quotes/{id}/order', { id }),
		done: t('shillinq', 'Sales order created from the quote.'),
		failed: t('shillinq', 'The sales order could not be created.'),
		openUrl: (orderId) => generateUrl('/apps/shillinq/orders/{id}', { id: orderId }),
	})
}

/**
 * Detail action: write a draft invoice for what a sales order has not invoiced yet (REQ-QTI-003).
 *
 * @param {{item?: object}} scope The action scope.
 * @return {Promise<object|null>} The draft invoice, or null when none was written.
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md#requirement-a-confirmed-sales-order-becomes-a-draft-invoice-req-qti-003
 */
export async function invoiceSalesOrder(scope) {
	const id = actionObjectId(scope)
	if (!id) {
		return null
	}
	return runStep({
		url: generateUrl('/apps/shillinq/api/orders/{id}/invoice', { id }),
		done: t('shillinq', 'Draft invoice written for the order.'),
		failed: t('shillinq', 'The order could not be invoiced.'),
		openUrl: (invoiceId) => generateUrl('/apps/shillinq/bookkeeping/accounts-receivable/{id}', { id: invoiceId }),
	})
}
