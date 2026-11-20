// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-payment-run: the request behind "Propose payment run", which goes
// to PaymentRunController::propose, and the sentences for its answer.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The body PaymentRunController::propose reads.
 *
 * @param {object} form The dialog's values.
 * @return {object} The request body.
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
 */
export function buildProposalRequest(form) {
	return {
		administrationId: String(form.administrationId || ''),
		dueOnOrBefore: String(form.dueOnOrBefore || ''),
		debtorAccountIban: String(form.debtorAccountIban || '')
			.replace(/\s+/g, '')
			.toUpperCase(),
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
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
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
 * The sentence for why the proposal left an invoice out.
 *
 * @param {object} skip One entry of `skipped`.
 * @return {string} The translated reason.
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
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
