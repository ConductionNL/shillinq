// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-payment-run: the named handler for the "Propose payment run" header
// action on Payment runs. CnIndexPage resolves a header handler against
// `customComponents` (src/main.js) and calls it; it opens the dialog with
// spawnDialog.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import ProposePaymentRunModal from '../modals/ProposePaymentRunModal.vue'

/**
 * Header action: open the Propose payment run dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-3.2
 */
export function openProposePaymentRun() {
	return spawnDialog(ProposePaymentRunModal, {})
}
