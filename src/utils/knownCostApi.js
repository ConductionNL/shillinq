// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// budget-known-costs design.md §12: the request behind "Regenerate known
// costs" on Budget Line Derivations, and the wording of its result and
// refusals.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Run the regeneration for the active administration, or a named one.
 *
 * @param {string} [administrationId] The administration; empty for the active one.
 * @return {Promise<object>} `{created, updated, overridden, skippedFiscalYears}`.
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
 */
export async function regenerateKnownCosts(administrationId = '') {
	const body = administrationId === '' ? {} : { administrationId }
	const { data } = await axios.post(
		generateUrl('/apps/shillinq/api/v1/budgets/known-costs/regenerate'),
		body,
	)
	return data
}

/**
 * The run summary as sentences, one per fact worth saying.
 *
 * @param {object} result The run summary.
 * @return {Array<string>} The lines.
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/specs/budget-known-costs/spec.md
 */
export function summaryLines(result) {
	const lines = [
		t(
			'shillinq',
			'Budget lines created: {created}. Updated: {updated}.',
			{
				created: Number(result?.created || 0),
				updated: Number(result?.updated || 0),
			},
		),
	]
	const overridden = Number(result?.overridden || 0)
	if (overridden > 0) {
		lines.push(
			t(
				'shillinq',
				'Budget lines you changed by hand and that were left alone: {overridden}.',
				{ overridden },
			),
		)
	}
	const skipped = Array.isArray(result?.skippedFiscalYears)
		? result.skippedFiscalYears
		: []
	if (skipped.length > 0) {
		lines.push(
			t(
				'shillinq',
				'Years without a default budget, so nothing was written for them: {years}.',
				{ years: skipped.join(', ') },
			),
		)
	}
	return lines
}

/**
 * The message for a failed run.
 *
 * @param {object} error The request error.
 * @return {string} The message.
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
 */
export function regenerateErrorText(error) {
	const status = error?.response?.status
	if (status === 401 || status === 403 || status === 404) {
		return t('shillinq', 'You cannot regenerate the budget of this administration.')
	}
	return t(
		'shillinq',
		'The known costs could not be regenerated. Try again, or ask your administrator to check the log.',
	)
}
