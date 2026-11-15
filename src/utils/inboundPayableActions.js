// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbound-payable-from-another-app T2.1: named handlers for the three row
// actions on Payouts to pay. CnIndexPage calls a row handler with
// `{ actionId, item }`; each opens InboundPayableStepDialog for its step.
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import InboundPayableStepDialog from '../modals/InboundPayableStepDialog.vue'

/**
 * Open the step dialog for the row's payable.
 *
 * @param {string} step `approve`, `paid` or `reject`.
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload, or nothing without a row id.
 */
function openStep(step, scope) {
	const payableId = String(scope?.item?.id || scope?.item?.uuid || '')
	if (payableId === '') {
		return undefined
	}
	return spawnDialog(InboundPayableStepDialog, { payableId, step, payable: scope.item })
}

/**
 * Row action: approve a requested payout.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined}
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */
export function approvePayable(scope) {
	return openStep('approve', scope)
}

/**
 * Row action: mark an approved payout paid.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined}
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */
export function markPayablePaid(scope) {
	return openStep('paid', scope)
}

/**
 * Row action: reject a payout with a reason.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined}
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */
export function rejectPayable(scope) {
	return openStep('reject', scope)
}
