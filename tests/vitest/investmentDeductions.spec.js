/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-investment-deduction-claims 2.1: the Recalculate action on Investment
 * deductions, its request helper and its dialog. Only HTTP, the dialog
 * spawner and the Nextcloud globals are stubbed; the helpers run for real.
 */

import axios from '@nextcloud/axios'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecalculateInvestmentDeductionsModal from '../../src/modals/RecalculateInvestmentDeductionsModal.vue'
import { openRecalculateInvestmentDeductions } from '../../src/utils/investmentDeductionActions.js'
import {
	defaultYear,
	euro,
	recalculateErrorText,
	recalculateYear,
	totalRows,
} from '../../src/utils/investmentDeductionApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcTextField: {} }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn(() => 'dialog') }))

/**
 * A fake component instance: data, bound methods, live computed getters,
 * a recorded $emit and the given overrides.
 *
 * @param {object} state Data overrides.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const Comp = RecalculateInvestmentDeductionsModal
	const vm = { emitted: [] }
	vm.$emit = (...args) => vm.emitted.push(args)
	for (const [name, method] of Object.entries(Comp.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(Comp.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	Object.assign(vm, Comp.data.call(vm), state)
	return vm
}

const httpError = (status) => Object.assign(new Error('x'), { response: { status, data: {} } })

const RESULT = { year: 2026, totals: { KIA: 1792000, EIA: 560000, MIA: 0 }, claims: 4, kept: 0 }

beforeEach(() => {
	axios.post.mockReset()
	spawnDialog.mockClear()
})

describe('investmentDeductionApi', () => {
	it('proposes the year that ended until July, then the current one', () => {
		expect(defaultYear(new Date(2027, 2, 31))).toBe(2026)
		expect(defaultYear(new Date(2027, 5, 30))).toBe(2026)
		expect(defaultYear(new Date(2027, 6, 1))).toBe(2027)
	})

	it('writes cents as euros and lists every scheme, a missing one as zero', () => {
		expect(euro(1792000)).toBe('17,920.00')
		expect(euro(undefined)).toBe('0.00')
		expect(totalRows({ KIA: 1792000, EIA: 560000 })).toEqual([
			{ scheme: 'KIA', amount: '17,920.00' },
			{ scheme: 'EIA', amount: '5,600.00' },
			{ scheme: 'MIA', amount: '0.00' },
		])
		expect(totalRows(null).map((row) => row.amount)).toEqual(['0.00', '0.00', '0.00'])
	})

	it('posts to the year\'s calculate route, with the administration only when given', async () => {
		axios.post.mockResolvedValue({ data: RESULT })

		expect(await recalculateYear(2026)).toBe(RESULT)
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/investment-deductions/2026/calculate', {})

		await recalculateYear('2025', 'adm-korenaar')
		expect(axios.post).toHaveBeenLastCalledWith(
			'/index.php/apps/shillinq/api/investment-deductions/2025/calculate',
			{ administrationId: 'adm-korenaar' },
		)
	})

	it('names each refusal by its status', () => {
		expect(recalculateErrorText(httpError(400))).toBe('Choose a year between 2000 and 2100.')
		expect(recalculateErrorText(httpError(404))).toBe('You cannot calculate investment deductions for this administration.')
		expect(recalculateErrorText(httpError(401))).toBe(recalculateErrorText(httpError(404)))
		expect(recalculateErrorText(httpError(422))).toBe('There are no investment deduction tables for that year yet.')
		expect(recalculateErrorText(new Error('network'))).toMatch(/^The investment deductions could not be calculated\./)
	})
})

describe('openRecalculateInvestmentDeductions', () => {
	it('opens the dialog', () => {
		expect(openRecalculateInvestmentDeductions()).toBe('dialog')
		expect(spawnDialog).toHaveBeenCalledWith(RecalculateInvestmentDeductionsModal, {})
	})
})

describe('RecalculateInvestmentDeductionsModal', () => {
	it('accepts only a whole year from 2000 to 2100', () => {
		expect(instance({ year: '2026' }).canSubmit).toBe(true)
		expect(instance({ year: '1999' }).canSubmit).toBe(false)
		expect(instance({ year: '2101' }).canSubmit).toBe(false)
		expect(instance({ year: '2026.5' }).canSubmit).toBe(false)
		expect(instance({ year: '' }).canSubmit).toBe(false)
	})

	it('recalculates the chosen year and shows the total per scheme', async () => {
		axios.post.mockResolvedValue({ data: RESULT })
		const vm = instance({ year: '2026' })

		await vm.submit()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/investment-deductions/2026/calculate', {})
		expect(vm.result).toBe(RESULT)
		expect(vm.submitting).toBe(false)
		expect(vm.error).toBe('')
		expect(vm.totals).toEqual([
			{ scheme: 'KIA', amount: '17,920.00' },
			{ scheme: 'EIA', amount: '5,600.00' },
			{ scheme: 'MIA', amount: '0.00' },
		])

		vm.close()
		expect(vm.emitted).toEqual([['close', RESULT]])
	})

	it('shows the refusal and keeps the form open', async () => {
		axios.post.mockRejectedValue(httpError(422))
		const vm = instance({ year: '2031' })

		await vm.submit()

		expect(vm.result).toBeNull()
		expect(vm.submitting).toBe(false)
		expect(vm.error).toBe('There are no investment deduction tables for that year yet.')
	})
})
