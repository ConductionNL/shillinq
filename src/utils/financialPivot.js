// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The financial pivot's table and exports (reporting-custom-analysis
 * REQ-RCA-002). Pure functions, so vitest pins what the page shows and what
 * the files hold without a browser.
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */

/** The axes the endpoint accepts, in the order the pickers offer them. */
export const PIVOT_AXES = ['account', 'accountGroup', 'period', 'quarter', 'costCenter', 'project', 'customer']

/**
 * The table the page shows: a header row, one row per row group and a totals row.
 * Every cell is a plain value; amounts stay numbers so an export keeps them numeric.
 *
 * @param {object} pivot The endpoint's answer.
 * @param {object} labels Texts: rowHeader, total, notSet.
 * @return {Array<Array<string|number|null>>} The rows of the table.
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
export function pivotTable(pivot, labels) {
	const columns = pivot.columns || []
	const name = (group) => (group.label === null || group.label === undefined ? labels.notSet : group.label)
	const table = [[labels.rowHeader, ...columns.map(name), labels.total]]
	for (const row of pivot.rows || []) {
		const cells = (pivot.cells && pivot.cells[row.key]) || {}
		table.push([
			name(row),
			...columns.map((column) => (column.key in cells ? cells[column.key] : null)),
			pivot.rowTotals ? pivot.rowTotals[row.key] ?? 0 : 0,
		])
	}
	table.push([
		labels.total,
		...columns.map((column) => (pivot.columnTotals ? pivot.columnTotals[column.key] ?? 0 : 0)),
		pivot.total ?? 0,
	])
	return table
}

/**
 * The table as CSV: comma separated, quoted where needed, amounts with a dot.
 *
 * @param {Array<Array<string|number|null>>} table The table.
 * @return {string} The CSV text.
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
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
		return /[",\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text
	}
	return table.map((row) => row.map(cell).join(',')).join('\n') + '\n'
}

/**
 * The table as an Excel workbook in SpreadsheetML, which Excel and
 * LibreOffice open as a spreadsheet with numeric amount cells.
 *
 * @param {Array<Array<string|number|null>>} table The table.
 * @param {string} sheetName The sheet's name.
 * @return {string} The workbook XML.
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
export function toSpreadsheetXml(table, sheetName) {
	const escape = (text) => String(text)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
	const cell = (value) => {
		if (value === null || value === undefined) {
			return '<Cell/>'
		}
		if (typeof value === 'number') {
			return '<Cell><Data ss:Type="Number">' + value + '</Data></Cell>'
		}
		return '<Cell><Data ss:Type="String">' + escape(value) + '</Data></Cell>'
	}
	const rows = table.map((row) => '<Row>' + row.map(cell).join('') + '</Row>').join('')
	return '<?xml version="1.0" encoding="UTF-8"?>'
		+ '<?mso-application progid="Excel.Sheet"?>'
		+ '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
		+ ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
		+ '<Worksheet ss:Name="' + escape(sheetName.slice(0, 31)) + '"><Table>' + rows + '</Table></Worksheet>'
		+ '</Workbook>'
}

/**
 * The range a new pivot opens with: this calendar year up to today.
 *
 * @param {Date} today Today.
 * @return {{from: string, to: string}} Dates as YYYY-MM-DD.
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 */
export function defaultRange(today) {
	const pad = (n) => String(n).padStart(2, '0')
	const year = today.getFullYear()
	return {
		from: year + '-01-01',
		to: year + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate()),
	}
}
