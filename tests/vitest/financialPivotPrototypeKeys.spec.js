/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * A pivot group whose key is a name Object.prototype carries (a project or
 * customer called "constructor", say) reads only the endpoint's own cells and
 * totals, never an inherited function.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { describe, expect, it } from 'vitest'
import { pivotTable, toCsv } from '../../src/utils/financialPivot.js'

const labels = { rowHeader: 'Group', total: 'Total', notSet: '(not set)' }

const PROTOTYPE_KEYS = [
	'constructor',
	'toString',
	'hasOwnProperty',
	'valueOf',
	'__proto__',
]

describe('financial pivot with prototype-named keys', () => {
	for (const key of PROTOTYPE_KEYS) {
		it(`a "${key}" column without a cell shows an empty cell and a zero total`, () => {
			const pivot = {
				columns: [{ key, label: key }],
				rows: [{ key: 'r', label: 'Row' }],
				cells: { r: {} },
				rowTotals: { r: 0 },
				columnTotals: {},
				total: 0,
			}
			expect(pivotTable(pivot, labels)).toEqual([
				['Group', key, 'Total'],
				['Row', null, 0],
				['Total', 0, 0],
			])
		})

		it(`a "${key}" row without cells shows empty cells and a zero total`, () => {
			const pivot = {
				columns: [{ key: 'c', label: 'Col' }],
				rows: [{ key, label: key }],
				cells: {},
				rowTotals: {},
				columnTotals: { c: 0 },
				total: 0,
			}
			const table = pivotTable(pivot, labels)
			expect(table[1]).toEqual([key, null, 0])
			expect(toCsv(table)).toBe(
				`Group,Col,Total\n${key},,0.00\nTotal,0.00,0.00\n`,
			)
		})
	}

	it('a "constructor" row without cells does not read the properties of Object', () => {
		const pivot = {
			columns: [
				{ key: 'name', label: 'name' },
				{ key: 'length', label: 'length' },
			],
			rows: [{ key: 'constructor', label: 'constructor' }],
			cells: {},
			rowTotals: {},
			columnTotals: {},
			total: 0,
		}
		expect(pivotTable(pivot, labels)[1]).toEqual(['constructor', null, null, 0])
	})

	it('a "constructor" key with its own values shows them', () => {
		const cells = JSON.parse('{"constructor": {"constructor": 12.5}}')
		const pivot = {
			columns: [{ key: 'constructor', label: 'Project constructor' }],
			rows: [{ key: 'constructor', label: 'Customer constructor' }],
			cells,
			rowTotals: JSON.parse('{"constructor": 12.5}'),
			columnTotals: JSON.parse('{"constructor": 12.5}'),
			total: 12.5,
		}
		expect(pivotTable(pivot, labels)).toEqual([
			['Group', 'Project constructor', 'Total'],
			['Customer constructor', 12.5, 12.5],
			['Total', 12.5, 12.5],
		])
	})
})
