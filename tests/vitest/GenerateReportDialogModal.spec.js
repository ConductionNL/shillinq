/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The report generation dialog: what it renders for each period type, with
 * and without administrations and while scheduling (server-side, no DOM),
 * and what generating and scheduling send and do with the answer.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? vars[key] : match)),
}))

const { default: GenerateReportDialog } = await import('../../src/modals/GenerateReportDialog.vue')

const REPORT = { id: 'balance-sheet', label: 'Balans', formats: ['pdf', 'xlsx'] }

/**
 * Render the dialog with props and part of its data replaced.
 *
 * @param {object} props The props.
 * @param {object} state Data to lay over its own.
 * @return {Promise<string>} The HTML.
 */
async function render(props = {}, state = {}) {
	const full = { report: REPORT, ...props }
	const view = {
		...GenerateReportDialog,
		data() {
			const own = GenerateReportDialog.data.call(this)
			return { ...own, ...state, form: { ...own.form, ...(state.form || {}) }, schedule: { ...own.schedule, ...(state.schedule || {}) } }
		},
	}
	return (await renderToString(createSSRApp(view, full))).replace(/<!--[[\]]-->/g, '')
}

/**
 * A plain instance of the dialog.
 *
 * @param {object} props The props.
 * @param {object} state Data to set after its own.
 * @return {object} The instance.
 */
function dialog(props = {}, state = {}) {
	const self = { report: REPORT, format: '', administrationOptions: [], defaultAdministrationId: '', ...props, $emit: vi.fn() }
	for (const [name, method] of Object.entries(GenerateReportDialog.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	Object.assign(self, GenerateReportDialog.data.call(self))
	Object.assign(self.form, state.form || {})
	Object.assign(self.schedule, state.schedule || {})
	Object.assign(self, { ...state, form: self.form, schedule: self.schedule })
	for (const [name, getter] of Object.entries(GenerateReportDialog.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

beforeEach(() => {
	axios.post.mockReset()
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-11-13T10:00:00Z'))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('GenerateReportDialog renders', () => {
	it('a free administration field without options, the year and the report formats', async () => {
		const html = await render()
		expect(html).toContain('Balans')
		expect(html).toMatch(/<input id="generate-report-administration"[^>]*type="text"/)
		expect(html).toMatch(/id="generate-report-year"[^>]*value="2026"/)
		expect(html).toContain('>PDF</option>')
		expect(html).toContain('>XLSX</option>')
		expect(html).not.toContain('generate-report-period-number')
		expect(html).toMatch(/<button[^>]*disabled[^>]*data-testid="generate-report-dialog-submit"[^>]*>\s*Generate/)
		expect(html).toContain('data-testid="generate-report-dialog-schedule"')
	})

	it('the administrations as options, with the default chosen', async () => {
		const html = await render({ administrationOptions: [{ value: 'a-1', label: 'Gemeente Utrecht' }], defaultAdministrationId: 'a-1' })
		expect(html).toMatch(/<select id="generate-report-administration"/)
		expect(html).toMatch(/<option value="a-1"[^>]*selected[^>]*>Gemeente Utrecht<\/option>/)
		expect(html).toMatch(/<button[^>]*data-testid="generate-report-dialog-submit"/)
		expect(html).not.toMatch(/<button[^>]*disabled[^>]*data-testid="generate-report-dialog-submit"/)
	})

	it('four quarters or twelve months to pick from', async () => {
		const quarter = await render({}, { form: { periodType: 'quarter' } })
		expect(quarter).toMatch(/for="generate-report-period-number"[^>]*>\s*Quarter/)
		expect(quarter.match(/<option value="\d+"[^>]*>\d+<\/option>/g)).toHaveLength(4)
		const month = await render({}, { form: { periodType: 'month' } })
		expect(month).toMatch(/for="generate-report-period-number"[^>]*>\s*Month/)
		expect(month.match(/<option value="\d+"[^>]*>\d+<\/option>/g)).toHaveLength(12)
	})

	it('the schedule fields, with a weekday for a weekly run, the notice and the error', async () => {
		const html = await render({}, { scheduling: true, schedule: { frequency: 'weekly' }, scheduledMessage: 'Scheduled. The first run is on 2026-11-16.', error: 'Nope', submitting: true })
		expect(html).toContain('data-testid="generate-report-schedule"')
		expect(html).toContain('Weekday (1 Monday to 7 Sunday)')
		expect(html).toMatch(/id="schedule-report-run-day"[^>]*max="7"/)
		expect(html).toContain('data-testid="generate-report-dialog-save-schedule"')
		expect(html).not.toContain('generate-report-dialog-submit')
		expect(html).toMatch(/data-testid="generate-report-scheduled"[^>]*>\s*Scheduled. The first run is on 2026-11-16./)
		expect(html).toMatch(/data-testid="generate-report-dialog-error"[^>]*>\s*Nope/)
		expect(await render({}, { scheduling: true })).toMatch(/id="schedule-report-run-day"[^>]*max="28"/)
	})

	it('Generating… while submitting', async () => {
		expect(await render({}, { submitting: true })).toMatch(/<button[^>]*disabled[^>]*data-testid="generate-report-dialog-submit"[^>]*>\s*Generating…/)
	})
})

describe('GenerateReportDialog form', () => {
	it('starts from the given format, else the report\'s first', () => {
		expect(dialog({ format: 'xlsx' }).form.format).toBe('xlsx')
		expect(dialog().form.format).toBe('pdf')
		expect(dialog({ report: { id: 'x' } }).form.format).toBe('')
	})

	it('can only be submitted with an administration, a format, a year and, below a year, a period', () => {
		expect(dialog({}, { form: { administrationId: 'a-1' } }).canSubmit).toBe(true)
		expect(dialog({}, { form: { administrationId: 'a-1' }, submitting: true }).canSubmit).toBe(false)
		expect(dialog().canSubmit).toBe(false)
		expect(dialog({ report: { id: 'x' } }, { form: { administrationId: 'a-1' } }).canSubmit).toBe(false)
		expect(dialog({}, { form: { administrationId: 'a-1', periodYear: 0 } }).canSubmit).toBe(false)
		expect(dialog({}, { form: { administrationId: 'a-1', periodType: 'month', periodNumber: 0 } }).canSubmit).toBe(false)
	})

	it('can only be scheduled with an administration, a format and a run day', () => {
		expect(dialog({}, { form: { administrationId: 'a-1' } }).canSchedule).toBe(true)
		expect(dialog({}, { form: { administrationId: 'a-1' }, schedule: { runDay: 0 } }).canSchedule).toBe(false)
		expect(dialog().canSchedule).toBe(false)
	})

	it('names the period as a year, a quarter or a zero-padded month', () => {
		expect(dialog({}, { form: { periodYear: 2025 } }).periodLabel()).toBe('2025')
		expect(dialog({}, { form: { periodYear: 2025, periodType: 'quarter', periodNumber: 3 } }).periodLabel()).toBe('2025-Q3')
		expect(dialog({}, { form: { periodYear: 2025, periodType: 'month', periodNumber: 4 } }).periodLabel()).toBe('2025-04')
	})

	it('cancels unless submitting', () => {
		const busy = dialog({}, { submitting: true })
		busy.onCancel()
		expect(busy.$emit).not.toHaveBeenCalled()
		const idle = dialog()
		idle.onCancel()
		expect(idle.$emit).toHaveBeenCalledWith('close')
	})
})

describe('GenerateReportDialog generate', () => {
	it('sends the report, administration, format and period, and hands the answer on', async () => {
		const vm = dialog({}, { form: { administrationId: 'a-1', periodType: 'quarter', periodNumber: 2, periodYear: 2026 } })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.submitting).toBe(true)
			return { data: { id: 'r-1', downloadUrl: '/d', fileName: 'balans.pdf' } }
		})
		await vm.onSubmit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/reporting/generate', {
			reportType: 'balance-sheet', administrationId: 'a-1', format: 'pdf', periodType: 'quarter', periodYear: 2026, periodNumber: 2, period: '2026-Q2',
		})
		expect(vm.$emit).toHaveBeenCalledWith('generated', { id: 'r-1', downloadUrl: '/d', fileName: 'balans.pdf' })
		expect(vm.submitting).toBe(false)
	})

	it('sends no period number for a year, and an empty answer as an empty result', async () => {
		axios.post.mockResolvedValue({})
		const vm = dialog({}, { form: { administrationId: 'a-1' } })
		await vm.onSubmit()
		expect(axios.post.mock.calls[0][1].periodNumber).toBeNull()
		expect(vm.$emit).toHaveBeenCalledWith('generated', {})
	})

	it('does not send an incomplete form', async () => {
		await dialog().onSubmit()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows the server error, else a fallback, and stays open', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'No ledger for 2026' } } }).mockRejectedValueOnce(new Error('x'))
		const vm = dialog({}, { form: { administrationId: 'a-1' } })
		await vm.onSubmit()
		expect(vm.error).toBe('No ledger for 2026')
		await vm.onSubmit()
		expect(vm.error).toBe('Report generation failed')
		expect(vm.$emit).not.toHaveBeenCalled()
	})
})

describe('GenerateReportDialog schedule', () => {
	it('saves a schedule object and says when it first runs', async () => {
		axios.post.mockResolvedValue({ data: { id: 's-1' } })
		const vm = dialog({}, { form: { administrationId: 'a-1' }, schedule: { recipients: 'group:controllers' } })
		await vm.onSchedule()
		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/shillinq/ReportSchedule')
		expect(payload).toMatchObject({ administrationId: 'a-1', reportType: 'balance-sheet', name: 'Balans', format: 'pdf', frequency: 'monthly', runDay: 1, periodRule: 'previous-period', status: 'active' })
		expect(vm.scheduledMessage).toBe(`Scheduled. The first run is on ${payload.nextRunAt.slice(0, 10)}.`)
		expect(vm.$emit).toHaveBeenCalledWith('scheduled', { id: 's-1' })
	})

	it('hands the payload on when the answer is empty', async () => {
		axios.post.mockResolvedValue({})
		const vm = dialog({}, { form: { administrationId: 'a-1' } })
		await vm.onSchedule()
		expect(vm.$emit.mock.calls[0][0]).toBe('scheduled')
		expect(vm.$emit.mock.calls[0][1].reportType).toBe('balance-sheet')
	})

	it('does not save without an administration, and shows the fallback on failure', async () => {
		await dialog().onSchedule()
		expect(axios.post).not.toHaveBeenCalled()
		axios.post.mockRejectedValueOnce(new Error('x'))
		const vm = dialog({}, { form: { administrationId: 'a-1' } })
		await vm.onSchedule()
		expect(vm.error).toBe('The schedule could not be saved')
		expect(vm.scheduledMessage).toBe('')
	})
})
