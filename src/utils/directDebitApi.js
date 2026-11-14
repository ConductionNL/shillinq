// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-sepa-direct-debit: the requests behind "Propose direct debit"
// on Direct debit batches and "Download pain.008" on a generated batch. The
// proposal goes to DirectDebitBatchController::propose; the file comes from
// DirectDebitBatchController::file, which checks the batch's administration.

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The body DirectDebitBatchController::propose reads.
 *
 * @param {object} form The dialog's values.
 * @return {{administrationId: string, collectionDate: string}} The request body.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export function buildProposalRequest(form) {
	return {
		administrationId: String(form?.administrationId || '').trim(),
		collectionDate: String(form?.collectionDate || '').trim(),
	}
}

/**
 * Ask the server for the draft batches of a collection date.
 *
 * Resolves with `{ batches, skipped }`; a 422 (nothing to collect) resolves
 * too, with no batches, so the dialog can list what was left out.
 *
 * @param {object} form The dialog's values.
 * @return {Promise<{batches: Array<object>, skipped: Array<object>}>} The proposal.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export async function proposeDirectDebitBatches(form) {
	try {
		const response = await axios.post(
			generateUrl('/apps/shillinq/api/v1/direct-debit-batches/propose'),
			buildProposalRequest(form),
		)
		return response.data
	} catch (error) {
		if (error?.response?.status === 422 && error.response.data) {
			return { batches: [], skipped: [], ...error.response.data }
		}
		throw error
	}
}

/**
 * The total of the proposed batches, rounded to cents.
 *
 * @param {Array<object>} batches The proposed batches.
 * @return {number} The sum of their control sums.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export function proposalTotal(batches) {
	const cents = (batches || []).reduce(
		(sum, batch) => sum + Math.round(Number(batch?.controlSum || 0) * 100),
		0,
	)
	return cents / 100
}

/**
 * The sentence for why the proposal left an invoice out.
 *
 * @param {object} skip One entry of `skipped`.
 * @return {string} The translated reason.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export function skipReasonText(skip) {
	const detail = String(skip?.detail || '')
	switch (skip?.reason) {
		case 'no-mandate':
			return t('shillinq', 'No direct debit mandate')
		case 'mandate-inactive':
			return detail
				? t('shillinq', 'The mandate is not active ({status})', { status: detail })
				: t('shillinq', 'The mandate is not active')
		case 'mandate-ended':
			return t('shillinq', 'The mandate ended on {date}', { date: detail })
		case 'already-collected':
			return t('shillinq', 'Already on a collection')
		case 'not-euro':
			return t('shillinq', 'Not in euro')
		case 'nothing-due':
			return t('shillinq', 'Nothing left to collect')
		default:
			return t('shillinq', 'Left out')
	}
}

/**
 * The batch id a header action runs on: the page's record, else the last path segment.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {string} The id.
 */
function batchId(scope) {
	return String(scope?.item?.id || window.location.pathname.split('/').pop() || '')
}

/**
 * Hand the file to the browser.
 *
 * @param {string} content The XML.
 * @param {string} fileName The file name.
 * @return {void}
 */
function saveFile(content, fileName) {
	const url = URL.createObjectURL(new Blob([content], { type: 'text/xml' }))
	const link = document.createElement('a')
	link.href = url
	link.download = fileName
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

/**
 * The file name the server put in Content-Disposition, else one from the batch.
 *
 * @param {object} headers The response headers.
 * @param {object|undefined} item The batch.
 * @return {string} The file name.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export function downloadFileName(headers, item) {
	const disposition = String(headers?.['content-disposition'] || '')
	const match = disposition.match(/filename="?([^";]+)"?/)
	if (match) {
		return match[1]
	}
	return String(item?.messageId || 'direct-debit-batch') + '.pain008.xml'
}

/**
 * Header action on a generated batch: download its pain.008 file.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<string|null>} The file name, or null when it failed.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export async function downloadDirectDebitFile(scope) {
	const id = batchId(scope)
	if (!id) {
		return null
	}
	try {
		const response = await axios.get(
			generateUrl('/apps/shillinq/api/v1/direct-debit-batches/{id}/file', { id }),
			{ responseType: 'text' },
		)
		const fileName = downloadFileName(response.headers, scope?.item)
		saveFile(String(response.data), fileName)
		return fileName
	} catch (error) {
		const status = error?.response?.status
		showError(
			status === 409
				? t('shillinq', 'Generate the pain.008 file before downloading it.')
				: t('shillinq', 'The pain.008 file could not be downloaded.'),
		)
		return null
	}
}
