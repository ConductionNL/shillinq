// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-investment-deduction-claims 2.1: the request behind "Recalculate" on
// Investment deductions, and the wording of its result and refusals.
// Amounts travel as EUR cents.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The schemes the server totals, in the order they are shown. */
export const SCHEMES = ['KIA', 'EIA', 'MIA']

/**
 * The year a bookkeeper most likely recalculates: the one that just ended
 * until its tax return is due, so before July the previous year.
 *
 * @param {Date} [now] The moment to count from.
 * @return {number} The year.
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export function defaultYear(now = new Date()) {
	return now.getMonth() < 6 ? now.getFullYear() - 1 : now.getFullYear()
}

/**
 * Cents as a euro amount with two decimals.
 *
 * @param {number} cents The amount.
 * @return {string} For example "17,920.00".
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export function euro(cents) {
	return (Number(cents || 0) / 100).toLocaleString('en-US', {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})
}

/**
 * The server's totals as rows, one per scheme, a missing scheme as zero.
 *
 * @param {object} totals The totals per scheme, in cents.
 * @return {Array<{scheme: string, amount: string}>} The rows.
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export function totalRows(totals) {
	return SCHEMES.map((scheme) => ({
		scheme,
		amount: euro(totals?.[scheme] ?? 0),
	}))
}

/**
 * Recalculate a year's claims.
 *
 * @param {number|string} year The year.
 * @param {string} administrationId The administration; empty for the active one.
 * @return {Promise<object>} year, totals, claims and kept.
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export async function recalculateYear(year, administrationId = '') {
	const body = administrationId === '' ? {} : { administrationId }
	const { data } = await axios.post(
		generateUrl(
			'/apps/shillinq/api/investment-deductions/'
				+ encodeURIComponent(String(year))
				+ '/calculate',
		),
		body,
	)
	return data
}

/**
 * The sentence for a refused or failed recalculation.
 *
 * @param {object} error The axios error.
 * @return {string} The sentence.
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export function recalculateErrorText(error) {
	const status = error?.response?.status
	if (status === 400) {
		return t('shillinq', 'Choose a year between 2000 and 2100.')
	}
	if (status === 401 || status === 403 || status === 404) {
		return t('shillinq', 'You cannot calculate investment deductions for this administration.')
	}
	if (status === 422) {
		return t('shillinq', 'There are no investment deduction tables for that year yet.')
	}
	return t(
		'shillinq',
		'The investment deductions could not be calculated. Try again, or ask your administrator to check the log.',
	)
}
