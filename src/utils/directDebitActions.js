// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-sepa-direct-debit: the named handler for the "Propose direct
// debit" header action on Direct debit batches. CnIndexPage resolves a header
// handler against `customComponents` (src/main.js) and calls it; it opens the
// dialog with spawnDialog.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import ProposeDirectDebitModal from '../modals/ProposeDirectDebitModal.vue'

/**
 * Header action: open the Propose direct debit dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-2.1
 */
export function openProposeDirectDebit() {
	return spawnDialog(ProposeDirectDebitModal, {})
}
