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
	const scan = { line, separator, cells: [], cell: '', quoted: false }
	for (let i = 0; i < line.length; i++) {
		i += csvStep(scan, i)
	}
	scan.cells.push(scan.cell.trim())
	return scan.cells
}

/**
 * Read the character at `i`; returns how many extra characters it used.
 *
 * @param {object} scan The line, separator, cells so far, open cell and quote state.
 * @param {number} i The position.
 * @return {number} 1 after an escaped quote, else 0.
 */
function csvStep(scan, i) {
	const char = scan.line[i]
	if (char === '"') {
		return csvQuote(scan, i)
	}
	if (char === scan.separator && !scan.quoted) {
		scan.cells.push(scan.cell.trim())
		scan.cell = ''
	} else {
		scan.cell += char
	}
	return 0
}

/**
 * A quote: an escaped quote inside a quoted cell, or the quote state flips.
 *
 * @param {object} scan The scan state.
 * @param {number} i The position of the quote.
 * @return {number} 1 when the next quote was used, else 0.
 */
function csvQuote(scan, i) {
	if (scan.quoted && scan.line[i + 1] === '"') {
		scan.cell += '"'
		return 1
	}
	scan.quoted = !scan.quoted
	return 0
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
	const period = {
		customerId: textOrEmpty(customerId),
		from: textOrEmpty(fromDate),
		to: textOrEmpty(toDate),
	}
	return (readings || [])
		.filter((reading) => isBillableReading(reading, period))
		.sort((a, b) => textOrEmpty(a.periodEnd).localeCompare(textOrEmpty(b.periodEnd)))
}

/**
 * A value as text, '' when it is falsy.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function textOrEmpty(value) {
	return String(value || '')
}

/**
 * A reading is billable in the period: rated, not invoiced, the period's
 * customer, its period ending inside the period.
 *
 * @param {object|null} reading The meter reading.
 * @param {{customerId: string, from: string, to: string}} period The invoice period.
 * @return {boolean}
 */
function isBillableReading(reading, period) {
	return reading?.status === 'rated'
		&& !reading?.invoiceId
		&& textOrEmpty(reading?.customerId) === period.customerId
		&& endsInPeriod(textOrEmpty(reading?.periodEnd), period)
}

/**
 * The period end lies between the period's first and last day.
 *
 * @param {string} periodEnd The reading's period end.
 * @param {{from: string, to: string}} period The invoice period.
 * @return {boolean}
 */
function endsInPeriod(periodEnd, period) {
	return periodEnd >= period.from && periodEnd <= period.to
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
