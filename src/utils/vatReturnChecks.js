// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-return-from-books 3.3 (REQ-TVRB-001): the Checks tab on a BTW
// return. The checks run on the server (VatReturnCheckService) and come back
// from GET /api/vat-returns/{id}/checks; this file only reads them and says
// them in the user's language.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The name of each check, in the order the tab shows them.
 *
 * @return {object} Rule id to its sentence.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 */
export function checkNames() {
	return {
		'nl-vat-return-line-box': t('shillinq', 'Every VAT line has a box'),
		'nl-vat-return-rate-per-line': t(
			'shillinq',
			'Posted VAT equals base times rate',
		),
		'nl-vat-return-account-movement': t(
			'shillinq',
			'The VAT accounts agree with the return',
		),
		'nl-vat-return-no-drafts': t(
			'shillinq',
			'Nothing in the period is still a draft',
		),
		'nl-vat-return-previous-filed': t(
			'shillinq',
			'The previous return is submitted',
		),
		'nl-vat-return-reverse-charge-pair': t(
			'shillinq',
			'Reverse-charge VAT is owed and deducted',
		),
	}
}

/**
 * The rows the tab shows, one per check.
 *
 * @param {Array<object>} checks The server's checks.
 * @return {Array<object>} Rows with a name, a state and what to fix.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 */
export function checkRows(checks) {
	const names = checkNames()
	return (Array.isArray(checks) ? checks : []).map((check) => {
		let state = 'passed'
		if (!check.passed) {
			state = check.blocking ? 'blocking' : 'warning'
		}
		return {
			id: check.id,
			name: names[check.id] || check.statement || check.id,
			state,
			stateLabel: {
				passed: t('shillinq', 'Passed'),
				blocking: t('shillinq', 'Failed, blocks submitting'),
				warning: t('shillinq', 'Failed, warning only'),
			}[state],
			offenders: Array.isArray(check.offenders) ? check.offenders : [],
		}
	})
}

/**
 * Run the checks of a return.
 *
 * @param {string} returnId The BtwAangifte id.
 * @return {Promise<Array<object>>} The checks.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 */
export async function fetchVatReturnChecks(returnId) {
	const response = await axios.get(
		generateUrl(
			'/apps/shillinq/api/vat-returns/'
				+ encodeURIComponent(String(returnId))
				+ '/checks',
		),
	)
	return Array.isArray(response.data?.data) ? response.data.data : []
}
