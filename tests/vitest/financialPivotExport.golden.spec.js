/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the financial pivot's CSV and SpreadsheetML exports over
 * generated tables with quotes, commas, newlines, markup and odd values.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { describe, it } from 'vitest'
import { toCsv, toSpreadsheetXml } from '../../src/utils/financialPivot.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('financialPivotExport')

const VALUES = [
	null,
	undefined,
	0,
	-2.5,
	1234.567,
	'plain',
	'a,b',
	'say "hi"',
	'line\nbreak',
	'<b>&amp;</b>',
	"it's",
	'',
	true,
	'"',
	',',
	'\n',
	'a\r\nb',
]

/**
 * A generated table.
 *
 * @param {object} r Random source.
 * @return {Array<Array<*>>}
 */
function table(r) {
	const width = r.int(4) + 1
	const height = r.int(4)
	const rows = []
	for (let i = 0; i < height; i++) {
		const row = []
		for (let j = 0; j < width; j++) {
			row.push(r.pick(VALUES))
		}
		rows.push(row)
	}
	return rows
}

describe('financial pivot export golden', () => {
	it('matches the record', () => {
		const r = seeded(23)
		for (let i = 0; i < 200; i++) {
			const t = table(r)
			const name = r.pick(['Pivot', 'a "b" & <c>', 'x'.repeat(40), ''])
			check(
				`${i} csv`,
				outcome(() => toCsv(t)),
			)
			check(
				`${i} xml`,
				outcome(() => toSpreadsheetXml(t, name)),
			)
		}
		check(
			'empty csv',
			outcome(() => toCsv([])),
		)
		check(
			'empty xml',
			outcome(() => toSpreadsheetXml([], 'Empty')),
		)
	})
})
