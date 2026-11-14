/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetBBVMappingDetail rendered from its real template, plus the small
 * computed / method branches the existing specs leave open: the delete
 * button only on an existing mapping, the picker hints, the allocation
 * feedback colour, the save error, the picker and dialog bindings, the
 * debounced projection, the summaries' fallbacks and the list-envelope
 * shapes the projection reads.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/renderWithStandIns.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m)) }))

const { default: BudgetBBVMappingDetail } = await import('../../src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue')

const CHILDREN = ['CnDetailPage', 'GlAccountPicker', 'BBVProgrammePicker', 'DeleteBudgetMappingDialog']
const originalGet = axios.get

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<!--[^>]*-->/g, '').replace(/<[^>]+>/g, ' ').replace(/[ \t\r\n]+/g, ' ').trim()

/**
 * Render the detail page.
 *
 * @param {object} props The props.
 * @param {object} [data] Data to preset.
 * @return {Promise<{html: string, vm: object, seen: object}>} The result.
 */
function render(props, data = {}) {
	return renderWithStandIns(BudgetBBVMappingDetail, { props, data, record: CHILDREN })
}

/**
 * A fake component `this` with the methods bound and the named computed
 * properties live.
 *
 * @param {object} state Fields.
 * @return {object} The context.
 */
function ctx(state = {}) {
	const c = {
		id: 'm-1',
		administrationId: '',
		form: { glAccountNumber: '', programmeCode: '', allocationPercentage: 0, effectiveFrom: '2027-01-01', effectiveTo: '', status: 'active', administrationId: '' },
		allocationFeedback: { message: '', severity: 'info' },
		allocationCheckTimer: null,
		...state,
	}
	for (const [name, fn] of Object.entries(BudgetBBVMappingDetail.methods)) {
		c[name] = fn.bind(c)
	}
	for (const name of ['isCreate', 'recordId', 'fiscalYearOfMapping', 'glAccountSummary']) {
		Object.defineProperty(c, name, { get: () => BudgetBBVMappingDetail.computed[name].call(c), configurable: true })
	}
	return c
}

afterEach(() => {
	axios.get = originalGet
	vi.useRealTimers()
})

describe('BudgetBBVMappingDetail template', () => {
	it('offers no delete on a new mapping and labels the save Create', async () => {
		const { html, seen } = await render({ id: 'new' })

		expect(html).not.toContain('bbv-mapping-detail-delete')
		expect(text(html.match(/<button[^>]*bbv-mapping-detail-save[^>]*>(.*?)<\/button>/s)[1])).toBe('Create')
		expect(seen.CnDetailPage[0].objectId).toBe('')
		expect(seen.CnDetailPage[0].title).toBe('New Budget Mapping')
	})

	it('offers delete on an existing mapping, disabled while saving', async () => {
		const { html, seen } = await render({ id: 'm-7' }, { saving: true })
		const del = html.match(/<button[^>]*bbv-mapping-detail-delete[^>]*>/)[0]

		expect(del).toContain('disabled')
		expect(seen.CnDetailPage[0].objectId).toBe('m-7')
	})

	it('shows the account and programme hints once both are chosen', async () => {
		const { html } = await render({ id: 'new' }, {
			selectedAccount: { accountName: 'Personeel', accountType: 'expenses' },
			selectedProgramme: { programmeCode: 'P01', programmeName: 'Veiligheid' },
		})

		expect(text(html.match(/data-testid="bbv-mapping-detail-gl-hint"[^>]*>(.*?)<\/p>/s)[1])).toBe('Personeel · expenses')
		expect(text(html.match(/data-testid="bbv-mapping-detail-programme-hint"[^>]*>(.*?)<\/p>/s)[1])).toBe('P01 · Veiligheid')
	})

	it('renders no hints, feedback or error for an untouched form', async () => {
		const { html } = await render({ id: 'new' })

		expect(html).not.toContain('bbv-mapping-detail-gl-hint')
		expect(html).not.toContain('bbv-mapping-detail-programme-hint')
		expect(html).not.toContain('bbv-mapping-detail-alloc-feedback')
		expect(html).not.toContain('bbv-mapping-detail-save-error')
	})

	it.each([
		['error', 'bbv-mapping-detail__alloc--error'],
		['info', 'bbv-mapping-detail__alloc--info'],
	])('colours %s allocation feedback', async (severity, cls) => {
		const { html } = await render({ id: 'new' }, { allocationFeedback: { severity, message: 'Remaining 20 %' } })
		const box = html.match(/<div[^>]*bbv-mapping-detail-alloc-feedback[^>]*>(.*?)<\/div>/s)

		expect(box[0]).toContain(cls)
		expect(text(box[1])).toBe('Remaining 20 %')
	})

	it('shows the save error as an alert', async () => {
		const { html } = await render({ id: 'new' }, { saveError: 'Server said no' })

		expect(html).toMatch(/<p[^>]*bbv-mapping-detail-save-error[^>]*role="alert"[^>]*>\s*Server said no\s*<\/p>/)
	})

	it('writes the pickers\' choices into the form', async () => {
		const { vm, seen } = await render({ id: 'new' })

		seen.GlAccountPicker[0]['onUpdate:modelValue']('4000')
		seen.BBVProgrammePicker[0]['onUpdate:modelValue']('P02')
		expect([vm.form.glAccountNumber, vm.form.programmeCode]).toEqual(['4000', 'P02'])
		expect(seen.BBVProgrammePicker[0].fiscalYear).toBe(new Date().getFullYear())
	})

	it('wires the delete dialog to close and confirm', async () => {
		const { vm, seen } = await render({ id: 'm-7' }, { deleteDialogOpen: true, record: { id: 'm-7' } })
		const [dialog] = seen.DeleteBudgetMappingDialog

		expect(dialog.open).toBe(true)
		expect(dialog.mapping).toEqual({ id: 'm-7' })
		dialog.onCancel()
		expect(vm.deleteDialogOpen).toBe(false)
		expect(dialog.onConfirm).toBe(vm.onDelete)
	})
})

describe('BudgetBBVMappingDetail behaviour branches', () => {
	it('clears a pending projection timer on unmount', () => {
		vi.useFakeTimers()
		const refresh = vi.fn()
		const timer = setTimeout(refresh, 250)
		BudgetBBVMappingDetail.beforeUnmount.call({ allocationCheckTimer: timer })
		vi.advanceTimersByTime(500)
		expect(refresh).not.toHaveBeenCalled()
	})

	it('debounces the projection, restarting the timer on each change', () => {
		vi.useFakeTimers()
		const c = ctx({ form: { allocationPercentage: 30, effectiveFrom: '2027-01-01' } })
		c.refreshAllocationProjection = vi.fn()

		c.scheduleAllocationCheck()
		vi.advanceTimersByTime(200)
		c.scheduleAllocationCheck()
		vi.advanceTimersByTime(200)
		expect(c.refreshAllocationProjection).not.toHaveBeenCalled()
		vi.advanceTimersByTime(50)
		expect(c.refreshAllocationProjection).toHaveBeenCalledTimes(1)
	})

	it('flags an out-of-range allocation at once, without scheduling a projection', () => {
		vi.useFakeTimers()
		const c = ctx({ form: { allocationPercentage: 120 } })
		c.refreshAllocationProjection = vi.fn()

		c.scheduleAllocationCheck()
		vi.advanceTimersByTime(1000)
		expect(c.allocationFeedback).toEqual({ severity: 'error', message: 'Allocation must be between 0 % and 100 %.' })
		expect(c.allocationCheckTimer).toBeNull()
		expect(c.refreshAllocationProjection).not.toHaveBeenCalled()
	})

	it('takes the administration from the chosen programme only when none is set', () => {
		const c = ctx()
		c.onProgrammeSelected({ programmeCode: 'P01', administrationId: 'adm-9' })
		expect(c.form.administrationId).toBe('adm-9')
		c.onProgrammeSelected({ programmeCode: 'P02', administrationId: 'adm-other' })
		expect(c.form.administrationId).toBe('adm-9')
		c.onProgrammeSelected(undefined)
		expect(c.selectedProgramme).toBeNull()
	})

	it('falls back to the current year for a missing or unparsable start date', () => {
		expect(ctx({ form: { effectiveFrom: '' } }).fiscalYearOfMapping).toBe(new Date().getFullYear())
		expect(ctx({ form: { effectiveFrom: 'abcd-01-01' } }).fiscalYearOfMapping).toBe(new Date().getFullYear())
		expect(ctx({ form: { effectiveFrom: '2031-06-01' } }).fiscalYearOfMapping).toBe(2031)
	})

	it('summarises an account from its alternative name, type and balance fields', () => {
		const c = ctx({ selectedAccount: { title: 'Kas', type: 'assets', balanceCents: 12345 } })
		c.formatEuro = (cents) => `EUR ${cents}`
		expect(c.glAccountSummary).toBe('Kas · assets · EUR 12345')
		c.selectedAccount = { name: 'Bank' }
		expect(c.glAccountSummary).toBe('Bank')
	})

	it('fills a missing administration from the prop, else empty', () => {
		const c = ctx({ administrationId: undefined })
		c.defaultEffectiveFrom = () => '2027-01-01'
		c.fillFormFromRecord({ glAccountNumber: '4000' })
		expect(c.form.administrationId).toBe('')
		expect(c.form.effectiveFrom).toBe('2027-01-01')
	})

	it('treats an empty allocation as not out of range', () => {
		for (const raw of ['', null, undefined]) {
			const c = ctx({ form: { allocationPercentage: raw } })
			expect(c.checkAllocationRange()).toBe(false)
			expect(c.allocationFeedback.message).toBe('')
		}
	})

	it.each([
		['results', { results: [{ id: 'a' }] }, [{ id: 'a' }]],
		['objects', { objects: [{ id: 'b' }] }, [{ id: 'b' }]],
		['a bare list', [{ id: 'c' }], [{ id: 'c' }]],
		['no list at all', { total: 0 }, []],
		['nothing', null, []],
	])('reads the active mappings from %s', async (_label, data, rows) => {
		axios.get = vi.fn(async () => ({ data }))
		const c = ctx({ form: { administrationId: '' } })

		expect(await c.fetchActiveMappings('4000')).toEqual(rows)
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { glAccountNumber: '4000', status: 'active' } })
	})

	it('counts a non-numeric current allocation as zero in the projection', () => {
		const c = ctx({ form: { allocationPercentage: 'abc' } })
		const feedback = c.allocationFeedbackFor('4000', 101)
		expect(feedback.severity).toBe('error')
		expect(feedback.message).toContain('101.00')
	})
})
