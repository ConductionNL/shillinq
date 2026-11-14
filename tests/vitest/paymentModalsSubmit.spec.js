/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The submit of the refund-paid and payment-plan action modals: their guards,
 * the calls they make, the success toast, the close payload and the error.
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PaymentPlanActionModal from '../../src/modals/PaymentPlanActionModal.vue'
import RefundPaidModal from '../../src/modals/RefundPaidModal.vue'
import { activatePlan, cancelPlan, settlePlan } from '../../src/utils/paymentPlanApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('../../src/utils/refundApi.js', () => ({
	refundStepUrl: (id, step) => `/refund/${id}/${step}`,
	refundError: (error, fallback) => `${error.message}|${fallback}`,
}))
vi.mock('../../src/utils/downPaymentApi.js', () => ({
	errorMessage: (error, fallback) => `${error.message}|${fallback}`,
}))
vi.mock('../../src/utils/paymentPlanApi.js', () => ({
	activatePlan: vi.fn(),
	cancelPlan: vi.fn(),
	settlePlan: vi.fn(),
}))

/**
 * A fake modal instance with data, bound methods and a recorded $emit.
 *
 * @param {object} Comp The component.
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function modal(Comp, state) {
	const vm = { ...Comp.data.call({}), emitted: [], ...state }
	vm.$emit = (...args) => vm.emitted.push(args)
	for (const [name, method] of Object.entries(Comp.methods)) {
		vm[name] = typeof method === 'function' ? method.bind(vm) : method
	}
	return vm
}

describe('RefundPaidModal submit', () => {
	beforeEach(() => {
		axios.post.mockReset()
		showSuccess.mockReset()
	})

	it('refuses a missing bank reference or account', async () => {
		for (const state of [{ bankReference: '  ', bankAccount: { value: 'NL01' } }, { bankReference: 'R1', bankAccount: null }, { bankReference: 'R1', bankAccount: { value: '' } }]) {
			const vm = modal(RefundPaidModal, { ...state, paymentRequest: { id: 'pr-1' }, error: 'old' })
			await vm.submit()
			expect(vm.error).toBe('Fill in the bank reference and choose the bank account.')
			expect(vm.submitting).toBe(false)
		}
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('marks the refund paid and closes with the answer', async () => {
		const vm = modal(RefundPaidModal, { bankReference: ' R1 ', bankAccount: { value: 'NL01' }, paymentRequest: { id: 'pr-1' }, error: 'old' })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.submitting).toBe(true)
			expect(vm.error).toBe('')
			return { data: { id: 'pr-1', state: 'paid' } }
		})
		await vm.submit()
		expect(axios.post).toHaveBeenCalledWith('/refund/pr-1/paid', { bankReference: 'R1', bankAccount: 'NL01' })
		expect(Object.keys(axios.post.mock.calls[0][1])).toEqual(['bankReference', 'bankAccount'])
		expect(showSuccess).toHaveBeenCalledWith('Refund marked paid.')
		expect(vm.emitted).toEqual([['close', { id: 'pr-1', state: 'paid' }]])
		expect(vm.submitting).toBe(false)
	})

	it('shows the refusal and stays open', async () => {
		const vm = modal(RefundPaidModal, { bankReference: 'R1', bankAccount: { value: 'NL01' }, paymentRequest: { id: 'pr-1' } })
		axios.post.mockRejectedValueOnce(new Error('locked'))
		await vm.submit()
		expect(vm.error).toBe('locked|The refund could not be marked paid.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([])
		expect(vm.submitting).toBe(false)
	})
})

describe('PaymentPlanActionModal submit', () => {
	beforeEach(() => {
		activatePlan.mockReset()
		cancelPlan.mockReset()
		settlePlan.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('activates, cancels or settles by mode, refreshes and closes with the result', async () => {
		activatePlan.mockResolvedValueOnce({ a: 1 })
		cancelPlan.mockResolvedValueOnce({ c: 1 })
		settlePlan.mockResolvedValueOnce({ s: 1 })
		const runs = [
			['activate', 'The plan is active.', { a: 1 }],
			['cancel', 'The plan is cancelled.', { c: 1 }],
			['settle', 'The payment is recorded.', { s: 1 }],
			['anything', 'The payment is recorded.', undefined],
		]
		for (const [mode, toast, result] of runs) {
			showSuccess.mockReset()
			emit.mockReset()
			const vm = modal(PaymentPlanActionModal, { mode, effectiveId: 'pp-1', reason: 'why', amount: '10', paidDate: '2026-01-02', reference: 'ref', error: 'old' })
			await vm.submit()
			expect(showSuccess).toHaveBeenCalledWith(toast)
			expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
			expect(vm.emitted).toEqual([['close', result]])
			expect(vm.error).toBe('')
			expect(vm.submitting).toBe(false)
		}
		expect(activatePlan).toHaveBeenCalledWith('pp-1')
		expect(cancelPlan).toHaveBeenCalledWith('pp-1', 'why')
		expect(settlePlan).toHaveBeenCalledWith('pp-1', { amount: '10', paidDate: '2026-01-02', reference: 'ref' })
		expect(settlePlan).toHaveBeenCalledTimes(2)
	})

	it('shows the refusal without a toast, refresh or close', async () => {
		const vm = modal(PaymentPlanActionModal, { mode: 'cancel', effectiveId: 'pp-1' })
		cancelPlan.mockImplementationOnce(async () => {
			expect(vm.submitting).toBe(true)
			throw new Error('gone')
		})
		await vm.submit()
		expect(vm.error).toBe('gone|The payment plan could not be updated.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(emit).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([])
		expect(vm.submitting).toBe(false)
	})
})
