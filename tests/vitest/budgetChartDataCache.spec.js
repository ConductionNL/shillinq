/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * useBudgetChartData: one series request per administration, period range
 * and budget on a page visit, shared by every chart opened for that key.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { resetBudgetChartData, useBudgetChartData } from '../../src/components/budget-charts/useBudgetChartData.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const SERIES = '/index.php/apps/shillinq/api/budget-charts/series'
const RANGE = { from: '2026-01', to: '2026-12' }

beforeEach(() => {
	resetBudgetChartData()
	http.get.mockReset()
})

describe('useBudgetChartData', () => {
	it('reads the series once and shares it with every chart for the same key', async () => {
		http.get.mockResolvedValue({ data: { series: [1, 2] } })
		const first = useBudgetChartData('adm-1', RANGE)
		const second = useBudgetChartData('adm-1', { ...RANGE })

		const pending = first.load()
		expect(first.loading.value).toBe(true)
		expect(second.load()).toBe(pending)
		await pending

		expect(http.get).toHaveBeenCalledTimes(1)
		expect(http.get).toHaveBeenCalledWith(SERIES, { params: { administrationId: 'adm-1', from: '2026-01', to: '2026-12' } })
		expect(first.data.value).toEqual({ series: [1, 2] })
		expect(second.data.value).toEqual({ series: [1, 2] })
		expect(first.loading.value).toBe(false)
		expect(first.error.value).toBeNull()

		await first.load()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('names the budget only when one is given', async () => {
		http.get.mockResolvedValue({ data: {} })
		await useBudgetChartData('adm-1', RANGE, 'bud-7').load()
		expect(http.get).toHaveBeenCalledWith(SERIES, {
			params: { administrationId: 'adm-1', from: '2026-01', to: '2026-12', annualBudgetId: 'bud-7' },
		})
	})

	it('drops the cached series and reads again for another key', async () => {
		http.get.mockResolvedValueOnce({ data: { year: 2026 } }).mockResolvedValueOnce({ data: { year: 2025 } })
		const a = useBudgetChartData('adm-1', RANGE)
		await a.load()
		const b = useBudgetChartData('adm-1', { from: '2025-01', to: '2025-12' })
		const pending = b.load()
		expect(b.data.value).toBeNull()
		await pending
		expect(http.get).toHaveBeenCalledTimes(2)
		expect(b.data.value).toEqual({ year: 2025 })
	})

	it('reads again on reload even for the same key', async () => {
		http.get.mockResolvedValue({ data: { n: 1 } })
		const chart = useBudgetChartData('adm-1', RANGE)
		await chart.load()
		await chart.reload()
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('keeps the error and stops loading when the read fails', async () => {
		const failure = new Error('500')
		http.get.mockRejectedValue(failure)
		const chart = useBudgetChartData('adm-1', RANGE)
		await chart.load()
		expect(chart.error.value).toBe(failure)
		expect(chart.loading.value).toBe(false)
		expect(chart.data.value).toBeNull()
	})

	it('tolerates a missing range', async () => {
		http.get.mockResolvedValue({ data: {} })
		await useBudgetChartData('adm-1', undefined).load()
		expect(http.get).toHaveBeenCalledWith(SERIES, { params: { administrationId: 'adm-1', from: undefined, to: undefined } })
	})

	it('resets every shared ref', async () => {
		http.get.mockResolvedValue({ data: { x: 1 } })
		const chart = useBudgetChartData('adm-1', RANGE)
		await chart.load()
		resetBudgetChartData()
		expect(chart.data.value).toBeNull()
		expect(chart.loading.value).toBe(false)
		expect(chart.error.value).toBeNull()
	})
})
