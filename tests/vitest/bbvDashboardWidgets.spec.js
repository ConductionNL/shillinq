/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins what the BBV dashboard widgets compute from the programme rows: the
 * KPI card counts, the compliance pie's buckets, and the programme table's
 * row keys, sort order, money and percentage formatting, status badges and
 * row opening.
 */

import { describe, expect, it, vi } from 'vitest'
import BBVComplianceChart from '../../src/components/Dashboard/BBVComplianceChart.vue'
import BBVKPICards from '../../src/components/Dashboard/BBVKPICards.vue'
import BBVProgrammeTable from '../../src/components/Dashboard/BBVProgrammeTable.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

/**
 * A fake instance of a component with its methods bound and its computed
 * properties readable as getters.
 *
 * @param {object} component The component options.
 * @param {object} state Props and data.
 * @return {object} The instance.
 */
function instance(component, state) {
	const ctx = { ...(component.data ? component.data() : {}), ...state }
	for (const [name, fn] of Object.entries(component.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx), configurable: true })
	}
	return ctx
}

const PROGRAMMES = [
	{ programmeCode: 'P2', complianceStatus: 'on-track' },
	{ programmeCode: 'P1', complianceStatus: 'at-risk' },
	{ programmeCode: 'P3', complianceStatus: 'non-compliant' },
	{ programmeCode: 'P4', complianceStatus: 'on-track' },
	{ programmeCode: 'P5' },
	{ programmeCode: 'P6', complianceStatus: 'retired' },
]

describe('BBVKPICards', () => {
	it('counts every programme and each compliance bucket', () => {
		const cards = instance(BBVKPICards, { programmes: PROGRAMMES })

		expect([cards.totalCount, cards.onTrackCount, cards.atRiskCount, cards.nonCompliantCount]).toEqual([6, 2, 1, 1])
	})

	it('counts nothing without programmes', () => {
		const cards = instance(BBVKPICards, { programmes: [] })

		expect([cards.totalCount, cards.onTrackCount, cards.atRiskCount, cards.nonCompliantCount]).toEqual([0, 0, 0, 0])
	})
})

describe('BBVComplianceChart', () => {
	it('draws the buckets in label order, a missing status as unconfigured and an unknown one not at all', () => {
		const chart = instance(BBVComplianceChart, { programmes: PROGRAMMES })

		expect(chart.series).toEqual([2, 1, 1, 1])
		expect(chart.labels).toEqual(['On-track', 'At-risk', 'Non-compliant', 'Unconfigured'])
		expect(chart.colors).toHaveLength(4)
	})
})

describe('BBVProgrammeTable', () => {
	const rows = [
		{ administrationId: 'a1', fiscalYear: 2026, programmeCode: 'P2', totalBudget: 500, ytdSpend: 10, utilization: 80, complianceStatus: 'at-risk' },
		{ administrationId: 'a1', fiscalYear: 2026, programmeCode: 'P1', totalBudget: 900, ytdSpend: 30, utilization: 95, complianceStatus: 'non-compliant' },
		{ programmeCode: 'P3', totalBudget: null, ytdSpend: 20, utilization: 10, complianceStatus: 'on-track' },
	]

	it('keys each row by administration, year and code', () => {
		const table = instance(BBVProgrammeTable, { programmes: rows })

		expect(table.rows.map((r) => r.rowKey)).toEqual(['a1|2026|P1', 'a1|2026|P2', '||P3'])
	})

	it('sorts by code ascending by default and by the chosen column and direction', () => {
		const table = instance(BBVProgrammeTable, { programmes: rows })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['P1', 'P2', 'P3'])

		table.onSort({ key: 'budget', order: 'desc' })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['P1', 'P2', 'P3'])

		table.onSort({ key: 'ytd', order: 'asc' })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['P2', 'P3', 'P1'])

		table.onSort({ key: 'utilization', order: 'asc' })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['P3', 'P2', 'P1'])

		table.onSort({ key: 'status', order: 'asc' })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['P1', 'P2', 'P3'])
	})

	it('puts rows without a value for the column last', () => {
		const table = instance(BBVProgrammeTable, {
			programmes: [{ programmeCode: 'B', programmeName: null }, { programmeCode: 'A', programmeName: 'Water' }, { programmeCode: 'C' }],
		})

		table.onSort({ key: 'programmeName', order: 'asc' })
		expect(table.rows.map((r) => r.programmeCode)).toEqual(['B', 'C', 'A'])
	})

	it('ranks statuses from non-compliant to unknown', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })

		expect(['non-compliant', 'at-risk', 'on-track', 'unconfigured', 'other'].map((s) => table.sortValue({ complianceStatus: s }, 'status')))
			.toEqual([0, 1, 2, 3, 4])
		expect(table.sortValue({}, 'budget')).toBe(0)
	})

	it('formats cents as whole euros and a missing amount as a dash', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })

		expect(table.formatEuro(123456)).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(1234.56))
		expect(table.formatEuro(null)).toBe('—')
		expect(table.formatEuro(undefined)).toBe('—')
		expect(table.formatEuro('abc')).toBe('—')
	})

	it('formats a percentage, falls back to the ratio, and shows a dash for neither', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })

		expect(table.formatPercentage(80.25, null)).toBe('80.3 %')
		expect(table.formatPercentage(null, 0.5)).toBe('50.0 %')
		expect(table.formatPercentage(undefined, undefined)).toBe('—')
		expect(table.formatPercentage('n/a', null)).toBe('—')
	})

	it('gives each status its class, emoji, label and tooltip', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })
		const statuses = ['non-compliant', 'at-risk', 'on-track', 'unconfigured', undefined]

		expect(statuses.map((s) => table.utilizationClass(s))).toEqual([
			'bbv-programme-table__util--danger', 'bbv-programme-table__util--warning', 'bbv-programme-table__util--ok',
			'bbv-programme-table__util--muted', 'bbv-programme-table__util--muted',
		])
		expect(statuses.map((s) => table.badgeClass(s))).toEqual([
			'bbv-programme-table__badge--danger', 'bbv-programme-table__badge--warning', 'bbv-programme-table__badge--ok',
			'bbv-programme-table__badge--muted', 'bbv-programme-table__badge--muted',
		])
		expect(statuses.map((s) => table.badgeEmoji(s))).toEqual(['🔴', '🟡', '🟢', '⚪', '⚪'])
		expect(statuses.map((s) => table.badgeLabel(s))).toEqual(['Non-compliant', 'At-risk', 'On-track', 'Unconfigured', 'Unconfigured'])
		expect(statuses.map((s) => table.badgeTooltip(s))).toEqual([
			'Spend already exceeds the on-track threshold',
			'Projected to exceed budget — review allocations',
			'Programme spend is within the on-track band',
			'No GL allocations configured for this programme',
			'Programme spend is within the on-track band',
		])
	})

	it('emits the clicked row and opens its budget mapping', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })
		table.$emit = vi.fn()
		table.$router = { push: vi.fn() }

		table.openRow({ programmeCode: 'P1' })

		expect(table.$emit).toHaveBeenCalledWith('row-click', { programmeCode: 'P1' })
		expect(table.$router.push).toHaveBeenCalledWith({ name: 'BudgetMappingDetail', params: { id: 'P1' } })
	})

	it('still emits when the route is missing or the row has no code', () => {
		const table = instance(BBVProgrammeTable, { programmes: [] })
		table.$emit = vi.fn()
		table.$router = { push: vi.fn(() => { throw new Error('no such route') }) }

		expect(() => table.openRow({ programmeCode: 'P1' })).not.toThrow()
		table.openRow({})

		expect(table.$emit).toHaveBeenCalledTimes(2)
		expect(table.$router.push).toHaveBeenCalledTimes(1)
	})
})
