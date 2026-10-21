/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Four budget and ledger flows that were broken: generated report downloads,
 * the programme drill-through, the trend chart on an empty timeline and the
 * trial balance link of a period.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {} }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const { default: BBVTrendChart } = await import(
	'../../src/components/Dashboard/BBVTrendChart.vue'
)

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const shell = JSON.parse(read('src/manifest.d.shell.json'))
const pageIds = new Set()
function collect (node) {
	if (Array.isArray(node)) {
		node.forEach(collect)
	} else if (node && typeof node === 'object') {
		if (typeof node.id === 'string' && typeof node.route === 'string') {
			pageIds.add(node.id)
		}
		Object.values(node).forEach(collect)
	}
}
collect(JSON.parse(read('src/manifest.json')).pages)
collect(shell)

describe('budget and ledger flows', () => {
	it('fills the table columns of generated reports, download included', () => {
		const index = read('src/components/reporting/GeneratedReportsIndex.vue')
		expect(index).not.toContain('#cell-')
		expect(index).toContain('#column-download')
		expect(index).toContain(':emptyText=')
	})

	it('opens a programme on the allocation index, filtered by its code', () => {
		const table = read('src/components/Dashboard/BBVProgrammeTable.vue')
		expect(table).toContain("name: 'BudgetBBVMappings'")
		expect(table).toContain('query: { programmeCode: row.programmeCode }')
		expect(pageIds.has('BudgetBBVMappings')).toBe(true)
		expect(pageIds.has('BudgetMappingDetail')).toBe(false)
		expect(read('src/registry.js')).not.toMatch(/\bBudgetMappingDetail:/)
	})

	it('draws no line when no month has been recorded', () => {
		const series = BBVTrendChart.computed.series.call({
			timeline: [],
			programmes: [{ programmeCode: 'P1', ytdSpend: 120000 }],
			seriesFromTimeline: BBVTrendChart.methods.seriesFromTimeline,
		})
		expect(series).toEqual([])
		expect(BBVTrendChart.methods.seriesFromCurrentYtd).toBeUndefined()
	})

	it('draws the recorded months when there are some', () => {
		const context = {
			timeline: [{ programmeCode: 'P1', month: 2, cumulativeSpendCents: 5000 }],
			programmes: [{ programmeCode: 'P1', programmeName: 'Water' }],
		}
		context.seriesFromTimeline = BBVTrendChart.methods.seriesFromTimeline.bind(context)
		const series = BBVTrendChart.computed.series.call(context)
		expect(series).toHaveLength(1)
		expect(series[0].name).toBe('P1 Water')
		expect(series[0].data[1]).toBe(50)
	})

	it('links a period to the trial balance page inside the app', () => {
		const detail = read('src/components/period-close/PeriodCloseDetail.vue')
		expect(detail).not.toContain('/apps/shillinq/trial-balance')
		expect(detail).toContain(":to=\"{ name: 'TrialBalance' }\"")
		expect(pageIds.has('TrialBalance')).toBe(true)
	})
})
