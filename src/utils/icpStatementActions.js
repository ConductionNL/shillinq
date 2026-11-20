// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-icp-and-reverse-charge 2.3: the ICP page's actions. "Prepare
// statement" is a header action on the index; Reconcile, Download XML and
// File are header actions on the detail page. They are in
// src/manifestActions.js, which feeds both handler maps.

import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import PrepareIcpStatementModal from '../modals/PrepareIcpStatementModal.vue'
import {
	fileIcpStatementRequest,
	filingMessage,
	icpErrorText,
	icpFilingAvailable,
	icpStatementXmlUrl,
	reconcileIcpPeriod,
	reconciliationMessage,
} from './icpStatementApi.js'

/**
 * The toast for each message kind.
 *
 * @type {Record<string, function(string): void>}
 */
const TOAST = { success: showSuccess, warning: showWarning, error: showError }

/**
 * The statement the action runs on, or null when it has no period.
 *
 * @param {{item: object}|null} scope The page scope.
 * @return {object|null} The statement.
 */
function statementOf(scope) {
	const item = scope?.item
	return item && item.period && item.administrationId ? item : null
}

/**
 * Header action on the ICP page: open the Prepare statement dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function openPrepareIcpStatement() {
	return spawnDialog(PrepareIcpStatementModal, {})
}

/**
 * Header action: compare the statement with box 3b and say how it compares.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The outcome, or null when it failed.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export async function reconcileIcpStatement(scope) {
	const statement = statementOf(scope)
	if (!statement) {
		return null
	}
	try {
		const outcome = await reconcileIcpPeriod(statement)
		const message = reconciliationMessage(outcome)
		TOAST[message.kind](message.text)
		return outcome
	} catch (error) {
		showError(icpErrorText(error))
		return null
	}
}

/**
 * Header action: download the statement's XBRL instance.
 *
 * @param {{item: object}} scope The page scope.
 * @param {Window} target The window that follows the download link.
 * @return {string} The address, or '' without a statement.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
 */
export function downloadIcpStatementXml(scope, target = window) {
	const statement = statementOf(scope)
	if (!statement) {
		return ''
	}
	const url = icpStatementXmlUrl(statement)
	target.location.assign(url)
	return url
}

/**
 * Header action: file the finalised statement through Digipoort, when a
 * Digipoort hand-off is bound; otherwise say how to file it instead.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} Digipoort's answer, or null when nothing was filed.
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */
export async function fileIcpStatement(scope) {
	const statement = statementOf(scope)
	if (!statement) {
		return null
	}
	if (statement.status !== 'finalized') {
		showError(t('shillinq', 'Finalise the statement before you file it.'))
		return null
	}
	if (!(await icpFilingAvailable())) {
		showWarning(
			t(
				'shillinq',
				'Filing through Digipoort is not available yet. Download the XML and file it yourself.',
			),
		)
		return null
	}
	try {
		const result = await fileIcpStatementRequest(statement)
		const message = filingMessage(result)
		TOAST[message.kind](message.text)
		emit('cn:page:refresh', {})
		return result
	} catch (error) {
		showError(icpErrorText(error))
		return null
	}
}
