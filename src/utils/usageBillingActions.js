// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-usage-billing: the named handlers of the meter readings page, the
// "Import readings" header action and the "Rate" bulk action. CnIndexPage
// resolves them through `customComponents` in src/main.js.

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import MeterReadingImportModal from '../modals/MeterReadingImportModal.vue'
import { rateReadings } from './usageBilling.js'

/**
 * Bulk action "Rate" on the meter readings page.
 *
 * @param {object} scope The bulk scope, with `selectedIds`.
 * @return {Promise<{rated: number, failed: number}>|undefined} The counts.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export async function rateMeterReadings(scope) {
	const ids = Array.isArray(scope?.selectedIds) ? scope.selectedIds : []
	if (ids.length === 0) {
		return undefined
	}
	const outcome = await rateReadings(ids)
	if (outcome.rated > 0) {
		showSuccess(
			t('shillinq', '{count} readings rated.', { count: outcome.rated }),
		)
	}
	if (outcome.failed > 0) {
		showError(
			t(
				'shillinq',
				'{count} readings were not rated: they are already rated, or have no rate plan.',
				{ count: outcome.failed },
			),
		)
	}
	return outcome
}

/**
 * Header action "Import readings" on the meter readings page.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export function openMeterReadingImport() {
	return spawnDialog(MeterReadingImportModal, {})
}
