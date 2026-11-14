/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-custom-analysis REQ-RCA-002: the pivot page shows the endpoint's
 * sums with totals, marks empty cells, and exports exactly what it shows.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	defaultRange,
	PIVOT_AXES,
	pivotTable,
	toCsv,
	toSpreadsheetXml,
} from '../../src/utils/financialPivot.js'

// The endpoint's answer for Adviesbureau Van Dijk, rows account group, columns quarter (LedgerPivotServiceTest::testRevenueByQuarter).
const vanDijk = {
	rowAxis: 'accountGroup',
	columnAxis: 'quarter',
	rows: [
		{ key: '40', label: 'Personeelskosten' },
		{ key: '80', label: 'Omzet' },
		{ key: '', label: null },
	],
	columns: [
		{ key: '2026-Q1', label: '2026-Q1' },
		{ key: '2026-Q2', label: '2026-Q2' },
		{ key: '2026-Q3', label: '2026-Q3' },
	],
	cells: {
		40: { '2026-Q1': -30000, '2026-Q2': -30000, '2026-Q3': -30000 },
		80: { '2026-Q1': 60000, '2026-Q2': 58000, '2026-Q3': 62000 },
		'': { '2026-Q2': 12.5 },
	},
	rowTotals: { 40: -90000, 80: 180000, '': 12.5 },
	columnTotals: { '2026-Q1': 30000, '2026-Q2': 28012.5, '2026-Q3': 32000 },
	total: 90012.5,
	capped: { rows: false, columns: false },
	truncated: false,
}
const labels = { rowHeader: 'Account group', total: 'Total', notSet: 'Not set' }

describe('financial pivot', () => {
	it('shows the Omzet row per quarter with its total of 180,000', () => {
		const table = pivotTable(vanDijk, labels)
		expect(table[0]).toEqual([
			'Account group',
			'2026-Q1',
			'2026-Q2',
			'2026-Q3',
			'Total',
		])
		expect(table[2]).toEqual(['Omzet', 60000, 58000, 62000, 180000])
		expect(table[3]).toEqual(['Not set', null, 12.5, null, 12.5])
		expect(table[4]).toEqual(['Total', 30000, 28012.5, 32000, 90012.5])
	})

	it('exports the shown table to CSV with numeric amounts and quoted text', () => {
		const csv = toCsv([
			['Group, name', 'Q1'],
			['Omzet "advies"', 60000],
			['Leeg', null],
		])
		expect(csv).toBe('"Group, name",Q1\n"Omzet ""advies""",60000.00\nLeeg,\n')
	})

	it('exports the shown table to an Excel workbook with number cells', () => {
		const xml = toSpreadsheetXml(pivotTable(vanDijk, labels), 'Financial pivot')
		expect(xml).toContain('<?mso-application progid="Excel.Sheet"?>')
		expect(xml).toContain('<Worksheet ss:Name="Financial pivot">')
		expect(xml).toContain(
			'<Cell><Data ss:Type="String">Omzet</Data></Cell><Cell><Data ss:Type="Number">60000</Data></Cell>',
		)
		expect(xml.match(/<Row>/g)).toHaveLength(5)
	})

	it('opens on this year up to today', () => {
		expect(defaultRange(new Date(2026, 8, 30))).toEqual({
			from: '2026-01-01',
			to: '2026-09-30',
		})
	})

	it('offers the axes the endpoint accepts', () => {
		const service = fs.readFileSync(
			path.resolve(
				__dirname,
				'../../lib/Service/Pivot/LedgerPivotService.php',
			),
			'utf8',
		)
		const declared = service
			.match(/public const AXES = \[([^\]]+)\]/)[1]
			.match(/'([a-zA-Z]+)'/g)
			.map((axis) => axis.replace(/'/g, ''))
		expect(PIVOT_AXES).toEqual(declared)
		const page = fs.readFileSync(
			path.resolve(
				__dirname,
				'../../src/components/reporting/FinancialPivot.vue',
			),
			'utf8',
		)
		for (const axis of PIVOT_AXES) {
			expect(page).toContain(`value: '${axis}'`)
		}
	})
})
