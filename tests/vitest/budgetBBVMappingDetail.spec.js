/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins BudgetBBVMappingDetail's `canSave` computed, `loadRecord()` (which
 * envelope it reads, how it fills the form, its failure text) and
 * `refreshAllocationProjection()` (the query, which other mappings count
 * towards the GL total, and the feedback it sets), on a fake instance.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BudgetBBVMappingDetail from '../../src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const originalGet = axios.get

/**
 * A fake component instance: data fields, computed getters and bound methods.
 *
 * @param {object} state Data fields to set.
 * @return {object}
 */
function vm(state = {}) {
	const ctx = {
		id: 'm-7',
		administrationId: 'adm-prop',
		saving: false,
		loading: false,
		loadError: '',
		record: undefined,
		existingAllocationTotal: -1,
		allocationFeedback: { message: '', severity: 'info' },
		form: { glAccountNumber: '', programmeCode: '', allocationPercentage: 0, effectiveFrom: '', effectiveTo: '', status: 'active', administrationId: '' },
		...state,
	}
	for (const [name, fn] of Object.entries(BudgetBBVMappingDetail.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.t = (app, text, vars) => `${text}${vars ? ' ' + JSON.stringify(vars) : ''}`
	ctx.defaultEffectiveFrom = () => '2031-01-01'
	for (const name of ['isCreate', 'recordId', 'canSave', 'fiscalYearOfMapping']) {
		Object.defineProperty(ctx, name, { get: () => BudgetBBVMappingDetail.computed[name].call(ctx) })
	}
	return ctx
}

const VALID = { glAccountNumber: '4000', programmeCode: 'P01', allocationPercentage: '40', effectiveFrom: '2026-01-01', effectiveTo: '', status: 'active', administrationId: '' }

afterEach(() => {
	axios.get = originalGet
})

describe('BudgetBBVMappingDetail canSave', () => {
	it.each([
		['a valid form', {}, {}, true],
		['while saving', {}, { saving: true }, false],
		['without GL account', { glAccountNumber: '' }, {}, false],
		['without programme', { programmeCode: '' }, {}, false],
		['allocation null', { allocationPercentage: null }, {}, false],
		['allocation undefined', { allocationPercentage: undefined }, {}, false],
		['allocation empty', { allocationPercentage: '' }, {}, false],
		['allocation negative', { allocationPercentage: -1 }, {}, false],
		['allocation over 100', { allocationPercentage: '100.5' }, {}, false],
		['allocation 0', { allocationPercentage: 0 }, {}, true],
		['allocation 100', { allocationPercentage: 100 }, {}, true],
		['without effective from', { effectiveFrom: '' }, {}, false],
		['effective to before from', { effectiveTo: '2025-12-31' }, {}, false],
		['effective to after from', { effectiveTo: '2026-12-31' }, {}, true],
		['with an error feedback', {}, { allocationFeedback: { severity: 'error', message: 'x' } }, false],
		['with a warning feedback', {}, { allocationFeedback: { severity: 'warning', message: 'x' } }, true],
	])('%s', (name, form, state, expected) => {
		expect(vm({ form: { ...VALID, ...form }, ...state }).canSave).toBe(expected)
	})
})

describe('BudgetBBVMappingDetail loadRecord', () => {
	it('clears the record on the create route without a request', async () => {
		const get = vi.fn()
		axios.get = get
		const ctx = vm({ id: 'new', loadError: 'old', record: { x: 1 } })
		await ctx.loadRecord()
		expect(ctx.record).toBe(null)
		expect(ctx.loadError).toBe('')
		expect(get).not.toHaveBeenCalled()
	})

	it.each([
		['object envelope', { object: { glAccountNumber: '4100', programmeCode: 'P9', allocationPercentage: 25, effectiveFrom: '2026-02-01', effectiveTo: '2026-12-31', status: 'draft', administrationId: 'adm-r' } }],
		['result envelope', { result: { glAccountNumber: '4100' } }],
		['bare record', { glAccountNumber: '4100', allocationPercentage: null, administrationId: null }],
	])('fills the form from the %s', async (name, data) => {
		const calls = []
		axios.get = vi.fn(async (url, config) => {
			calls.push([url, config])
			return { data: calls.length === 1 ? data : [] }
		})
		const ctx = vm()
		await ctx.loadRecord()
		expect(calls[0]).toEqual(['/index.php/apps/openregister/api/objects/shillinq/BudgetBBVMapping/m-7', undefined])
		expect({ form: ctx.form, record: ctx.record, loading: ctx.loading, loadError: ctx.loadError, calls: calls.length }).toMatchSnapshot()
	})

	it.each([
		['an empty response', async () => ({ data: null }), 'Failed to load mapping.'],
		['a non-object body', async () => ({ data: 'x' }), 'Failed to load mapping.'],
		['a server error', async () => { const e = new Error('boom'); e.response = { data: { error: 'Denied' } }; throw e }, 'Denied'],
	])('reports %s', async (name, get, message) => {
		axios.get = vi.fn(get)
		const ctx = vm()
		await ctx.loadRecord()
		expect(ctx.loadError).toBe(message)
		expect(ctx.loading).toBe(false)
	})
})

describe('BudgetBBVMappingDetail refreshAllocationProjection', () => {
	const OTHERS = [
		{ id: 'm-7', allocationPercentage: 30, effectiveFrom: '2026-01-01' },
		{ id: 'm-1', allocationPercentage: '20', effectiveFrom: '2025-01-01', effectiveTo: '2026-06-30' },
		{ id: 'm-2', allocationPercentage: 15.5, effectiveFrom: '2027-01-01' },
		{ id: 'm-3', allocationPercentage: 'n/a', effectiveFrom: '2026-03-01' },
		{ id: 'm-4', effectiveTo: '2025-12-31' },
		null,
		'x',
		{ id: 'm-5', allocationPercentage: 35 },
	]

	it.each([
		['results envelope, within 100', { results: OTHERS }, { allocationPercentage: '40' }, 'm-7'],
		['objects envelope, over 100', { objects: OTHERS }, { allocationPercentage: '50' }, 'm-7'],
		['bare list on create counts the own id', OTHERS, { allocationPercentage: 10 }, 'new'],
		['not a list', { results: 'nope' }, { allocationPercentage: 10 }, 'm-7'],
		['allocation not a number', { results: OTHERS }, { allocationPercentage: 'abc' }, 'm-7'],
		['with an administration', { results: [] }, { allocationPercentage: 10, administrationId: 'adm-9' }, 'm-7'],
	])('%s', async (name, data, form, id) => {
		const calls = []
		axios.get = vi.fn(async (url, config) => {
			calls.push([url, config])
			return { data }
		})
		const ctx = vm({ id, form: { ...VALID, ...form } })
		await ctx.refreshAllocationProjection()
		expect({ calls, total: ctx.existingAllocationTotal, feedback: ctx.allocationFeedback }).toMatchSnapshot()
	})

	it('flags an out-of-range allocation without a request', async () => {
		const get = vi.fn()
		axios.get = get
		const ctx = vm({ form: { ...VALID, allocationPercentage: 120 } })
		await ctx.refreshAllocationProjection()
		expect(ctx.allocationFeedback.severity).toBe('error')
		expect(get).not.toHaveBeenCalled()
	})

	it('resets without a GL account', async () => {
		const get = vi.fn()
		axios.get = get
		const ctx = vm({ form: { ...VALID, glAccountNumber: '' } })
		await ctx.refreshAllocationProjection()
		expect(ctx.allocationFeedback).toEqual({ message: '', severity: 'info' })
		expect(ctx.existingAllocationTotal).toBe(0)
		expect(get).not.toHaveBeenCalled()
	})

	it('clears the feedback when the request fails', async () => {
		axios.get = vi.fn(async () => { throw new Error('down') })
		const ctx = vm({ form: { ...VALID }, allocationFeedback: { severity: 'error', message: 'old' } })
		await ctx.refreshAllocationProjection()
		expect(ctx.allocationFeedback).toEqual({ message: '', severity: 'info' })
		expect(ctx.existingAllocationTotal).toBe(-1)
	})
})
