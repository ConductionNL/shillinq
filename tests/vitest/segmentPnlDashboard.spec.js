/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for SegmentPnLDashboard.vue: the message of each
 * failed segment load, the unknown-segment refusal, the missing-administration
 * refusal the server answers with 400, and the CSV the export downloads. Methods run bound to a fake
 * `this` seeded from the real `data()`.
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SegmentPnLDashboard from '../../src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue'
import { SEGMENTS } from '../../src/utils/segmentResults.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const SEGMENT = SEGMENTS[0]

/**
 * A fake dashboard with its methods bound.
 *
 * @return {object}
 */
function dashboard() {
	const self = { ...SegmentPnLDashboard.data.call({}), periods: ['2026-01'], t: (app, text) => `${app}:${text}` }
	for (const [name, method] of Object.entries(SegmentPnLDashboard.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('SegmentPnLDashboard', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('refuses an unknown segment and a missing administration', async () => {
		const unknown = dashboard()
		await unknown.loadSegment('no-such-segment')
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockRejectedValueOnce({ response: { status: 400 } })
		const unscoped = dashboard()
		await unscoped.loadSegment(SEGMENT)

		expect(axios.get).toHaveBeenCalledOnce()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/segment-results', { params: { segment: SEGMENT } })
		expect([unknown.errorMessage, unknown.loading]).toEqual(['shillinq:Unknown segment selected.', false])
		expect([unscoped.errorMessage, unscoped.loading, unscoped.rows]).toEqual(['shillinq:No active administration, so the segment P&L cannot be scoped.', false, []])
	})

	it('words each failed load', async () => {
		const messages = []
		for (const status of [400, 404, 401, 403, 500]) {
			axios.get.mockRejectedValueOnce({ response: { status } })
			const vm = dashboard()
			await vm.loadSegment(SEGMENT)
			messages.push(vm.errorMessage)
			expect(vm.loading).toBe(false)
		}

		expect(messages).toEqual([
			'shillinq:No active administration, so the segment P&L cannot be scoped.',
			'shillinq:Permission required to read segment P&L data.',
			'shillinq:Permission required to read segment P&L data.',
			'shillinq:Permission required to read segment P&L data.',
			'shillinq:Failed to load segment P&L.',
		])
	})

	it('downloads the rows as CSV, and nothing without rows', async () => {
		const blobs = []
		const anchor = { click: vi.fn() }
		vi.stubGlobal('URL', { createObjectURL: (blob) => { blobs.push(blob); return 'blob:1' }, revokeObjectURL: vi.fn() })
		vi.stubGlobal('document', { createElement: () => anchor, body: { appendChild: vi.fn(), removeChild: vi.fn() } })
		const vm = dashboard()
		vm.activeSegment = 'costCenter'

		vm.exportCsv()
		expect(blobs).toHaveLength(0)

		vm.rows = [
			{ key: 'KP-1', name: 'Advies, "senior"', parent: '', revenue: 10, costs: 2.5, result: 7.5 },
			{ key: 'KP-2', name: 'Beheer', parent: 'KP-1', revenue: 0, costs: 1, result: -1 },
		]
		vm.exportCsv()

		expect(blobs).toHaveLength(1)
		expect(blobs[0].type).toBe('text/csv;charset=utf-8')
		expect(await blobs[0].text()).toBe(
			'segment,name,parent,revenue,costs,result\n'
			+ `${vm.csvEscape('KP-1')},${vm.csvEscape('Advies, "senior"')},${vm.csvEscape('')},10.00,2.50,7.50\n`
			+ `${vm.csvEscape('KP-2')},${vm.csvEscape('Beheer')},${vm.csvEscape('KP-1')},0.00,1.00,-1.00\n`,
		)
		expect(anchor.href).toBe('blob:1')
		expect(anchor.download).toMatch(/^segment-pnl-costCenter-\d{4}-\d{2}-\d{2}\.csv$/)
		expect(anchor.click).toHaveBeenCalledOnce()
	})
})
