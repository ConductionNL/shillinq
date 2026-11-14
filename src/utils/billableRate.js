/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The billable rate of an hour (sales-billable-rate-per-hour): which billing
 * models still take one rate card for the whole invoice, and how a rated hour
 * reads on the project page.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.2
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Billing models that take one rate card for the whole invoice. A time and
 * material invoice prices each hour by its own rate, from the rate card on the
 * person's assignment (REQ-BRPH-005), so it is not one of them.
 */
export const RATE_CARD_MODELS = Object.freeze(['mixed'])

/**
 * A euro amount in the user's locale.
 *
 * @param {number|string|null|undefined} value The amount.
 * @return {string} The amount, or an em dash when it is not a number.
 */
export function euro(value) {
	const amount = Number(value)
	if (value === null || value === undefined || value === '' || !Number.isFinite(amount)) {
		return '—'
	}
	return amount.toLocaleString(undefined, { style: 'currency', currency: 'EUR' })
}

/**
 * The tier a rate came from, in words.
 *
 * @param {string} tier user, role, project, client or blended.
 * @return {string} The tier's name.
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
 */
export function tierLabel(tier) {
	const labels = {
		user: t('shillinq', 'Personal rate'),
		role: t('shillinq', 'Role rate'),
		project: t('shillinq', 'Project rate'),
		client: t('shillinq', 'Client rate'),
		blended: t('shillinq', 'Standard rate'),
	}
	return labels[tier] || ''
}

/**
 * An hour's rate in one line: "€ 130,00 per hour · Personal rate", or
 * "No rate" when none resolves. Empty when the hour carries no resolution.
 *
 * @param {object|null|undefined} resolution The hour's `rate`: status, rate, unit, tier.
 * @return {string} The line.
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.2
 */
export function rateLabel(resolution) {
	if (!resolution || typeof resolution !== 'object') {
		return ''
	}
	if (resolution.status !== 'rated') {
		return t('shillinq', 'No rate')
	}
	const per = resolution.unit === 'daily'
		? t('shillinq', '{amount} per day', { amount: euro(resolution.rate) })
		: t('shillinq', '{amount} per hour', { amount: euro(resolution.rate) })
	return [per, tierLabel(resolution.tier)].filter(Boolean).join(' · ')
}

/**
 * A billed hour's state: "Billed on 2026-T-0031", or "Not billed".
 *
 * @param {object} row The row: billed, invoiceNumber.
 * @return {string} The state.
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
 */
export function billedLabel(row) {
	if (row && row.billed === true) {
		return t('shillinq', 'Billed on {invoice}', { invoice: String(row.invoiceNumber || '') })
	}
	return t('shillinq', 'Not billed')
}

/**
 * Hours in the user's locale, with one decimal.
 *
 * @param {number|string} value The hours.
 * @return {string} The hours.
 */
export function hoursText(value) {
	return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 2 })
}

/**
 * The month a date falls in, as `YYYY-MM`.
 *
 * @param {Date} date The date.
 * @return {string} The month.
 */
export function monthOf(date) {
	return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

/**
 * Read one month of a project's rated hours.
 *
 * @param {string} projectId The project.
 * @param {string} month The month, `YYYY-MM`.
 * @return {Promise<object>} The answer: available, reason, rows, totals.
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
 */
export async function loadProjectBillableHours(projectId, month) {
	const url = generateUrl('/apps/shillinq/api/v1/projects/{id}/billable-hours', { id: projectId })
	const response = await axios.get(url, { params: { month } })
	return response.data || {}
}
