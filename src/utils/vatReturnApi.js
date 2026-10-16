// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-return-from-books 2.3: the request behind "Prepare return" on BTW
// returns. VATReturnController::create prepares the return from the booked
// ledger lines of the period (VATReturnService::createReturn) and answers
// with the new BtwAangifte.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The period kinds the controller accepts.
 *
 * @type {Array<string>}
 */
export const PERIOD_KINDS = ['quarter', 'month', 'year']

/**
 * The last finished period of a kind: the quarter or month before the
 * current one, or last year.
 *
 * @param {string} kind quarter, month or year.
 * @param {Date} now Today.
 * @return {{periodYear: number, periodNumber: number}} The period.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export function lastFinishedPeriod(kind, now = new Date()) {
	const year = now.getFullYear()
	if (kind === 'year') {
		return { periodYear: year - 1, periodNumber: 1 }
	}
	const per =
		kind === 'month' ? now.getMonth() + 1 : Math.floor(now.getMonth() / 3) + 1
	if (per > 1) {
		return { periodYear: year, periodNumber: per - 1 }
	}
	return { periodYear: year - 1, periodNumber: kind === 'month' ? 12 : 4 }
}

/**
 * The body VATReturnController::create reads.
 *
 * @param {object} form The dialog's values.
 * @return {object} The request body.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export function buildPrepareRequest(form) {
	const period = PERIOD_KINDS.includes(form.period) ? form.period : 'quarter'
	return {
		administrationId: String(form.administrationId || ''),
		period,
		periodYear: Number(form.periodYear) || 0,
		periodNumber: period === 'year' ? 1 : Number(form.periodNumber) || 0,
		regime: String(form.regime || 'standard'),
	}
}

/**
 * The sentence for a refused or failed request.
 *
 * @param {object} error The axios error.
 * @return {string} The sentence.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export function prepareErrorText(error) {
	const status = error?.response?.status
	const code = String(error?.response?.data?.error || '')
	if (status === 400 && code.includes('future')) {
		return t(
			'shillinq',
			'That period has not ended yet. Prepare a return for a period that is over.',
		)
	}
	if (status === 400) {
		return t(
			'shillinq',
			'Choose a period kind, a year from 2020 and a period number that fits it.',
		)
	}
	if (status === 403 || status === 404) {
		return t('shillinq', 'You cannot prepare a return for this administration.')
	}
	return t(
		'shillinq',
		'The return could not be prepared. Try again, or ask your administrator to check the log.',
	)
}

/**
 * Prepare the return of a period.
 *
 * @param {object} form The dialog's values.
 * @return {Promise<object>} The prepared BtwAangifte.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export async function prepareVatReturn(form) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/vat-returns'),
		buildPrepareRequest(form),
	)
	return response.data?.data || {}
}

/**
 * Link to a return's page.
 *
 * @param {string} id The BtwAangifte id.
 * @return {string} The URL.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export function vatReturnUrl(id) {
	return generateUrl(
		'/apps/shillinq/vat-returns/' + encodeURIComponent(String(id || '')),
	)
}
