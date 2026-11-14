// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ledger-sealed-entries: the request behind "Check ledger seal" on the general
// ledger page, and the header action that opens its dialog. The server walks
// the seal chain of one administration; only an admin or a controller of that
// administration may run it (REQ-LSE-002).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import LedgerSealCheckModal from '../modals/LedgerSealCheckModal.vue'

const BASE = '/apps/shillinq/api/v1'

/**
 * Check the seal chain of one administration.
 *
 * @param {string} administrationId The administration.
 * @return {Promise<{intact: boolean, entries: number, transactionNumber: ?string, transactionId: ?string, reason: ?string, message: string}>} The result.
 * @spec openspec/changes/ledger-sealed-entries/tasks.md#task-2.1
 */
export async function checkLedgerSeal(administrationId) {
	const { data } = await axios.get(generateUrl(`${BASE}/ledger/seal-check`), {
		params: { administrationId },
	})
	return data
}

/**
 * The sentence for a failed request: the server's own when it sent one.
 *
 * @param {unknown} error The axios error.
 * @param {string} fallback The sentence when the server sent none.
 * @return {string} The sentence.
 */
export function sealCheckError(error, fallback) {
	const message = error?.response?.data?.message
	return typeof message === 'string' && message.trim() !== '' ? message : fallback
}

/**
 * Header action on the general ledger page: open the seal check dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/ledger-sealed-entries/tasks.md#task-2.1
 */
export function openLedgerSealCheck() {
	return spawnDialog(LedgerSealCheckModal, {})
}
