/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * PurchaseOrderDetail: the dispatch channel, status and error of an order, and
 * a send channel the server cannot use shown disabled with its reason
 * (purchasing-order-dispatch-and-receipt, task 3.2).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import Detail from '../../src/components/purchase-order/PurchaseOrderDetail.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const CHANNELS = '/index.php/apps/shillinq/api/purchase-orders/dispatch-channels'

/**
 * A stand-in instance with the component's data, methods and computed.
 *
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = { id: 'po-1', t: (_app, text) => text, ...Detail.data(), ...fields }
	for (const [name, fn] of Object.entries(Detail.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Detail.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('dispatch channels', () => {
	it('reads which channels the server can send through', async () => {
		const channels = {
			peppol: { available: false, reason: 'Peppol sending is not configured' },
			email: { available: true, reason: null },
		}
		http.get.mockResolvedValue({ data: channels })
		const self = instance()

		await self.loadChannels()

		expect(http.get).toHaveBeenCalledWith(CHANNELS)
		expect(self.channels).toEqual(channels)
		expect(self.channelAvailable('peppol')).toBe(false)
		expect(self.channelAvailable('email')).toBe(true)
		expect(self.unavailableChannels).toEqual(['peppol'])
		expect(self.channelReason('peppol')).toBe('Peppol sending is not configured')
		expect(self.channelReason('email')).toBe('')
	})

	it('names both reasons when neither channel is there', () => {
		const self = instance({ channels: { peppol: { available: false }, email: { available: false } } })

		expect(self.unavailableChannels).toEqual(['peppol', 'email'])
		expect(self.channelReason('email')).toBe('Email sending is not configured')
	})

	it('leaves the buttons alone when availability cannot be read', async () => {
		http.get.mockRejectedValue(new Error('offline'))
		const self = instance()

		await self.loadChannels()

		expect(self.channels).toBeNull()
		expect(self.unavailableChannels).toEqual([])
	})
})

describe('dispatch labels', () => {
	it('labels the channel and the status, and a missing status as a dash', () => {
		const self = instance()

		expect(self.channelLabel('peppol')).toBe('Peppol')
		expect(self.channelLabel('email')).toBe('PDF and email')
		expect(self.dispatchStatusLabel('pending')).toBe('Waiting for delivery')
		expect(self.dispatchStatusLabel('sent')).toBe('Delivered')
		expect(self.dispatchStatusLabel('failed')).toBe('Not delivered')
		expect(self.dispatchStatusLabel(null)).toBe('—')
	})
})
