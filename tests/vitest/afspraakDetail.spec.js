/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for AfspraakDetail.vue's booking load and its
 * klantbeeld paging: the requests, the accumulated transactions and envelope
 * they leave, and the message of each failure. Methods run bound to a fake
 * `this` seeded from the real `data()`.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AfspraakDetail from '../../src/views/bookings/AfspraakDetail.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const URL = '/index.php/apps/shillinq/api/v1/bookings/b%2F1'

/**
 * A fake AfspraakDetail for booking `b/1` with its methods bound.
 *
 * @return {object}
 */
function detail() {
	const self = { ...AfspraakDetail.data.call({}), id: 'b/1' }
	for (const [name, method] of Object.entries(AfspraakDetail.methods)) {
		self[name] = method.bind(self)
	}
	self.label = (text) => `L:${text}`
	return self
}

describe('AfspraakDetail', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('loads the booking and seeds the transactions from its klantbeeld', async () => {
		axios.get.mockResolvedValueOnce({ data: { id: 'b/1', klantbeeld: { transactions: [1, 2], limit: 2, offset: 0 } } })
		const vm = detail()
		vm.pagingError = 'stale'

		await vm.reload()

		expect(axios.get).toHaveBeenCalledWith(URL)
		expect([vm.accumulatedTransactions, vm._lastBatchSize, vm.loading, vm.loadError, vm.pagingError]).toEqual([[1, 2], 2, false, '', ''])

		axios.get.mockResolvedValueOnce({ data: null })
		await vm.reload()
		expect([vm.payload, vm.accumulatedTransactions, vm._lastBatchSize]).toEqual([null, [], 0])
	})

	it('words each failed load and drops the payload', async () => {
		const messages = []
		for (const error of [{ response: { status: 404 } }, { response: { status: 503 } }, { response: { status: 401 } }, { response: { status: 500, data: { error: 'Boom' } } }, new Error('x')]) {
			axios.get.mockRejectedValueOnce(error)
			const vm = detail()
			vm.payload = { stale: true }
			await vm.reload()
			messages.push(vm.loadError)
			expect([vm.payload, vm.loading]).toEqual([null, false])
		}

		expect(messages).toEqual([
			'L:Booking not found',
			'L:OpenRegister is temporarily unavailable',
			'L:Please sign in to view this booking',
			'Boom',
			'L:Failed to load booking',
		])
	})

	it('appends a page of transactions and mirrors its window into the envelope', async () => {
		const vm = detail()
		vm.payload = { id: 'b/1', klantbeeld: { transactions: [1], limit: 1, offset: 0, extra: 'kept' } }
		vm.accumulatedTransactions = [1]
		axios.get.mockResolvedValueOnce({ data: { klantbeeld: { transactions: [2, 3], unavailable: false } } })

		await vm.onLoadMore({ limit: 2, offset: 1 })

		expect(axios.get).toHaveBeenCalledWith(URL, { params: { limit: 2, offset: 1 } })
		expect([vm.accumulatedTransactions, vm._lastBatchSize, vm.pagingLoading]).toEqual([[1, 2, 3], 2, false])
		expect(vm.payload).toEqual({ id: 'b/1', klantbeeld: { transactions: [1], limit: 2, offset: 1, extra: 'kept', unavailable: false } })

		axios.get.mockResolvedValueOnce({ data: { klantbeeld: { transactions: [4], limit: 9, offset: 8, unavailable: true } } })
		await vm.onLoadMore({ limit: 2, offset: 3 })
		expect([vm.payload.klantbeeld.limit, vm.payload.klantbeeld.offset, vm.payload.klantbeeld.unavailable]).toEqual([9, 8, true])
	})

	it('ignores a page without transactions, skips while paging, and words a failed page', async () => {
		const vm = detail()
		vm.payload = { klantbeeld: { limit: 1 } }
		axios.get.mockResolvedValueOnce({ data: { klantbeeld: { transactions: 'none' } } })
		await vm.onLoadMore({ limit: 1, offset: 1 })
		expect([vm.accumulatedTransactions, vm.payload]).toEqual([[], { klantbeeld: { limit: 1 } }])

		vm.pagingLoading = true
		await vm.onLoadMore({ limit: 1, offset: 2 })
		expect(axios.get).toHaveBeenCalledTimes(1)

		vm.pagingLoading = false
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Nope' } } })
		await vm.onLoadMore({ limit: 1, offset: 2 })
		axios.get.mockRejectedValueOnce(new Error('x'))
		const second = detail()
		await second.onLoadMore({ limit: 1, offset: 2 })
		expect([vm.pagingError, second.pagingError, second.pagingLoading]).toEqual(['Nope', 'L:Failed to load more transactions', false])
	})
})
