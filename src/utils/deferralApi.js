/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Calls and line helpers for "Spread over periods" (ledger-period-end
 * REQ-LPE-004): load the invoice, turn its lines into spreadable lines,
 * preview the releases and create the deferral schedule.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/** Per side: the invoice schema, the schedule kind and the default accounts. */
export const SIDES = {
	ap: { schema: 'SupplierInvoice', kind: 'prepaid-cost', prefix: 'ap-invoice', resultAccount: '', balanceAccount: '1900' },
	ar: { schema: 'ARInvoice', kind: 'deferred-revenue', prefix: 'ar-invoice', resultAccount: '8000', balanceAccount: '1600' },
}

/**
 * Load an invoice of a side.
 *
 * @param {string} side ap or ar.
 * @param {string} id The invoice id.
 * @return {Promise<object>} The invoice.
 */
export async function loadInvoice(side, id) {
	const schema = (SIDES[side] || SIDES.ap).schema
	const response = await axios.get(generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}/${encodeURIComponent(id)}`))
	return response?.data || {}
}

/**
 * The lines of an invoice as spreadable lines: a key, a label, the amount in
 * cents and the account it was booked on.
 *
 * @param {string} side ap or ar.
 * @param {object} invoice The invoice.
 * @return {Array<object>} The lines.
 */
export function spreadableLines(side, invoice) {
	const number = invoice?.invoiceNumber || invoice?.id || ''
	if (side === 'ar') {
		return (Array.isArray(invoice?.invoiceLines) ? invoice.invoiceLines : []).map((line, index) => {
			const key = String(line.lineId || index + 1)
			return {
				key,
				label: line.itemName || key,
				cents: Math.round(Number(line.netAmount || 0) * 100),
				account: '',
				reference: `${SIDES.ar.prefix}:${number}#line-${key}`,
			}
		})
	}
	return (Array.isArray(invoice?.lines) ? invoice.lines : []).map((line, index) => {
		const key = String(line.lineNumber || index + 1)
		return {
			key,
			label: line.description || key,
			cents: Math.round(Number(line.lineExtension || 0)),
			account: line.accountNumber || '',
			reference: `${SIDES.ap.prefix}:${number}#line-${key}`,
		}
	})
}

/**
 * Preview the releases of a schedule.
 *
 * @param {object} body The schedule fields.
 * @return {Promise<object>} The schedule with its releases.
 */
export async function previewSchedule(body) {
	const response = await axios.post(generateUrl('/apps/shillinq/api/deferral-schedules/preview'), body)
	return response?.data?.data || {}
}

/**
 * Create the schedule and post its opening entry.
 *
 * @param {object} body The schedule fields.
 * @return {Promise<object>} The stored schedule.
 */
export async function createSchedule(body) {
	const response = await axios.post(generateUrl('/apps/shillinq/api/deferral-schedules'), body)
	return response?.data?.data || {}
}

/**
 * Cents as euros with two decimals.
 *
 * @param {number} cents The amount in cents.
 * @return {string} The amount.
 */
export function euros(cents) {
	return (Number(cents || 0) / 100).toFixed(2)
}
