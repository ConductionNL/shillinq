// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-object-request-refund-and-credit 3.2: the row actions of the
// "Refunds to pay" page. CnIndexPage resolves a named row handler against the
// `customComponents` map main.js hands the app (spread from manifestActions)
// and calls it with `{ actionId, item }`.
//
// Both steps run against ObjectRequestRefundController, which refuses a
// caller without `payment.administer` before it books anything. The page
// shows that refusal as the server words it.

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import RefundPaidModal from '../modals/RefundPaidModal.vue'
import { refundError, refundStepUrl } from './refundApi.js'

/**
 * The state of the request's newest refund, the one the server acts on.
 *
 * @param {object} request The payment request.
 * @return {string} The refund state, or '' when it has none.
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
 */
export function newestRefundState(request) {
	const refunds = Array.isArray(request?.refunds) ? request.refunds : []
	if (refunds.length === 0) {
		return ''
	}
	return String(refunds[refunds.length - 1]?.state ?? '')
}

/**
 * Row action: approve the refund, so the income moves to refunds payable.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<boolean>} Whether the refund was approved.
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
 */
export async function approveRefund(scope) {
	const request = scope?.item
	if (!request?.id) {
		return false
	}
	if (newestRefundState(request) !== 'requested') {
		showError(t('shillinq', 'This refund is already approved.'))
		return false
	}
	try {
		await axios.post(refundStepUrl(request.id, 'approve'))
		showSuccess(
			t('shillinq', 'Refund approved. Pay it by bank, then mark it paid.'),
		)
		return true
	} catch (error) {
		showError(
			refundError(error, t('shillinq', 'The refund could not be approved.')),
		)
		return false
	}
}

/**
 * Row action: record the bank payment of an approved refund.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
 */
export function openRefundPaid(scope) {
	const request = scope?.item
	if (!request?.id) {
		return undefined
	}
	if (newestRefundState(request) !== 'approved') {
		showError(t('shillinq', 'Approve this refund before you mark it paid.'))
		return undefined
	}
	return spawnDialog(RefundPaidModal, { paymentRequest: request })
}
