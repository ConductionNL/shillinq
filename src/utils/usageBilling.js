// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-usage-billing: the requests and the pure logic behind the meter
// readings page (Import readings, Rate) and the usage option of the invoice
// generator. The page handlers live in usageBillingActions.js.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/**
 * The columns a reading file may carry, as MeterReadingImportService reads them.
 *
 * @type {Array<string>}
 */
export const READING_COLUMNS = [
	'meterId',
	'customerId',
	'resourceType',
	'quantity',
	'unit',
	'periodStart',
	'periodEnd',
	'ratePlanId',
	'description',
]

/**
 * Split one CSV line on the separator, honouring double quotes.
 *
 * @param {string} line The line.
 * @param {string} separator `,` or `;`.
 * @return {Array<string>} The cells.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export function splitCsvLine(line, separator) {
	const cells = []
	let cell = ''
	let quoted = false
	for (let i = 0; i < line.length; i++) {
		const char = line[i]
		if (char === '"' && quoted && line[i + 1] === '"') {
			cell += '"'
			i++
		} else if (char === '"') {
			quoted = !quoted
		} else if (char === separator && !quoted) {
			cells.push(cell.trim())
			cell = ''
		} else {
			cell += char
		}
	}
	cells.push(cell.trim())
	return cells
}

/**
 * The rows of a reading file: the first line names the columns, every other
 * non-empty line is one reading. Unknown columns are dropped.
 *
 * @param {string} text The file's text.
 * @return {Array<object>} The rows, in file order.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export function parseReadingsCsv(text) {
	const lines = String(text || '')
		.replace(/^\uFEFF/, '')
		.split(/\r?\n/)
		.filter((line) => line.trim() !== '')
	if (lines.length < 2) {
		return []
	}
	const separator = lines[0].includes(';') ? ';' : ','
	const header = splitCsvLine(lines[0], separator)
	return lines.slice(1).map((line) => {
		const cells = splitCsvLine(line, separator)
		const row = {}
		header.forEach((column, index) => {
			if (READING_COLUMNS.includes(column)) {
				row[column] = cells[index] ?? ''
			}
		})
		return row
	})
}

/**
 * Send the rows to the import endpoint.
 *
 * @param {string} administrationId The active administration.
 * @param {Array<object>} rows The parsed rows.
 * @return {Promise<{created: Array<string>, refused: Array<{row: number, reason: string}>}>} The outcome.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export async function importReadings(administrationId, rows) {
	const { data } = await axios.post(
		generateUrl('/apps/shillinq/api/meter-readings/import'),
		{ administrationId, rows },
	)
	return data
}

/**
 * Run the `rate` transition on every reading; count what was rated and what not.
 *
 * @param {Array<string>} ids The reading ids.
 * @return {Promise<{rated: number, failed: number}>} The counts.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */
export async function rateReadings(ids) {
	let rated = 0
	let failed = 0
	for (const id of ids) {
		try {
			await axios.post(
				generateUrl(
					`/apps/openregister/api/objects/${encodeURIComponent(id)}/transition`,
				),
				{ action: 'rate', data: {} },
			)
			rated++
		} catch {
			failed++
		}
	}
	return { rated, failed }
}

/**
 * The readings a usage invoice can bill: the customer's, rated, not on an
 * invoice yet, with a period ending inside the invoice period.
 *
 * @param {Array<object>} readings Meter readings.
 * @param {string} customerId The customer.
 * @param {string} fromDate First day of the invoice period (YYYY-MM-DD).
 * @param {string} toDate Last day of the invoice period (YYYY-MM-DD).
 * @return {Array<object>} The billable readings, oldest period first.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
 */
export function billableReadings(readings, customerId, fromDate, toDate) {
	return (readings || [])
		.filter(
			(reading) =>
				reading?.status === 'rated'
				&& !reading?.invoiceId
				&& String(reading?.customerId || '') === String(customerId || '')
				&& String(reading?.periodEnd || '') >= String(fromDate || '')
				&& String(reading?.periodEnd || '') <= String(toDate || ''),
		)
		.sort((a, b) =>
			String(a.periodEnd || '').localeCompare(String(b.periodEnd || '')),
		)
}

/**
 * Load the customer's billable readings for the invoice period.
 *
 * @param {string} customerId The customer.
 * @param {string} fromDate First day of the invoice period.
 * @param {string} toDate Last day of the invoice period.
 * @return {Promise<Array<object>>} The billable readings.
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
 */
export async function loadBillableReadings(customerId, fromDate, toDate) {
	const response = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/MeterReading`),
		{ params: { customerId, status: 'rated', _limit: 500 } },
	)
	const rows = response.data?.results ?? response.data ?? []
	return billableReadings(rows, customerId, fromDate, toDate)
}
