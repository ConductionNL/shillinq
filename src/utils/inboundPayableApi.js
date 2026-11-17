// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbound-payable-from-another-app T2.1: the calls behind the Payouts to pay
// row actions. Each step posts to InboundPayableController, which checks the
// payment.administer action and answers the payable or a refusal.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The step a row action takes, keyed by its id, with the route segment it posts to.
 *
 * @type {Record<string, string>}
 */
export const PAYABLE_STEPS = Object.freeze({
	approve: 'approve',
	paid: 'paid',
	reject: 'reject',
})

/**
 * The body one step sends, refusing what the server would refuse anyway, so
 * the dialog can say so before posting.
 *
 * @param {string} step `approve`, `paid` or `reject`.
 * @param {object} [fields] The dialog's fields.
 * @param {string} [fields.bankReference] The bank payment's reference (paid).
 * @param {string} [fields.bankAccount] The ledger account of the bank (paid).
 * @param {string} [fields.paidOn] The date it left the bank, YYYY-MM-DD, or '' for today (paid).
 * @param {string} [fields.reason] Why it is rejected (reject).
 * @return {object} The request body.
 * @throws {Error} When a step's required value is missing or malformed.
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */
export function stepBody(step, fields = {}) {
	if (step === 'approve') {
		return {}
	}
	if (step === 'paid') {
		const bankReference = String(fields.bankReference || '').trim()
		const bankAccount = String(fields.bankAccount || '').trim()
		const paidOn = String(fields.paidOn || '').trim()
		if (bankReference === '' || bankAccount === '') {
			throw new Error('missing-bank')
		}
		if (paidOn !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(paidOn)) {
			throw new Error('bad-date')
		}
		return { bankReference, bankAccount, paidOn }
	}
	if (step === 'reject') {
		const reason = String(fields.reason || '').trim()
		if (reason === '') {
			throw new Error('missing-reason')
		}
		return { reason }
	}
	throw new Error('unknown-step')
}

/**
 * Take one step on a payable.
 *
 * @param {string} payableId The payable's id.
 * @param {string} step `approve`, `paid` or `reject`.
 * @param {object} body The body from stepBody().
 * @return {Promise<object>} The payable as the server answered it.
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */
export async function takePayableStep(payableId, step, body) {
	const segment = PAYABLE_STEPS[step]
	if (!segment || String(payableId || '') === '') {
		throw new Error('unknown-step')
	}
	const url = generateUrl('/apps/shillinq/api/inbound-payables/{id}/' + segment, {
		id: String(payableId),
	})
	const response = await axios.post(url, body)
	return response.data
}
