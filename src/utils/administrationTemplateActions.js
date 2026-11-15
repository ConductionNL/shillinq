// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ledger-multi-administration 3.2 (REQ-LMA-003): the "Save as template"
// header action on an administration's page, resolved through
// `manifest.actions` (src/manifestActions.js).

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import SaveAdministrationTemplateModal from '../modals/SaveAdministrationTemplateModal.vue'

/**
 * Save an administration's chart of accounts as an office template.
 *
 * The server copies the structure only: no postings, relations or balances.
 *
 * @param {string} administrationId The administration.
 * @param {string} name The template's name.
 * @return {Promise<object>} The saved template.
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
 */
export async function saveAdministrationAsTemplate(administrationId, name) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/administrations/{id}/template', { id: administrationId }),
		{ name },
	)
	return response?.data ?? {}
}

/**
 * Header action on an administration: ask the template's name and save it.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<unknown>|undefined} The dialog's close payload.
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-an-office-saves-an-administration-as-a-template-req-lma-003
 */
export function openSaveAdministrationTemplate(scope) {
	const id = scope?.item?.id || window.location.pathname.split('/').pop() || ''
	if (!id) {
		return undefined
	}
	return spawnDialog(SaveAdministrationTemplateModal, {
		administrationId: String(id),
		suggestedName: String(scope?.item?.name || ''),
	})
}
