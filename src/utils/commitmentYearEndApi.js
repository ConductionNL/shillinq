// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// planning-commitment-year-end: the requests behind "Carry open commitments
// to next year" on the commitments register and "Mark as last invoice" on a
// supplier invoice, and the header action that opens the carry-over dialog.
// Amounts travel as EUR cents.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import CarryOverCommitmentsModal from '../modals/CarryOverCommitmentsModal.vue'

const BASE = '/apps/shillinq/api/v1'

/**
 * Cents as a euro amount with two decimals.
 *
 * @param {number} cents The amount.
 * @return {string} For example "18,000.00".
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
 */
export function euro(cents) {
	return (Number(cents || 0) / 100).toLocaleString('en-US', {
		minimumFractionDigits: 2,
		maximumFractionDigits: 2,
	})
}

/**
 * What carrying a year's open commitments over would do.
 *
 * @param {string} administrationId The administration.
 * @param {number} fromYear The year that ends.
 * @return {Promise<object>} The preview: lines, shortfalls and total.
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
 */
export async function previewCarryOver(administrationId, fromYear) {
	const { data } = await axios.get(generateUrl(`${BASE}/commitments/carry-over`), {
		params: { administrationId, fromYear },
	})
	return data
}

/**
 * Carry a year's open commitments over.
 *
 * @param {string} administrationId The administration.
 * @param {number} fromYear The year that ends.
 * @return {Promise<object>} What was carried.
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
 */
export async function carryOver(administrationId, fromYear) {
	const { data } = await axios.post(
		generateUrl(`${BASE}/commitments/carry-over`),
		{ administrationId, fromYear },
	)
	return data
}

/**
 * What marking an invoice as the last one releases.
 *
 * @param {string} administrationId The administration.
 * @param {string} invoiceId The supplier invoice.
 * @return {Promise<object>} invoiceNumber, commitmentNumber and release.
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
 */
export async function previewLastInvoice(administrationId, invoiceId) {
	const { data } = await axios.get(
		generateUrl(
			`${BASE}/supplier-invoices/${encodeURIComponent(invoiceId)}/last-invoice`,
		),
		{ params: { administrationId } },
	)
	return data
}

/**
 * Mark an invoice as the last one and close its commitment.
 *
 * @param {string} administrationId The administration.
 * @param {string} invoiceId The supplier invoice.
 * @return {Promise<object>} What was released.
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-2.3
 */
export async function markLastInvoice(administrationId, invoiceId) {
	const { data } = await axios.post(
		generateUrl(
			`${BASE}/supplier-invoices/${encodeURIComponent(invoiceId)}/last-invoice`,
		),
		{ administrationId },
	)
	return data
}

/**
 * Header action on the commitments register: open the carry-over dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.2
 */
export function openCarryOverCommitments() {
	return spawnDialog(CarryOverCommitmentsModal, {})
}
