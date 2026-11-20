// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-icp-and-reverse-charge 2.3: the requests behind the ICP page.
// IcpStatementController prepares the statement of a period from the books
// (REQ-ICR-002), serves its XBRL instance and files it through the Digipoort
// hand-off when one is bound (REQ-ICR-003); IcpController::reconcile compares
// it with box 3b of the VAT return.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The ICP period kinds: a statement is quarterly or monthly.
 *
 * @type {Array<string>}
 */
export const ICP_PERIOD_KINDS = ['quarter', 'month']

/**
 * A period as the server reads it: 2026-Q3 or 2026-07, or '' when it is incomplete.
 *
 * @param {string} kind quarter or month.
 * @param {number|string} year The year.
 * @param {number|string} number The quarter (1 to 4) or month (1 to 12).
 * @return {string} The period.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function icpPeriod(kind, year, number) {
	const y = Number(year)
	const n = Number(number)
	if (!Number.isInteger(y) || y < 2000 || y > 9999 || !Number.isInteger(n)) {
		return ''
	}
	if (kind === 'month') {
		return n >= 1 && n <= 12 ? y + '-' + String(n).padStart(2, '0') : ''
	}
	return n >= 1 && n <= 4 ? y + '-Q' + n : ''
}

/**
 * The last finished quarter or month before today.
 *
 * @param {string} kind quarter or month.
 * @param {Date} now Today.
 * @return {{year: number, number: number}} The period.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function lastFinishedIcpPeriod(kind, now = new Date()) {
	const year = now.getFullYear()
	const current =
		kind === 'month' ? now.getMonth() + 1 : Math.floor(now.getMonth() / 3) + 1
	if (current > 1) {
		return { year, number: current - 1 }
	}
	return { year: year - 1, number: kind === 'month' ? 12 : 4 }
}

/**
 * An amount as EUR with two decimals.
 *
 * @param {number|string} amount The amount.
 * @return {string} The text.
 */
function euro(amount) {
	return 'EUR ' + (Number(amount) || 0).toFixed(2)
}

/**
 * The reconciliation of a statement with box 3b, in words.
 *
 * @param {{icpTotal: number, rubriek3b: number|null, matches: boolean, missing: boolean, difference: number}} outcome The outcome.
 * @return {{kind: string, text: string}} success when it reconciles, error when it does not, warning without a return.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function reconciliationMessage(outcome) {
	if (!outcome || outcome.missing || outcome.rubriek3b === null) {
		return {
			kind: 'warning',
			text: t(
				'shillinq',
				'There is no VAT return for this period yet, so box 3b cannot be compared. Prepare the VAT return first.',
			),
		}
	}
	const values = {
		total: euro(outcome.icpTotal),
		box: euro(outcome.rubriek3b),
		difference: euro(outcome.difference),
	}
	if (outcome.matches) {
		return {
			kind: 'success',
			text: t(
				'shillinq',
				'The statement total {total} matches box 3b ({box}) within EUR 1; the difference is {difference}.',
				values,
			),
		}
	}
	return {
		kind: 'error',
		text: t(
			'shillinq',
			'The statement total {total} differs from box 3b ({box}) by {difference}. It cannot be finalised until they match within EUR 1.',
			values,
		),
	}
}

/**
 * The sentence for a refused or failed request.
 *
 * @param {object} error The axios error.
 * @return {string} The sentence.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function icpErrorText(error) {
	const status = error?.response?.status
	const data = error?.response?.data || {}
	if (status === 409 && data.error === 'statement-filed') {
		return t(
			'shillinq',
			'The statement of this period has been filed and is not replaced. File a correction instead.',
		)
	}
	if (status === 409 && data.message) {
		return String(data.message)
	}
	if (status === 422 && data.message) {
		return String(data.message)
	}
	if (status === 400) {
		return t('shillinq', 'Choose a quarter or a month and a year.')
	}
	if (status === 403 || status === 404) {
		return t('shillinq', 'You cannot work on the ICP statement of this administration.')
	}
	return t(
		'shillinq',
		'The ICP statement could not be processed. Try again, or ask your administrator to check the log.',
	)
}

/**
 * Prepare the statement of a period.
 *
 * @param {{administrationId: string, period: string}} form The administration and period.
 * @return {Promise<{statement: object, reconciliation: object, replaced: boolean}>} What was prepared.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export async function prepareIcpStatement(form) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/icp/statements/prepare'),
		{
			period: String(form.period || ''),
			administration_id: String(form.administrationId || ''),
		},
	)
	return response.data || {}
}

/**
 * The reconciliation of a statement's period with box 3b.
 *
 * @param {{administrationId: string, period: string}} statement The statement.
 * @return {Promise<object>} The outcome.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export async function reconcileIcpPeriod(statement) {
	const response = await axios.get(generateUrl('/apps/shillinq/api/icp/reconcile'), {
		params: {
			period_id: String(statement.period || ''),
			administration_id: String(statement.administrationId || ''),
		},
	})
	return response.data || {}
}

/**
 * The address of a statement's XBRL instance.
 *
 * @param {{administrationId: string, period: string}} statement The statement.
 * @return {string} The URL.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function icpStatementXmlUrl(statement) {
	return (
		generateUrl('/apps/shillinq/api/icp/statements/xml')
		+ '?period='
		+ encodeURIComponent(String(statement.period || ''))
		+ '&administration_id='
		+ encodeURIComponent(String(statement.administrationId || ''))
	)
}

/**
 * Whether filing through Digipoort is available.
 *
 * @return {Promise<boolean>} False while no Digipoort hand-off is bound, or when it cannot be asked.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */
export async function icpFilingAvailable() {
	try {
		const response = await axios.get(
			generateUrl('/apps/shillinq/api/icp/statements/filing'),
		)
		return response.data?.available === true
	} catch {
		return false
	}
}

/**
 * File a statement.
 *
 * @param {{administrationId: string, period: string}} statement The statement.
 * @return {Promise<{status: string, deliveryStatus: string, reference: string}>} Digipoort's answer.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */
export async function fileIcpStatementRequest(statement) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/icp/statements/file'),
		{
			period: String(statement.period || ''),
			administration_id: String(statement.administrationId || ''),
		},
	)
	return response.data || {}
}

/**
 * The sentence for Digipoort's answer to a filing.
 *
 * @param {{status: string, reference: string}} result The answer.
 * @return {{kind: string, text: string}} The toast kind and text.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */
export function filingMessage(result) {
	if (result?.status === 'submitted') {
		return {
			kind: 'success',
			text: t('shillinq', 'The statement was submitted to Digipoort (reference {reference}).', {
				reference: String(result.reference || ''),
			}),
		}
	}
	if (result?.status === 'rejected') {
		return {
			kind: 'error',
			text: t('shillinq', 'Digipoort rejected the statement. Check it and file it again.'),
		}
	}
	return {
		kind: 'warning',
		text: t('shillinq', 'Digipoort has not taken the statement yet. Try again later.'),
	}
}
