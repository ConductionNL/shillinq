// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-automatic-dunning 4.1 (REQ-RAD-008): the header actions that
// open the Next run preview. "Next run" on the Dunning runs index page
// (resolved through `customComponents`) and "Switch on reminders" on an
// administration's page (resolved through `manifest.actions`). Both are in
// src/manifestActions.js, which feeds both maps.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import DunningNextRunModal from '../modals/DunningNextRunModal.vue'

/**
 * Header action on Dunning runs: what the next daily run would send, for
 * every administration, and how the last run went.
 *
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export function openDunningNextRun() {
	return spawnDialog(DunningNextRunModal, {
		administrationId: '',
		switchOn: false,
	})
}

/**
 * Header action on an administration: show the preview first, then switch
 * reminders on (REQ-RAD-008: enabling shows the list first).
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export function openDunningSwitchOn(scope) {
	const id = scope?.item?.id || window.location.pathname.split('/').pop() || ''
	if (!id) {
		return undefined
	}
	return spawnDialog(DunningNextRunModal, { administrationId: id, switchOn: true })
}
