// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ledger-open-item-clearing: the named handlers behind the open items actions.
// Clear on a suggested pair and Clear selected on Lines to clear post the line
// ids to the clearing endpoint; Undo clearing on a group posts to its undo
// endpoint. Nothing is cleared without a person pressing one of them.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The message an endpoint answered with, or a fallback.
 *
 * @param {Error} error The request error.
 * @param {string} fallback The text when the endpoint said nothing.
 * @return {string} The text.
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
 */
export function clearingError(error, fallback) {
	return error?.response?.data?.message || fallback
}

/**
 * Clear lines into one group.
 *
 * @param {string[]} lineIds The GLLine ids.
 * @return {Promise<boolean>} Whether the lines were cleared.
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
 */
export async function clearLines(lineIds) {
	try {
		await axios.post(generateUrl('/apps/shillinq/api/v1/open-items/clear'), { lineIds })
		showSuccess(t('shillinq', 'Lines cleared.'))
		emit('cn:page:refresh', {})
		return true
	} catch (e) {
		showError(clearingError(e, t('shillinq', 'The lines could not be cleared.')))
		return false
	}
}

/**
 * Row action: clear a suggested pair.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<boolean>} Whether the pair was cleared.
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-004)
 */
export function clearOpenItemSuggestion(scope) {
	const lineIds = scope?.item?.lineIds
	if (!Array.isArray(lineIds) || lineIds.length < 2) {
		return Promise.resolve(false)
	}
	return clearLines(lineIds)
}

/**
 * Bulk action: clear the selected lines.
 *
 * @param {{selectedIds: string[]}} scope The selection scope.
 * @return {Promise<boolean>} Whether the lines were cleared.
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
 */
export function clearSelectedOpenItems(scope) {
	const lineIds = scope?.selectedIds
	if (!Array.isArray(lineIds) || lineIds.length < 2) {
		showError(t('shillinq', 'Select at least two lines to clear.'))
		return Promise.resolve(false)
	}
	return clearLines(lineIds)
}

/**
 * Row action: undo a clearing, opening its lines again.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<boolean>} Whether the clearing was undone.
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md (REQ-LOIC-002)
 */
export async function undoOpenItemClearing(scope) {
	const groupId = scope?.item?.id
	if (!groupId) {
		return false
	}
	try {
		await axios.post(generateUrl('/apps/shillinq/api/v1/open-items/groups/{groupId}/undo', { groupId }))
		showSuccess(t('shillinq', 'Clearing undone.'))
		emit('cn:page:refresh', {})
		return true
	} catch (e) {
		showError(clearingError(e, t('shillinq', 'The clearing could not be undone.')))
		return false
	}
}
