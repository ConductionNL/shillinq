/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the series the BBV trend chart draws from the monthly timeline: the
 * grouping per programme, the month clamping, cents to euros, the names and
 * the programmes without a timeline.
 */

import { describe, expect, it, vi } from 'vitest'
import BBVTrendChart from '../../src/components/Dashboard/BBVTrendChart.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

/**
 * The series computed on a fake instance.
 *
 * @param {Array} programmes The programmes prop.
 * @param {Array} timeline The timeline prop.
 * @return {Array}
 */
function series(programmes, timeline) {
	const ctx = { programmes, timeline }
	for (const [name, fn] of Object.entries(BBVTrendChart.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return BBVTrendChart.computed.series.call(ctx)
}

/**
 * Twelve months with the given values set.
 *
 * @param {object} set Month index to value.
 * @return {Array<number>}
 */
function months(set) {
	const data = new Array(12).fill(0)
	for (const [i, v] of Object.entries(set)) data[i] = v
	return data
}

describe('BBVTrendChart — series from the timeline', () => {
	it('groups, clamps and converts the rows per programme in programme order', () => {
		const out = series(
			[
				{ programmeCode: 'P2' },
				{ programmeCode: '' },
				{ programmeCode: 'P1', programmeName: 'Youth' },
				{ programmeCode: 'P3', programmeName: 'Care' },
			],
			[
				{ programmeCode: 'P1', month: 1, cumulativeSpendCents: 12345 },
				{ code: 'P1', month: 3, cumulativeSpendCents: '500' },
				{ programmeCode: 'P1', month: 3, cumulativeSpendCents: 700 },
				{ programmeCode: 'P2', month: 0, cumulativeSpendCents: 100 },
				{ programmeCode: 'P2', month: 14, cumulativeSpendCents: 'x' },
				{ programmeCode: 'P2', month: -5, cumulativeSpendCents: null },
				{ month: 4, cumulativeSpendCents: 999 },
				{ programmeCode: 'P9', month: 2, cumulativeSpendCents: 1 },
			],
		)
		expect(out).toEqual([
			{ name: 'P2', data: months({ 0: 0, 11: 0 }) },
			{ name: 'P1 — Youth', data: months({ 0: 123.45, 2: 7 }) },
			{ name: 'P3 — Care', data: months({}) },
		])
	})

	it('puts a row without a month in January and a non-numeric month on its own key', () => {
		const [p] = series([{ programmeCode: 'P1' }], [
			{ programmeCode: 'P1', cumulativeSpendCents: 200 },
			{ programmeCode: 'P1', month: 'x', cumulativeSpendCents: 300 },
		])
		expect(p.data[0]).toBe(2)
		expect(p.data.NaN).toBe(3)
		expect(p.data.length).toBe(12)
	})

	it('falls back to the year-to-date line without a timeline', () => {
		const out = series([{ programmeCode: 'P1', programmeName: 'Youth', ytdSpend: 1200 }], [])
		expect(out).toHaveLength(1)
		expect(out[0].name).toBe('P1 — Youth')
		const m = new Date().getMonth()
		expect(out[0].data[m]).toBe(12)
		expect(out[0].data.filter((v) => v === null)).toHaveLength(11 - m)
	})
})
