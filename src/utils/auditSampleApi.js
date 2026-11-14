// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-sector-council-and-auditor-statements 2.2 (REQ-011): the Draw
// sample header action on the audit protocol page and its request to
// AuditSampleController::draw (POST /api/bado/controleprotocol/samples).

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import AuditSampleDrawModal from '../modals/AuditSampleDrawModal.vue'

/**
 * The size, seed and prefix of a draw, or null when the size or seed is not
 * a positive whole number.
 *
 * @param {{size: string|number, seed: string|number, accountPrefix: string}} input The form.
 * @return {{size: number, seed?: number, account_prefix?: string}|null} The request fields.
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
export function drawFields(input) {
	const size = String(input?.size ?? '').trim()
	const seed = String(input?.seed ?? '').trim()
	if (!/^[1-9]\d*$/.test(size) || (seed !== '' && !/^[1-9]\d*$/.test(seed))) {
		return null
	}
	const fields = { size: Number(size) }
	if (seed !== '') {
		fields.seed = Number(seed)
	}
	const prefix = String(input?.accountPrefix ?? '').trim()
	if (prefix !== '') {
		fields.account_prefix = prefix
	}
	return fields
}

/**
 * Draw and store a sample.
 *
 * @param {string} protocolId The Controleprotocol id.
 * @param {object} fields The request fields from drawFields().
 * @return {Promise<object>} The stored sample.
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
export async function drawAuditSample(protocolId, fields) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/bado/controleprotocol/samples'),
		{ protocol_id: protocolId, ...fields },
	)
	return response.data
}

/**
 * The message of a failed draw: the server's reason for a refusal.
 *
 * @param {unknown} error The axios error.
 * @return {string}
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
export function sampleError(error) {
	const status = error?.response?.status
	const reason = error?.response?.data?.error
	if (status === 422 && typeof reason === 'string' && reason !== '') {
		return reason
	}
	if (status === 404) {
		return t('shillinq', 'This audit protocol was not found.')
	}
	return t('shillinq', 'The sample could not be drawn.')
}

/**
 * Header action on an audit protocol: open the Draw sample dialog.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<unknown>|null} The dialog's close payload.
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
export function openAuditSampleDraw(scope) {
	const protocolId = String(scope?.item?.id || window.location.pathname.split('/').pop() || '')
	if (!protocolId) {
		return null
	}
	return spawnDialog(AuditSampleDrawModal, { protocolId })
}
