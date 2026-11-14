/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the chart widget mixin's month window: the injected
 * date range as a ref, a plain object or missing, and the trailing window
 * it falls back to. The clock is set to local noon.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { afterAll, describe, it, vi } from 'vitest'
import { golden, outcome } from './goldenHarness.js'

vi.mock('../../src/components/dashboard/financial/useFinancialData.js', () => ({
	useFinancialData: () => ({}),
}))

const check = golden('chartWidgetMonths')

afterAll(() => {
	vi.useRealTimers()
})

describe('chart widget months golden', () => {
	it('matches the record', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 10, 3, 12))
		const mod =
			await import('../../src/components/dashboard/financial/chartWidgetMixin.js')
		const mixin = mod.default ?? Object.values(mod).find((v) => v && v.computed)
		const ranges = [
			{ from: '2026-01-10', to: '2026-03-02' },
			{ from: '2026-05-01', to: '2026-01-01' },
			{ from: '', to: '2026-01-01' },
			{ from: '2026-01-01' },
			{ from: 'bad', to: 'worse' },
			null,
			undefined,
			'2026',
			0,
		]
		const injected = [
			...ranges,
			...ranges.map((value) => ({ value })),
			{ value: undefined, from: '2026-01-01', to: '2026-02-01' },
			[],
		]
		injected.forEach((range, i) =>
			check(
				`${i}`,
				outcome(() =>
					mixin.computed.months.call({ cnDashboardDateRange: range }),
				),
			),
		)
	})
})
