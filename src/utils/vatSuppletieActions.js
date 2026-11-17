// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-position-and-suppletie: the page actions that check a filed return
// for corrections, prepare, download and file a supplementary return, and
// show the lines behind a box of the running VAT position. Every handler is
// in src/manifestActions.js, which feeds both handler maps.

import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import VatPositionLinesModal from '../modals/VatPositionLinesModal.vue'

/**
 * The id of the record an action runs on: the page item, else the last
 * segment of the address.
 *
 * @param {{item: object}|null} scope The page scope.
 * @return {string} The id, or an empty string.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
export function recordId(scope) {
	return scope?.item?.id || scope?.object?.id || window.location.pathname.split('/').pop() || ''
}

/**
 * The server's refusal, else the fallback sentence.
 *
 * @param {Error} error The failed request.
 * @param {string} fallback What to say when the server said nothing.
 * @return {string}
 */
function refusal(error, fallback) {
	return error?.response?.data?.error || fallback
}

/**
 * The outcome of a drift check, as the bookkeeper reads it.
 *
 * @param {{drift: boolean}} data The endpoint's answer.
 * @return {{kind: string, text: string}} The toast kind and its text.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
export function driftMessage(data) {
	if (data?.drift === true) {
		return {
			kind: 'warning',
			text: t('shillinq', 'The books differ from this return. A correction is on the corrections page.'),
		}
	}
	return { kind: 'success', text: t('shillinq', 'The books still match this return.') }
}

/**
 * The outcome of preparing a supplementary return, as the bookkeeper reads it.
 *
 * @param {{thresholdExceeded: boolean, filingDeadline: string|null}} correction The prepared correction.
 * @return {string}
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
export function preparedMessage(correction) {
	if (correction?.thresholdExceeded === true) {
		return t(
			'shillinq',
			'The correction is above EUR 1,000: file the supplementary return before {date}. The correction posting is staged.',
			{ date: correction.filingDeadline || '' },
		)
	}
	return t('shillinq', 'The correction is EUR 1,000 or less and can go into the next return. The correction posting is staged.')
}

/** How each outcome kind is shown. */
const TOAST = { success: showSuccess, warning: showWarning }

/**
 * Header action on a filed BTW return: check it against the books.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The answer, or null when it failed.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
export async function checkVatReturnForCorrections(scope) {
	const id = recordId(scope)
	if (!id) {
		return null
	}
	try {
		const { data } = await axios.post(generateUrl('/apps/shillinq/api/vat-returns/{id}/detect-drift', { id }))
		const message = driftMessage(data)
		TOAST[message.kind](message.text)
		emit('cn:page:refresh', {})
		return data
	} catch (e) {
		showError(refusal(e, t('shillinq', 'The return could not be checked for corrections.')))
		return null
	}
}

/**
 * Header action on a detected correction: prepare its supplementary return.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The prepared correction, or null when it failed.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.1
 */
export async function prepareSupplementaryReturn(scope) {
	const id = recordId(scope)
	if (!id) {
		return null
	}
	try {
		const { data } = await axios.post(generateUrl('/apps/shillinq/api/vat-corrections/{id}/prepare', { id }))
		showSuccess(preparedMessage(data?.data))
		emit('cn:page:refresh', {})
		return data?.data ?? null
	} catch (e) {
		showError(refusal(e, t('shillinq', 'The supplementary return could not be prepared.')))
		return null
	}
}

/**
 * Header action on a prepared correction: download its supplementary return.
 *
 * @param {{item: object}} scope The page scope.
 * @return {string} The address opened, or an empty string.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 */
export function downloadSupplementaryReturn(scope) {
	const id = recordId(scope)
	if (!id) {
		return ''
	}
	const url = generateUrl('/apps/shillinq/api/vat-corrections/{id}/supplementary-return', { id })
	window.location.assign(url)
	return url
}

/**
 * Header action on a prepared correction: file it through Digipoort, or
 * download it while Digipoort is not connected.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<string>} `filed`, `downloaded` or `failed`.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 */
export async function fileSupplementaryReturn(scope) {
	const id = recordId(scope)
	if (!id) {
		return 'failed'
	}
	try {
		await axios.post(generateUrl('/apps/shillinq/api/vat-corrections/{id}/file', { id }))
		showSuccess(t('shillinq', 'The supplementary return was handed to Digipoort.'))
		emit('cn:page:refresh', {})
		return 'filed'
	} catch (e) {
		if (e?.response?.status === 409 && e.response.data?.download) {
			showWarning(t('shillinq', 'Digipoort is not connected. The supplementary return is downloaded so you can file it with the Belastingdienst.'))
			downloadSupplementaryReturn(scope)
			return 'downloaded'
		}
		showError(refusal(e, t('shillinq', 'The supplementary return could not be filed.')))
		return 'failed'
	}
}

/**
 * Row action on the position table: show the lines behind that box.
 *
 * The table appends the row as the last argument; whatever comes first is the
 * page scope.
 *
 * @param {...object} args The dispatch arguments, the row last.
 * @return {Promise<unknown>|null} The dialog's close payload, or null without a box.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.3
 */
export function openVatPositionLines(...args) {
	const box = [...args].reverse().find((arg) => arg && Array.isArray(arg.lines))
		|| [...args].reverse().find((arg) => arg?.item && Array.isArray(arg.item.lines))?.item
	if (!box) {
		return null
	}
	return spawnDialog(VatPositionLinesModal, { box })
}
