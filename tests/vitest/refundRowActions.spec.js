/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The row actions of the "Refunds to pay" page (receivables-object-request-
 * refund-and-credit 3.2): which refund state a request is in, approving a
 * requested refund, and opening the paid dialog for an approved one, with the
 * refusals the page shows.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	post: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	spawnDialog: vi.fn(() => 'dialog'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/dialogs', () => ({ showError: mocks.showError, showSuccess: mocks.showSuccess }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: mocks.spawnDialog }))
vi.mock('../../src/modals/RefundPaidModal.vue', () => ({ default: { name: 'RefundPaidModal' } }))
vi.mock('@nextcloud/axios', () => ({ default: { post: mocks.post } }))

const { approveRefund, newestRefundState, openRefundPaid } = await import('../../src/utils/refundActions.js')

const request = (...states) => ({ id: 'pr 1', refunds: states.map((state) => ({ state })) })

beforeEach(() => {
	Object.values(mocks).forEach((mock) => mock.mockClear())
	mocks.post.mockReset()
})

describe('newestRefundState', () => {
	it('reads the last refund\'s state, or none', () => {
		expect(newestRefundState(request('paid', 'requested'))).toBe('requested')
		expect(newestRefundState(request())).toBe('')
		expect(newestRefundState({ refunds: 'x' })).toBe('')
		expect(newestRefundState(undefined)).toBe('')
		expect(newestRefundState({ refunds: [{}] })).toBe('')
	})
})

describe('approveRefund', () => {
	it('approves a requested refund through its step URL', async () => {
		mocks.post.mockResolvedValueOnce({})

		expect(await approveRefund({ item: request('requested') })).toBe(true)
		expect(mocks.post).toHaveBeenCalledWith('/apps/shillinq/api/payment-requests/pr%201/refund/approve')
		expect(mocks.showSuccess).toHaveBeenCalledWith('Refund approved. Pay it by bank, then mark it paid.')
	})

	it('refuses a refund that is no longer requested, without asking the server', async () => {
		expect(await approveRefund({ item: request('approved') })).toBe(false)
		expect(mocks.showError).toHaveBeenCalledWith('This refund is already approved.')
		expect(mocks.post).not.toHaveBeenCalled()
	})

	it('does nothing without a request id', async () => {
		expect(await approveRefund({ item: {} })).toBe(false)
		expect(await approveRefund(undefined)).toBe(false)
		expect(mocks.post).not.toHaveBeenCalled()
		expect(mocks.showError).not.toHaveBeenCalled()
	})

	it('shows the server\'s refusal, else its own words', async () => {
		mocks.post.mockRejectedValueOnce({ response: { data: { error: 'You may not administer payments.' } } })
		expect(await approveRefund({ item: request('requested') })).toBe(false)
		expect(mocks.showError).toHaveBeenLastCalledWith('You may not administer payments.')

		mocks.post.mockRejectedValueOnce(new Error('Network Error'))
		expect(await approveRefund({ item: request('requested') })).toBe(false)
		expect(mocks.showError).toHaveBeenLastCalledWith('The refund could not be approved.')
		expect(mocks.showSuccess).not.toHaveBeenCalled()
	})
})

describe('openRefundPaid', () => {
	it('opens the paid dialog for an approved refund', () => {
		const item = request('requested', 'approved')

		expect(openRefundPaid({ item })).toBe('dialog')
		expect(mocks.spawnDialog).toHaveBeenCalledWith({ name: 'RefundPaidModal' }, { paymentRequest: item })
	})

	it('asks to approve first, and opens nothing', () => {
		expect(openRefundPaid({ item: request('requested') })).toBeUndefined()
		expect(mocks.showError).toHaveBeenCalledWith('Approve this refund before you mark it paid.')
		expect(mocks.spawnDialog).not.toHaveBeenCalled()
	})

	it('does nothing without a request id', () => {
		expect(openRefundPaid({})).toBeUndefined()
		expect(mocks.spawnDialog).not.toHaveBeenCalled()
		expect(mocks.showError).not.toHaveBeenCalled()
	})
})
