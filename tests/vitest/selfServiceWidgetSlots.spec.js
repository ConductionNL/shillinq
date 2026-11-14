/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for SelfServiceWidget.vue's loadSlots(): the slots
 * request, the reset it does first, the skip without a service or date, and
 * the message of each failure. Methods run bound to a fake `this` built the
 * way tests/vitest/selfServiceWidgetSubmit.golden.spec.js builds it.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import SelfServiceWidget from '../../src/components/widget/SelfServiceWidget.vue'

const PROPS = { businessId: 'biz-1', apiBase: 'https://api.example.org/', apiKey: 'key-1', resourceId: 'res-9', lang: 'en', translations: {} }

/**
 * A widget with a service and date chosen and stale slot state.
 *
 * @return {object}
 */
function widget() {
	const vm = { ...PROPS }
	for (const [name, fn] of Object.entries(SelfServiceWidget.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, SelfServiceWidget.data.call(vm), {
		selectedServiceId: 'svc-1',
		selectedDate: '2026-11-02',
		slots: ['stale'],
		selectedSlot: { startTime: 'x' },
		slotsError: 'stale',
	})
	return vm
}

describe('SelfServiceWidget loadSlots', () => {
	let requests

	beforeEach(() => {
		requests = []
		globalThis.window = { location: { origin: 'https://host.example' } }
	})

	afterEach(() => {
		delete globalThis.window
		delete globalThis.fetch
	})

	/**
	 * Answer the slots request with the given reply, or throw.
	 *
	 * @param {object|string} reply A {status, json} reply, or 'throw'.
	 */
	function answer(reply) {
		globalThis.fetch = async (url, init) => {
			requests.push([url, init])
			if (reply === 'throw') {
				throw new TypeError('Failed to fetch')
			}
			return { ok: reply.status >= 200 && reply.status < 300, ...reply }
		}
	}

	it('loads the slots of the service and date', async () => {
		answer({ status: 200, json: async () => ({ slots: [{ startTime: 'a' }] }) })
		const vm = widget()

		await vm.loadSlots()

		expect(requests).toHaveLength(1)
		expect(requests[0][0]).toContain('/api/widget/slots')
		expect(requests[0][0]).toContain('serviceId=svc-1')
		expect(requests[0][0]).toContain('resourceId=res-9')
		expect(requests[0][0]).toContain('date=2026-11-02')
		expect(requests[0][1]).toEqual({ method: 'GET', headers: vm.authHeaders() })
		expect([vm.slots, vm.selectedSlot, vm.slotsError]).toEqual([[{ startTime: 'a' }], null, ''])
	})

	it('reads a payload without slots as none', async () => {
		answer({ status: 200, json: async () => null })
		const vm = widget()
		await vm.loadSlots()
		expect(vm.slots).toEqual([])
	})

	it('resets and skips without a service or date', async () => {
		answer({ status: 200, json: async () => ({}) })
		const vm = widget()
		vm.selectedDate = ''

		await vm.loadSlots()

		expect([requests.length, vm.slots, vm.selectedSlot, vm.slotsError]).toEqual([0, [], null, ''])
	})

	it('words each failure', async () => {
		const messages = []
		for (const reply of [{ status: 401 }, { status: 403 }, { status: 404 }, { status: 500 }, { status: 302 }, 'throw']) {
			answer(reply)
			const vm = widget()
			await vm.loadSlots()
			messages.push(vm.slotsError)
			expect(vm.slots).toEqual([])
		}

		expect(messages).toEqual([
			'Configuration error. Please contact the website owner.',
			'Configuration error. Please contact the website owner.',
			'This service is no longer available. Please refresh the page.',
			'We could not load available times. Please try another date.',
			'We could not load available times. Please try another date.',
			'Network error. Please check your connection and try again.',
		])
	})
})
