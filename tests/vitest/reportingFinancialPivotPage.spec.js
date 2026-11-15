/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for FinancialPivot.vue: the request it sends for the chosen axes
 * and range, the error it shows, the table it builds with the axis label and
 * the totals, and the file it hands the browser. The SFC's methods and
 * computed are invoked bound to a fake `this`; only axios and the browser's
 * download primitives are doubled.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FinancialPivot from '../../src/components/reporting/FinancialPivot.vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

/** A pivot answer in the endpoint's shape. */
const PIVOT = {
	rowAxis: 'accountGroup',
	columnAxis: 'quarter',
	rows: [{ key: 'omzet', label: 'Omzet' }, { key: 'none', label: null }],
	columns: [{ key: 'Q1', label: 'Q1' }, { key: 'Q2', label: 'Q2' }],
	cells: { omzet: { Q1: 100, Q2: 50 }, none: { Q2: -20 } },
	rowTotals: { omzet: 150, none: -20 },
	columnTotals: { Q1: 100, Q2: 30 },
	total: 130,
	capped: { rows: false, columns: false },
	truncated: false,
}

/**
 * A `this` seeded with the real data(), the methods and the computed.
 *
 * @param {object} [overrides] Fields to override.
 * @return {object} The context.
 */
function ctx(overrides = {}) {
	const self = { ...FinancialPivot.data.call({}), ...FinancialPivot.methods, ...overrides }
	Object.defineProperty(self, 'axes', { get: () => FinancialPivot.computed.axes.call(self) })
	Object.defineProperty(self, 'table', { get: () => FinancialPivot.computed.table.call(self) })
	return self
}

beforeEach(() => {
	vi.restoreAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('FinancialPivot data', () => {
	it('starts on account groups by quarter for a two-date range', () => {
		const data = FinancialPivot.data.call({})
		expect(data.rowAxis).toBe('accountGroup')
		expect(data.columnAxis).toBe('quarter')
		expect(data.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(data.to).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(data.pivot).toBeNull()
	})

	it('offers the seven axes the endpoint accepts', () => {
		expect(ctx().axes.map((axis) => axis.value)).toEqual([
			'account', 'accountGroup', 'period', 'quarter', 'costCenter', 'project', 'customer',
		])
	})
})

describe('FinancialPivot load', () => {
	it('asks for the chosen axes and range and keeps the answer', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: PIVOT })
		const self = ctx({ rowAxis: 'account', columnAxis: 'period', from: '2026-01-01', to: '2026-06-30' })

		await self.load()

		expect(get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/analysis/pivot', {
			params: { rows: 'account', columns: 'period', from: '2026-01-01', to: '2026-06-30' },
		})
		expect(self.pivot).toBe(PIVOT)
		expect(self.loading).toBe(false)
		expect(self.error).toBe('')
	})

	it('shows the server error and drops an earlier pivot', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { error: 'Range too long' } } })
		const self = ctx({ pivot: PIVOT })

		await self.load()

		expect(self.pivot).toBeNull()
		expect(self.error).toBe('Range too long')
		expect(self.loading).toBe(false)
	})

	it('falls back to its own message when the server names none', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('network'))
		const self = ctx()

		await self.load()

		expect(self.error).toBe('The pivot could not be loaded.')
	})
})

describe('FinancialPivot table', () => {
	it('is empty before a pivot is loaded', () => {
		expect(ctx().table).toEqual([])
	})

	it('heads the rows with the row axis label and adds totals', () => {
		expect(ctx({ pivot: PIVOT }).table).toEqual([
			['Account group', 'Q1', 'Q2', 'Total'],
			['Omzet', 100, 50, 150],
			['Not set', null, -20, -20],
			['Total', 100, 30, 130],
		])
	})

	it('leaves the row header blank for an axis it does not offer', () => {
		expect(ctx({ pivot: { ...PIVOT, rowAxis: 'unknown' } }).table[0][0]).toBe('')
	})
})

describe('FinancialPivot formatAmount', () => {
	it('keeps an empty cell empty and formats an amount as euros', () => {
		const self = ctx()
		expect(self.formatAmount(null)).toBe('')
		expect(self.formatAmount(undefined)).toBe('')
		const shown = self.formatAmount(12.5)
		expect(shown).toContain('12')
		expect(shown).toMatch(/€|EUR/)
	})
})

describe('FinancialPivot exports', () => {
	/**
	 * Capture what the page hands the browser.
	 *
	 * @return {object} The captured blob parts, link and revoke calls.
	 */
	function captureDownload() {
		const captured = { parts: null, type: null, link: null, revoked: [] }
		vi.stubGlobal('Blob', class {
			constructor(parts, options) {
				captured.parts = parts
				captured.type = options.type
			}
		})
		vi.stubGlobal('URL', {
			createObjectURL: () => 'blob:1',
			revokeObjectURL: (url) => captured.revoked.push(url),
		})
		const link = { click: vi.fn() }
		vi.stubGlobal('document', { createElement: () => link })
		captured.link = link
		return captured
	}

	it('downloads the shown table as CSV named after the axes and range', () => {
		const captured = captureDownload()
		const self = ctx({ pivot: PIVOT, from: '2026-01-01', to: '2026-03-31' })

		self.exportCsv()

		expect(captured.type).toBe('text/csv')
		expect(captured.parts[0]).toBe('Account group,Q1,Q2,Total\nOmzet,100.00,50.00,150.00\nNot set,,-20.00,-20.00\nTotal,100.00,30.00,130.00\n')
		expect(captured.link.href).toBe('blob:1')
		expect(captured.link.download).toBe('pivot-accountGroup-quarter-2026-01-01-2026-03-31.csv')
		expect(captured.link.click).toHaveBeenCalledOnce()
		expect(captured.revoked).toEqual(['blob:1'])
	})

	it('downloads the shown table as an Excel workbook', () => {
		const captured = captureDownload()
		const self = ctx({ pivot: PIVOT, from: '2026-01-01', to: '2026-03-31' })

		self.exportExcel()

		expect(captured.type).toBe('application/vnd.ms-excel')
		expect(captured.parts[0]).toContain('Financial pivot')
		expect(captured.parts[0]).toContain('Omzet')
		expect(captured.link.download).toBe('pivot-accountGroup-quarter-2026-01-01-2026-03-31.xls')
	})
})
