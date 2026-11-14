/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The cashflow chart series: realised columns padded past the forecast, the
 * forecast columns padded before it and inserted ahead of the net line.
 */

import { describe, expect, it, vi } from 'vitest'
import CashflowChartWidget from '../../src/components/dashboard/financial/CashflowChartWidget.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {} }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {} }))

/**
 * A fake instance with the widget's methods bound to it.
 *
 * @param {object} state The computed values the series reads.
 * @return {object} The instance.
 */
function widget(state) {
	const vm = { ...state }
	for (const [name, method] of Object.entries(CashflowChartWidget.methods || {})) {
		vm[name] = method.bind(vm)
	}
	return vm
}

const series = (state) => CashflowChartWidget.computed.series.call(widget(state))

describe('cashflow chart series', () => {
	it('is empty without realised figures', () => {
		expect(series({ glSeries: null, months: [], forecast: { months: [] } })).toEqual([])
	})

	it('has three series without a forecast', () => {
		expect(series({
			glSeries: { cashIn: [1, 2], cashOut: [3, 4], cashNet: [-2, -2] },
			months: ['2025-01', '2025-02'],
			forecast: { months: [], cashIn: [], cashOut: [], cashNet: [] },
		})).toEqual([
			{ name: 'Money in', type: 'column', data: [1, 2] },
			{ name: 'Money out', type: 'column', data: [3, 4] },
			{ name: 'Net', type: 'line', data: [-2, -2] },
		])
	})

	it('pads realised and forecast columns and puts the forecast before the net line', () => {
		expect(series({
			glSeries: { cashIn: [1, 2], cashOut: [3, 4], cashNet: [-2, -2] },
			months: ['2025-01', '2025-02'],
			forecast: { months: ['2025-03', '2025-04', '2025-05'], cashIn: [5, 6, 7], cashOut: [8, 9, 10], cashNet: [-3, -3, -3] },
		})).toEqual([
			{ name: 'Money in', type: 'column', data: [1, 2, null, null, null] },
			{ name: 'Money out', type: 'column', data: [3, 4, null, null, null] },
			{ name: 'Forecast in', type: 'column', data: [null, null, 5, 6, 7] },
			{ name: 'Forecast out', type: 'column', data: [null, null, 8, 9, 10] },
			{ name: 'Net', type: 'line', data: [-2, -2, -3, -3, -3] },
		])
	})
})
