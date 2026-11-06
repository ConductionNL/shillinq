// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-down-payments: the named handler for the "New down-payment invoice"
// header action on Accounts Receivable. CnIndexPage resolves a header
// handler against `customComponents` (src/main.js) and calls it with
// `{ actionId }`; it opens the dialog with spawnDialog.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import DownPaymentInvoiceModal from '../modals/DownPaymentInvoiceModal.vue'

/**
 * Header action: open the New down-payment invoice dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
export function openDownPaymentInvoice() {
	return spawnDialog(DownPaymentInvoiceModal, {})
}
