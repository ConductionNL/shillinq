/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetTrendChart's series, categories, ApexCharts options and TOTAAL
 * column: the three named series in both Trend and Cumulative mode, the
 * cumulative Actual/Projected seam read from the trend kinds, the x-axis
 * categories, the colour/stroke/formatter/tooltip/annotation options, the
 * per-row totals that treat unprojectable months as non-contributions, and
 * the shared fetch-once load trigger. The effective-prop resolution and the
 * cumulative-disable rule are covered in budgetTrendChart.spec.js; this file
 * covers the plotting and totals computed properties it does not reach.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-006
 */

import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { formatEurCents } from '../../src/components/budget-charts/budgetChartSeries.js'

const FIXTURE = {
	months: ['2027-01', '2027-02', '2027-03'],
	accounts: [
		{
			accountNumber: '1000',
			accountType: 'revenue',
			trend: {
				'2027-01': { kind: 'actual', amount: 10000 },
				'2027-02': { kind: 'unprojectable', reason: 'insufficient-data' },
				'2027-03': { kind: 'projected', amount: 12000 },
			},
			cumulative: { '2027-01': 10000, '2027-02': 10000, '2027-03': 22000 },
			budgeted: { '2027-01': 9000, '2027-02': 9000, '2027-03': 9000 },
			budgetedCumulative: { '2027-01': 9000, '2027-02': 18000, '2027-03': 27000 },
		},
	],
	ledgerGroups: [],
}

const loadSpy = vi.fn()
vi.mock('../../src/components/budget-charts/useBudgetChartData.js', () => ({
	useBudgetChartData: () => ({
		loading: ref(false),
		data: ref(FIXTURE),
		load: loadSpy,
	}),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {} }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))

const { default: BudgetTrendChart } =
	await import('../../src/components/budget-charts/BudgetTrendChart.vue')

/**
 * A fake `this` with every computed resolved lazily in dependency order and
 * every method bound, mirroring budgetTrendChart.spec.js.
 *
 * @param {object} props Prop/data overrides.
 * @return {object} The bound context.
 */
function context(props = {}) {
	const ctx = {
		scope: 'account',
		id: '1000',
		name: 'Omzet',
		administrationId: 'adm-1',
		range: { from: '2027-01', to: '2027-03' },
		annualBudgetId: null,
		objectData: null,
		mode: 'trend',
		showTable: false,
		...props,
	}
	for (const [name, fn] of Object.entries(BudgetTrendChart.computed)) {
		Object.defineProperty(ctx, name, { get: () => fn.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(BudgetTrendChart.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('BudgetTrendChart series and categories', () => {
	it('names the three line series with the trend-mode data', () => {
		const ctx = context()
		expect(ctx.series).toEqual([
			{ name: 'Actual', type: 'line', data: [100, null, null] },
			{ name: 'Projected', type: 'line', data: [null, null, 120] },
			{ name: 'Begroot', type: 'line', data: [90, 90, 90] },
		])
	})

	it('reads the cumulative Actual/Projected seam from the trend kinds in cumulative mode', () => {
		const ctx = context({ mode: 'cumulative' })
		expect(ctx.actualPoints).toEqual([100, null, null])
		expect(ctx.projectedPoints).toEqual([null, null, 220])
		expect(ctx.budgetedPoints).toEqual([90, 180, 270])
	})

	it('has the requested months as categories', () => {
		expect(context().categories).toEqual(['2027-01', '2027-02', '2027-03'])
	})

	it('lists the unprojectable and partial months', () => {
		expect(context().unprojectableMonthsList).toEqual(['2027-02'])
		expect(context().partialMonthsList).toEqual([])
	})
})

describe('BudgetTrendChart options', () => {
	it('uses three colours and an Actual-solid/Projected-dashed/Begroot-solid stroke', () => {
		const options = context().chartOptions
		expect(options.colors).toEqual([
			'var(--color-success, #46ba61)',
			'color-mix(in srgb, var(--color-success, #46ba61) 55%, transparent)',
			'var(--color-primary-element, #0082c9)',
		])
		expect(options.stroke.dashArray).toEqual([0, 6, 0])
	})

	it('formats the y-axis as euro cents, a null as a dash', () => {
		const options = context().chartOptions
		expect(options.yaxis.labels.formatter(100)).toBe(formatEurCents(10000))
		expect(options.yaxis.labels.formatter(null)).toBe('—')
	})

	it('reads an unprojectable projected tooltip as "Cannot project yet", else euro cents or a dash', () => {
		const formatter = context().chartOptions.tooltip.y.formatter
		expect(formatter(0, { seriesIndex: 1, dataPointIndex: 1 })).toBe('Cannot project yet')
		expect(formatter(100, { seriesIndex: 0, dataPointIndex: 0 })).toBe(formatEurCents(10000))
		expect(formatter(null, { seriesIndex: 0, dataPointIndex: 0 })).toBe('—')
		expect(formatter(120, { seriesIndex: 1, dataPointIndex: 2 })).toBe(formatEurCents(12000))
	})

	it('annotates each unprojectable month with a dash marker', () => {
		const points = context().chartOptions.annotations.points
		expect(points.map((p) => p.x)).toEqual(['2027-02'])
		expect(points[0].label.text).toBe('—')
	})
})

describe('BudgetTrendChart totals and table', () => {
	it('sums only the plotted actual and projected amounts, never a fabricated unprojectable one', () => {
		const ctx = context()
		expect(ctx.actualTotal).toBe(10000)
		expect(ctx.projectedTotal).toBe(12000)
		expect(ctx.budgetedTotal).toBe(27000)
	})

	it('sums nullable cents treating a null as nothing and formats the total', () => {
		const ctx = context()
		expect(ctx.sumCents([100, null, 200, null])).toBe(300)
		expect(ctx.formatTotal(27000)).toBe(formatEurCents(27000))
	})

	it('formats a budget cell, zero euros for a month with no budget', () => {
		const ctx = context()
		expect(ctx.budgetedCellText('2027-01')).toBe(formatEurCents(9000))
		expect(ctx.budgetedCellText('2099-12')).toBe(formatEurCents(0))
	})
})

describe('BudgetTrendChart load', () => {
	it('triggers the shared fetch-once load when ready', () => {
		loadSpy.mockClear()
		context().load()
		expect(loadSpy).toHaveBeenCalledTimes(1)
	})

	it('does not load while the entity or administration is unresolved', () => {
		loadSpy.mockClear()
		expect(context({ id: null, objectData: null }).load()).toBeUndefined()
		expect(loadSpy).not.toHaveBeenCalled()
	})
})
