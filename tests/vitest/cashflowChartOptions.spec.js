/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The cashflow chart forecast, x-axis categories and ApexCharts options: the
 * forecast computed over the cashflow weeks, the realised + forecast month
 * categories, and the colour/stroke/formatter options that widen only when a
 * forecast is present. The series shaping lives in cashflowChartSeries.spec.js;
 * this file covers the forecast, categories and options computed properties.
 */

import { describe, expect, it, vi } from 'vitest'
import CashflowChartWidget from '../../src/components/dashboard/financial/CashflowChartWidget.vue'
import { forecastByMonth, formatEur } from '../../src/components/dashboard/financial/financialSeries.js'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {} }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))

/**
 * Resolve one computed property over a fake `this` whose other computed
 * properties are supplied directly in `state`.
 *
 * @param {string} name The computed property.
 * @param {object} state The values it reads.
 * @return {*} The computed result.
 */
function compute(name, state) {
	return CashflowChartWidget.computed[name].call(state)
}

describe('CashflowChartWidget forecast', () => {
	it('is empty without financial data', () => {
		expect(compute('forecast', { financialData: null, months: [] })).toEqual({
			months: [], cashIn: [], cashOut: [], cashNet: [],
		})
	})

	it('forecasts past the last realised month from the cashflow weeks', () => {
		const cashflowWeeks = [
			{ month: '2025-01', cashIn: 100, cashOut: 40 },
			{ month: '2025-02', cashIn: 120, cashOut: 50 },
			{ month: '2025-03', cashIn: 130, cashOut: 60 },
		]
		const state = { financialData: { cashflowWeeks }, months: ['2025-01', '2025-02'] }
		expect(compute('forecast', state)).toEqual(forecastByMonth(cashflowWeeks, '2025-02'))
	})
})

describe('CashflowChartWidget categories', () => {
	it('appends the forecast months, labelled, to the realised labels', () => {
		const state = {
			monthLabels: ['Jan', 'Feb'],
			forecast: { months: ['2025-03', '2025-04'] },
		}
		const categories = compute('categories', state)
		expect(categories).toHaveLength(4)
		expect(categories.slice(0, 2)).toEqual(['Jan', 'Feb'])
		expect(categories.slice(2).every((c) => typeof c === 'string' && c.length > 0)).toBe(true)
	})
})

describe('CashflowChartWidget options', () => {
	it('uses two column colours and the net line colour without a forecast', () => {
		const state = { forecast: { months: [] }, series: [{ type: 'column' }, { type: 'column' }, { type: 'line' }] }
		const options = compute('options', state)
		expect(options.colors).toEqual(['#46ba61', '#e04224', 'var(--color-primary-element, #0082c9)'])
		expect(options.stroke.width).toEqual([0, 0, 3])
	})

	it('widens the palette with the translucent forecast colours when a forecast is present', () => {
		const state = { forecast: { months: ['2025-03'] }, series: [{ type: 'column' }, { type: 'column' }, { type: 'column' }, { type: 'column' }, { type: 'line' }] }
		const options = compute('options', state)
		expect(options.colors).toEqual([
			'#46ba61', '#e04224', '#46ba6155', '#e0422455',
			'var(--color-primary-element, #0082c9)',
		])
		expect(options.stroke.width).toEqual([0, 0, 0, 0, 3])
	})

	it('formats the y-axis and a non-null tooltip as euros and a null tooltip as a dash', () => {
		const state = { forecast: { months: [] }, series: [{ type: 'line' }] }
		const options = compute('options', state)
		expect(options.yaxis.labels.formatter(1000)).toBe(formatEur(1000))
		expect(options.tooltip.y.formatter(null)).toBe('—')
		expect(options.tooltip.y.formatter(1000)).toBe(formatEur(1000, 2))
	})
})
