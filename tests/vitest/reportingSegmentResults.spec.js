/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-segment-results: the segment dashboard reads OpenRegister's
 * grouped envelope ({ groups: [{ key, values }] }) into revenue, costs and
 * result per segment, asks for one period, and every segment type it offers
 * names an aggregation the register declares with those three figures.
 *
 * @spec openspec/changes/reporting-segment-results/specs/bookkeeping-cost-centers-dimensions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	normaliseSegmentRows,
	SEGMENT_AGGREGATION,
	segmentQuery,
	segmentTotals,
} from '../../src/utils/segmentResults.js'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json'),
		'utf8',
	),
)
const dashboard = fs.readFileSync(
	path.join(ROOT, 'src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue'),
	'utf8',
)

describe('segment results', () => {
	it('reads KP-300 Sociaal Domein from the grouped envelope', () => {
		const rows = normaliseSegmentRows({
			groups: [
				{
					key: 'KP-300',
					'AnalyticalDimension.name': 'Sociaal Domein',
					values: { revenue: 40000, costs: 28000, result: 12000 },
				},
				{ key: null, values: { revenue: 1, costs: 0, result: 1 } },
			],
		})

		expect(rows).toEqual([
			{ key: 'KP-300', name: 'Sociaal Domein', parent: '', revenue: 40000, costs: 28000, result: 12000, depth: 0 },
		])
		expect(segmentTotals(rows)).toEqual({ revenue: 40000, costs: 28000, result: 12000 })
	})

	it('reads a multi-key group and a missing figure as 0', () => {
		const rows = normaliseSegmentRows({ groups: [{ keys: ['P-2026-014'], values: { costs: 500 } }] })

		expect(rows[0]).toMatchObject({ key: 'P-2026-014', revenue: 0, costs: 500, result: 0 })
	})

	it('scopes to the administration and narrows to the chosen period', () => {
		expect(segmentQuery('adm-1', '')).toEqual({ 'filter[administrationId]': 'adm-1' })
		expect(segmentQuery('adm-1', '2026-09')).toEqual({
			'filter[administrationId]': 'adm-1',
			'filter[periodId]': '2026-09',
		})
	})

	it('offers every segment type over a declared aggregation with revenue, costs and result', () => {
		const declared = fragment.components.schemas.GLLine['x-openregister-aggregations']
		for (const name of Object.values(SEGMENT_AGGREGATION)) {
			expect(declared[name], name).toBeDefined()
			expect(declared[name].filter).toMatchObject({ accountClass: 'pnl', countsInResult: true })
			expect(declared[name].metrics.map((m) => m.as)).toEqual(['revenue', 'costs', 'result'])
		}
		expect(Object.keys(SEGMENT_AGGREGATION)).toContain('costObject')
	})

	it('shows the three columns and a period field on the dashboard', () => {
		expect(dashboard).toContain("from '../../../utils/segmentResults.js'")
		expect(dashboard).toContain("t('shillinq', 'Revenue')")
		expect(dashboard).toContain("t('shillinq', 'Costs')")
		expect(dashboard).toContain("t('shillinq', 'Result')")
		expect(dashboard).toContain('type="month"')
	})
})
