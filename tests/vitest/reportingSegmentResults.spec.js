/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-segment-results: the segment dashboard reads OpenRegister's
 * grouped envelope ({ groups: [{ key, values }] }) into revenue, costs and
 * result per segment and asks the server for one segment and period.
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	normaliseSegmentRows,
	periodOptions,
	segmentQuery,
	SEGMENTS,
	segmentTotals,
} from '../../src/utils/segmentResults.js'

const ROOT = path.resolve(__dirname, '../..')
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
			{
				key: 'KP-300',
				name: 'Sociaal Domein',
				parent: '',
				revenue: 40000,
				costs: 28000,
				result: 12000,
				depth: 0,
			},
		])
		expect(segmentTotals(rows)).toEqual({
			revenue: 40000,
			costs: 28000,
			result: 12000,
		})
	})

	it('reads a multi-key group and a missing figure as 0', () => {
		const rows = normaliseSegmentRows({
			groups: [{ keys: ['P-2026-014'], values: { costs: 500 } }],
		})

		expect(rows[0]).toMatchObject({
			key: 'P-2026-014',
			revenue: 0,
			costs: 500,
			result: 0,
		})
	})

	// The server picks and checks the administration, so the client never names one.
	it('asks for the segment and narrows to the chosen period', () => {
		expect(segmentQuery('costCenter', '')).toEqual({ segment: 'costCenter' })
		expect(segmentQuery('project', '2026-M09')).toEqual({
			segment: 'project',
			periodId: '2026-M09',
		})
	})

	// GLLine.periodId holds the FiscalPeriod's own periodId (2026-M09, 2026-Q3),
	// which the administration chooses: a month picker's 2026-09 matches no line.
	it("offers the administration's fiscal periods by their periodId, oldest first", () => {
		const payload = {
			results: [
				{
					periodId: '2026-M10',
					name: 'October 2026',
					startDate: '2026-10-01',
				},
				{
					periodId: '2026-M09',
					name: 'September 2026',
					startDate: '2026-09-01',
				},
				{ periodId: '', name: 'broken' },
			],
		}
		expect(periodOptions(payload)).toEqual([
			{ id: '2026-M09', label: 'September 2026' },
			{ id: '2026-M10', label: 'October 2026' },
		])
		expect(periodOptions(null)).toEqual([])
		expect(periodOptions(payload.results)).toHaveLength(2)
	})

	// The segment to aggregation map lives on the server and is pinned in
	// AdministrationResultsServiceTest against the declarations.
	it('offers every segment type the server maps', () => {
		expect(SEGMENTS).toEqual([
			'costCenter',
			'costCenterHierarchy',
			'costObject',
			'project',
			'analyticalDimension',
		])
	})

	it('shows the three columns and a period field on the dashboard', () => {
		expect(dashboard).toContain("from '../../../utils/segmentResults.js'")
		expect(dashboard).toContain("t('shillinq', 'Revenue')")
		expect(dashboard).toContain("t('shillinq', 'Costs')")
		expect(dashboard).toContain("t('shillinq', 'Result')")
		expect(dashboard).not.toContain('type="month"')
		expect(dashboard).toContain('v-for="period in periods"')
	})
})
