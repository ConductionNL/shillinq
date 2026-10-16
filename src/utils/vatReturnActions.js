// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-return-from-books 2.3: the "Prepare return" header action on BTW
// returns. CnIndexPage resolves it through `customComponents`; it is in
// src/manifestActions.js, which feeds both handler maps.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import PrepareVatReturnModal from '../modals/PrepareVatReturnModal.vue'

/**
 * Header action: open the Prepare return dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
 */
export function openPrepareVatReturn() {
	return spawnDialog(PrepareVatReturnModal, {})
}
