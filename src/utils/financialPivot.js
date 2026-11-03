// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The financial pivot's table and exports (reporting-custom-analysis
 * REQ-RCA-002). Pure functions, so vitest pins what the page shows and what
 * the files hold without a browser.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

/** The axes the endpoint accepts, in the order the pickers offer them. */
export const PIVOT_AXES = [
	'account',
	'accountGroup',
	'period',
	'quarter',
	'costCenter',
	'project',
	'customer',
]

/**
 * The table the page shows: a header row, one row per row group and a totals row.
 * Every cell is a plain value; amounts stay numbers so an export keeps them numeric.
 *
 * @param {object} pivot The endpoint's answer.
 * @param {object} labels Texts: rowHeader, total, notSet.
 * @return {Array<Array<string|number|null>>} The rows of the table.
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
export function pivotTable(pivot, labels) {
	const table = { pivot, labels, columns: pivot.columns || [] }
	const rows = [headerRow(table)]
	for (const row of pivot.rows || []) {
		rows.push(bodyRow(table, row))
	}
	rows.push(totalsRow(table))
	return rows
}

/**
 * The shown name of a row or column group.
 *
 * @param {object} labels Texts: notSet.
 * @param {object} group The group.
 * @return {string|number} Its label, or the not-set text.
 */
function groupName(labels, group) {
	return group.label === null || group.label === undefined
		? labels.notSet
		: group.label
}

/**
 * The header row: the row header, each column's name and the total.
 *
 * @param {object} table `{ labels, columns }`.
 * @return {Array<string|number>} The row.
 */
function headerRow(table) {
	return [
		table.labels.rowHeader,
		...table.columns.map((column) => groupName(table.labels, column)),
		table.labels.total,
	]
}

/**
 * One row group: its name, its cell per column (null when absent) and its total.
 *
 * @param {object} table `{ pivot, labels, columns }`.
 * @param {object} row The row group.
 * @return {Array<string|number|null>} The row.
 */
function bodyRow(table, row) {
	const cells = (table.pivot.cells && table.pivot.cells[row.key]) || {}
	return [
		groupName(table.labels, row),
		...table.columns.map((column) =>
			column.key in cells ? cells[column.key] : null,
		),
		totalOf(table.pivot.rowTotals, row.key),
	]
}

/**
 * The totals row: each column's total and the grand total.
 *
 * @param {object} table `{ pivot, labels, columns }`.
 * @return {Array<string|number>} The row.
 */
function totalsRow(table) {
	return [
		table.labels.total,
		...table.columns.map((column) =>
			totalOf(table.pivot.columnTotals, column.key),
		),
		table.pivot.total ?? 0,
	]
}

/**
 * A total by key, 0 when the totals or the key are missing.
 *
 * @param {object|null|undefined} totals Totals by key.
 * @param {string} key The key.
 * @return {number}
 */
function totalOf(totals, key) {
	return totals ? (totals[key] ?? 0) : 0
}

/**
 * The table as CSV: comma separated, quoted where needed, amounts with a dot.
 *
 * @param {Array<Array<string|number|null>>} table The table.
 * @return {string} The CSV text.
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
export function toCsv(table) {
	const cell = (value) => {
		if (value === null || value === undefined) {
			return ''
		}
		if (typeof value === 'number') {
			return value.toFixed(2)
		}
		const text = String(value)
		return needsCsvQuotes(text) ? '"' + text.replaceAll('"', '""') + '"' : text
	}
	return table.map((row) => row.map(cell).join(',')).join('\n') + '\n'
}

/**
 * Whether a CSV field holds a quote, a comma or a newline, and so must be quoted.
 *
 * @param {string} text The field.
 * @return {boolean}
 */
function needsCsvQuotes(text) {
	return text.includes('"') || text.includes(',') || text.includes('\n')
}

/** The XML declaration and the opening Workbook element of a SpreadsheetML file. */
const WORKBOOK_OPEN =
	'<?xml version="1.0" encoding="UTF-8"?>'
	+ '<?mso-application progid="Excel.Sheet"?>'
	+ '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
	+ ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'

/**
 * The table as an Excel workbook in SpreadsheetML, which Excel and
 * LibreOffice open as a spreadsheet with numeric amount cells.
 *
 * @param {Array<Array<string|number|null>>} table The table.
 * @param {string} sheetName The sheet's name.
 * @return {string} The workbook XML.
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
export function toSpreadsheetXml(table, sheetName) {
	const rows = table
		.map((row) => '<Row>' + row.map(xmlCell).join('') + '</Row>')
		.join('')
	return (
		WORKBOOK_OPEN
		+ '<Worksheet ss:Name="'
		+ xmlEscape(sheetName.slice(0, 31))
		+ '"><Table>'
		+ rows
		+ '</Table></Worksheet>'
		+ '</Workbook>'
	)
}

/**
 * Text with the XML special characters escaped.
 *
 * @param {string|number|boolean} text The text.
 * @return {string}
 */
function xmlEscape(text) {
	return String(text)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replaceAll('"', '&quot;')
}

/**
 * One SpreadsheetML cell: empty, a number, or escaped text.
 *
 * @param {string|number|null|undefined} value The value.
 * @return {string}
 */
function xmlCell(value) {
	if (value === null || value === undefined) {
		return '<Cell/>'
	}
	if (typeof value === 'number') {
		return '<Cell><Data ss:Type="Number">' + value + '</Data></Cell>'
	}
	return '<Cell><Data ss:Type="String">' + xmlEscape(value) + '</Data></Cell>'
}

/**
 * The range a new pivot opens with: this calendar year up to today.
 *
 * @param {Date} today Today.
 * @return {{from: string, to: string}} Dates as YYYY-MM-DD.
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
export function defaultRange(today) {
	const pad = (n) => String(n).padStart(2, '0')
	const year = today.getFullYear()
	return {
		from: year + '-01-01',
		to: year + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate()),
	}
}
