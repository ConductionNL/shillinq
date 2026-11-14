// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-invoice-sending 3.2: the "Send selected" bulk action on Accounts
// Receivable. The selection goes to POST /api/ar-invoices/send-batch in the
// active administration; a queued job sends each invoice by its customer's
// delivery method and the bookkeeper is notified when the batch is done.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { fetchAdministrationContext } from '../api/administrationApi.js'

/**
 * The most invoices one batch sends (InvoiceBatchSendService::MAX_INVOICES).
 */
export const MAX_BATCH_INVOICES = 200

/**
 * The send-batch endpoint.
 *
 * @return {string} The app-relative path.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.2
 */
export function sendBatchEndpoint() {
	return '/apps/shillinq/api/ar-invoices/send-batch'
}

/**
 * Bulk action "Send selected" on Accounts Receivable.
 *
 * @param {{selectedIds: Array<string>}} scope The selection scope.
 * @return {Promise<object|undefined>} The queued batch, or undefined when nothing was queued.
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.2
 */
export async function sendSelectedInvoices(scope) {
	const ids = Array.isArray(scope?.selectedIds) ? scope.selectedIds.filter((id) => typeof id === 'string' && id !== '') : []
	if (ids.length === 0) {
		return undefined
	}
	if (ids.length > MAX_BATCH_INVOICES) {
		showError(t('shillinq', 'Select at most 200 invoices to send at once.'))
		return undefined
	}
	try {
		const context = await fetchAdministrationContext()
		const response = await axios.post(generateUrl(sendBatchEndpoint()), {
			administrationId: context?.activeAdministrationId || '',
			invoiceIds: ids,
		})
		showSuccess(t('shillinq', '{count} invoices are being sent. You get a notification when the batch is done.', { count: ids.length }))
		return response.data
	} catch (error) {
		showError(error?.response?.data?.error || t('shillinq', 'The invoices could not be queued for sending.'))
		return undefined
	}
}
