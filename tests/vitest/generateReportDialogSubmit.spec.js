/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for GenerateReportDialog.vue's generate and schedule
 * actions: the requests, the events, the scheduled message and the error
 * texts. Methods run bound to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import GenerateReportDialog from '../../src/modals/GenerateReportDialog.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake dialog for a P&L report with its methods bound.
 *
 * @return {object}
 */
function dialog() {
	const self = {
		...GenerateReportDialog.data.call({ report: { id: 'pnl', label: 'Winst en verlies', formats: ['pdf'] }, administrations: [] }),
		report: { id: 'pnl', label: 'Winst en verlies' },
		canSubmit: true,
		canSchedule: true,
		emitted: [],
		t: (app, text, vars = {}) => `${app}:` + text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])),
	}
	for (const [name, method] of Object.entries(GenerateReportDialog.methods)) {
		self[name] = method.bind(self)
	}
	self.$emit = (...args) => self.emitted.push(args)
	self.periodLabel = () => 'Q2 2026'
	Object.assign(self.form, { administrationId: 'adm-1', format: 'pdf', periodType: 'quarter', periodYear: 2026, periodNumber: 2 })
	Object.assign(self.schedule, { frequency: 'monthly', runDay: 5, periodRule: 'previous', recipients: 'anna', folderPath: '' })
	return self
}

describe('GenerateReportDialog', () => {
	beforeEach(() => {
		axios.post.mockReset()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-09-29T14:00:00Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('generates the report and emits the result', async () => {
		axios.post.mockResolvedValue({ data: { id: 'rep-1' } })
		const vm = dialog()

		await vm.onSubmit()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/reporting/generate', {
			reportType: 'pnl', administrationId: 'adm-1', format: 'pdf', periodType: 'quarter', periodYear: 2026, periodNumber: 2, period: 'Q2 2026',
		})
		expect([vm.emitted, vm.submitting, vm.error]).toEqual([[['generated', { id: 'rep-1' }]], false, ''])
	})

	it('sends no period number for a year and words a failed generation', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'No data' } } })
		const vm = dialog()
		vm.form.periodType = 'year'

		await vm.onSubmit()

		expect(axios.post.mock.calls[0][1].periodNumber).toBeNull()
		axios.post.mockRejectedValueOnce(new Error('x'))
		const other = dialog()
		await other.onSubmit()
		expect([vm.error, other.error, other.submitting, vm.emitted]).toEqual(['No data', 'Report generation failed', false, []])
	})

	it('saves the schedule, tells its first run and emits it', async () => {
		axios.post.mockResolvedValueOnce({ data: null })
		const vm = dialog()

		await vm.onSchedule()

		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/shillinq/ReportSchedule')
		expect(payload).toMatchObject({ administrationId: 'adm-1', reportType: 'pnl', format: 'pdf', frequency: 'monthly', runDay: 5, nextRunAt: '2026-10-05T00:00:00+00:00' })
		expect([vm.scheduledMessage, vm.emitted]).toEqual(['Scheduled. The first run is on 2026-10-05.', [['scheduled', payload]]])
	})

	it('words a failed schedule, and does nothing when not allowed', async () => {
		axios.post.mockRejectedValueOnce(new Error('x'))
		const vm = dialog()
		await vm.onSchedule()
		const blocked = dialog()
		blocked.canSchedule = false
		blocked.canSubmit = false
		await blocked.onSchedule()
		await blocked.onSubmit()

		expect([vm.error, vm.submitting, axios.post.mock.calls.length]).toEqual(['The schedule could not be saved', false, 1])
	})
})
