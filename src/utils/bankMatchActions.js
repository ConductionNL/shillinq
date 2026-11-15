// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-manual-match: named handlers for the manifest's "Match by hand" row
// actions and the unmatched items bulk classification. CnIndexPage resolves a
// named row or bulk handler against `customComponents` (src/main.js) and calls
// it with `{ actionId, item }` for a row or `{ actionId, selectedIds, count }`
// for a selection. Each handler opens its dialog with spawnDialog.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import BankLineMatchModal from '../modals/BankLineMatchModal.vue'
import UnmatchedClassifyDialog from '../modals/UnmatchedClassifyDialog.vue'

/**
 * Bulk action id to resolution status.
 *
 * @type {Record<string, string>}
 */
export const CLASSIFY_ACTIONS = {
	'bulk-classify-timing': 'timing',
	'bulk-classify-pending': 'pending',
	'bulk-classify-adjustment': 'adjustment',
}

/**
 * The line a row stands for: a BankStatementLine row is the line itself, a
 * ReconciliationMatch row names it in `bankLineId` or `bankStatementLineId`.
 *
 * @param {object} row The row.
 * @return {string} The line's uuid or business key, or ''.
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
export function lineIdOfRow(row) {
	if (!row) {
		return ''
	}
	return String(
		row.bankStatementLineId
			|| row.bankLineId
			|| (row.statementId ? row.id : '')
			|| '',
	)
}

/**
 * Row action: open the manual match dialog for the row's bank line.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
export function openBankLineMatch(scope) {
	const lineId = lineIdOfRow(scope?.item)
	if (lineId === '') {
		return undefined
	}
	return spawnDialog(BankLineMatchModal, { lineId })
}

/**
 * Bulk action: classify the selection with one reason.
 *
 * @param {{actionId: string, selectedIds: Array<string>}} scope The selection scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export function classifyUnmatched(scope) {
	const resolutionStatus = CLASSIFY_ACTIONS[scope?.actionId]
	if (
		!resolutionStatus
		|| !Array.isArray(scope?.selectedIds)
		|| scope.selectedIds.length === 0
	) {
		return undefined
	}
	return spawnDialog(UnmatchedClassifyDialog, {
		selectedIds: scope.selectedIds,
		resolutionStatus,
	})
}
