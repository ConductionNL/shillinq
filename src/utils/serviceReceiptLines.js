/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Service receipt lines: which order lines are services, and the payload a
 * confirmed line sends (purchasing-order-dispatch-and-receipt, task 4.2).
 */

/**
 * UN/ECE Recommendation 20 units that measure time or a service rather than
 * goods: hour, day, week, month, minute, and the generic "service unit".
 */
export const SERVICE_UNITS = ['HUR', 'DAY', 'WEE', 'MON', 'MIN', 'E48']

/** The three ways a line is confirmed, as the service-receipt endpoint names them. */
export const CONFIRMATION_MODES = ['percentageComplete', 'quantityConfirmed', 'amountConfirmedCents']

/**
 * Whether an order line is a service, by its unit of measure.
 *
 * @param {object} line A PurchaseOrderLine.
 * @return {boolean}
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
 */
export function isServiceLine(line) {
	return SERVICE_UNITS.includes(String(line?.unitOfMeasure || '').toUpperCase())
}

/**
 * The lines-endpoint payload of one entry, or null when nothing was entered.
 *
 * A percentage is a whole number from 0 to 100; a quantity a positive number;
 * an amount is entered in euros and sent in cents. Anything else is null, so
 * an empty or invalid entry adds no line.
 *
 * @param {object} entry { poLineId, mode, value } as the form holds it.
 * @param {object} period { periodStart, periodEnd } of the receipt.
 * @param {string} administrationId The administration.
 * @return {object|null}
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
 */
export function linePayload(entry, period, administrationId) {
	if (!entry || !CONFIRMATION_MODES.includes(entry.mode) || entry.value === '' || entry.value === null || entry.value === undefined) {
		return null
	}
	const number = Number(entry.value)
	if (!Number.isFinite(number) || number <= 0) {
		return null
	}
	let value = number
	if (entry.mode === 'percentageComplete') {
		if (!Number.isInteger(number) || number > 100) {
			return null
		}
	} else if (entry.mode === 'amountConfirmedCents') {
		value = Math.round(number * 100)
	}
	return {
		administrationId,
		poLineId: entry.poLineId,
		[entry.mode]: value,
		periodStart: period.periodStart || null,
		periodEnd: period.periodEnd || null,
	}
}
