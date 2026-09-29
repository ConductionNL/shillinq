// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-payment-run: the requests behind "Propose payment run" and the
// "Block payment" / "Release payment" actions. The proposal goes to
// PaymentRunController::propose; a block is a PATCH of two fields on the
// APTransaction or Payee through OpenRegister, so the object's audit trail
// records who set it and when.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/**
 * The schemas a payment block can be set on.
 *
 * @type {Array<string>}
 */
export const BLOCKABLE_SCHEMAS = ['APTransaction', 'Payee']

/**
 * The body PaymentRunController::propose reads.
 *
 * @param {object} form The dialog's values.
 * @return {object} The request body.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
 */
export function buildProposalRequest(form) {
	return {
		administrationId: String(form.administrationId || ''),
		dueOnOrBefore: String(form.dueOnOrBefore || ''),
		debtorAccountIban: String(form.debtorAccountIban || '').replace(/\s+/g, '').toUpperCase(),
		executionDate: String(form.executionDate || ''),
		payOnDueDate: Boolean(form.payOnDueDate),
	}
}

/**
 * Ask the server for a draft run.
 *
 * Resolves with `{ paymentRun, skipped }`; a 422 (nothing due) resolves
 * too, with `paymentRun: null`, so the dialog can list what was left out.
 *
 * @param {object} form The dialog's values.
 * @return {Promise<object>} The proposal result.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
 */
export async function proposePaymentRun(form) {
	try {
		const response = await axios.post(
			generateUrl('/apps/shillinq/api/v1/payment-runs/propose'),
			buildProposalRequest(form),
		)
		return response.data
	} catch (error) {
		if (error?.response?.status === 422 && error.response.data) {
			return error.response.data
		}
		throw error
	}
}

/**
 * The patch that blocks payment, refusing an empty reason.
 *
 * @param {string} reason Why payment is blocked.
 * @return {object} The fields to patch.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.2
 */
export function blockPayload(reason) {
	const trimmed = String(reason || '').trim()
	if (trimmed === '') {
		throw new Error(t('shillinq', 'Give a reason for the payment block.'))
	}
	return { paymentBlocked: true, paymentBlockReason: trimmed }
}

/**
 * The patch that releases a payment block.
 *
 * @return {object} The fields to patch.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.2
 */
export function releasePayload() {
	return { paymentBlocked: false, paymentBlockReason: null }
}

/**
 * Patch the block fields of an invoice or a supplier.
 *
 * @param {string} schema APTransaction or Payee.
 * @param {string} id The object's id.
 * @param {object} payload The fields to patch.
 * @return {Promise<object>} The patched object.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-2.2
 */
export async function setPaymentBlock(schema, id, payload) {
	if (!BLOCKABLE_SCHEMAS.includes(schema) || !id) {
		throw new Error(t('shillinq', 'This record cannot be blocked from payment.'))
	}
	const response = await axios.patch(
		generateUrl(
			`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}/${encodeURIComponent(id)}`,
		),
		payload,
	)
	return response.data
}

/**
 * The sentence for why the proposal left an invoice out.
 *
 * @param {object} skip One entry of `skipped`.
 * @return {string} The translated reason.
 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
 */
export function skipReasonText(skip) {
	const detail = String(skip?.detail || '')
	switch (skip?.reason) {
	case 'invoice-blocked':
		return detail
			? t('shillinq', 'Invoice blocked: {detail}', { detail })
			: t('shillinq', 'Invoice blocked')
	case 'payee-blocked':
		return detail
			? t('shillinq', 'Supplier blocked: {detail}', { detail })
			: t('shillinq', 'Supplier blocked')
	case 'invoice-disputed':
		return t('shillinq', 'Invoice disputed')
	case 'already-on-run':
		return t('shillinq', 'Already on a payment run')
	case 'no-iban':
		return t('shillinq', 'Supplier has no IBAN')
	case 'not-euro':
		return t('shillinq', 'Not in euro')
	default:
		return t('shillinq', 'Not found')
	}
}
