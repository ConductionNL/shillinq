/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the financial pivot table over generated endpoint
 * answers, partial and malformed ones included.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { describe, it } from 'vitest'
import { pivotTable } from '../../src/utils/financialPivot.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('financialPivot')
const LABELS = { rowHeader: 'Account', total: 'Total', notSet: '(not set)' }

/**
 * A generated pivot answer.
 *
 * @param {object} r Random source.
 * @return {object}
 */
function pivot(r) {
	const keys = ['a', 'b', 'c', 'constructor']
	const group = (key) => ({
		key,
		label: r.pick([key.toUpperCase(), null, undefined, '', 0]),
	})
	const columns = keys.filter(() => r.next() > 0.3).map(group)
	const rows = keys.filter(() => r.next() > 0.3).map(group)
	const cells = {}
	for (const row of rows) {
		if (r.next() < 0.2) continue
		if (r.next() < 0.1) {
			cells[row.key] = r.pick([0, '', false])
			continue
		}
		cells[row.key] = {}
		for (const column of columns) {
			if (r.next() > 0.3)
				cells[row.key][column.key] = r.pick([10, 0, -2.5, null, 'x'])
		}
	}
	const totals = (list) =>
		Object.fromEntries(
			list
				.filter(() => r.next() > 0.3)
				.map((g) => [g.key, r.pick([5, 0, null, undefined])]),
		)
	return {
		columns: r.next() < 0.1 ? undefined : columns,
		rows: r.next() < 0.1 ? undefined : rows,
		cells: r.next() < 0.1 ? r.pick([undefined, null]) : cells,
		rowTotals: r.next() < 0.2 ? r.pick([undefined, null]) : totals(rows),
		columnTotals: r.next() < 0.2 ? r.pick([undefined, null]) : totals(columns),
		total: r.pick([100, 0, null, undefined, '', false]),
	}
}

describe('financial pivot golden', () => {
	it('matches the record', () => {
		const r = seeded(11)
		for (let i = 0; i < 150; i++) {
			const p = pivot(r)
			check(
				`${i}`,
				outcome(() => pivotTable(p, LABELS)),
			)
		}
		check(
			'empty',
			outcome(() => pivotTable({}, LABELS)),
		)
		check(
			'rows not iterable',
			outcome(() => pivotTable({ rows: { a: 1 } }, LABELS)),
		)
		check(
			'rows a string',
			outcome(() => pivotTable({ rows: 'ab', columns: [] }, LABELS)),
		)
	})
})
