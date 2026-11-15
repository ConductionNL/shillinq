/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins VendorPerformanceDetail's loading against a fake instance: the
 * scorecard id comes from the prop or the route, a missing id is an error
 * without a request, and a failed load shows the server's error or a
 * generic one. Also the score band class.
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import VendorPerformanceDetail from '../../src/components/vendor-performance/VendorPerformanceDetail.vue'

const { methods } = VendorPerformanceDetail
const originalGet = axios.get

/**
 * A fake component instance.
 *
 * @param {object} overrides Fields to set.
 * @return {object} The instance.
 */
function instance(overrides = {}) {
	const vm = {
		id: '',
		...VendorPerformanceDetail.data(),
		t: (_app, text) => text,
		...overrides,
	}
	for (const [name, fn] of Object.entries(methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

afterEach(() => {
	axios.get = originalGet
})

describe('VendorPerformanceDetail loading', () => {
	it('loads the scorecard named by the prop', async () => {
		axios.get = vi.fn(async () => ({ data: { id: 'vp-1', onTimeBp: 9700 } }))
		const vm = instance({ id: 'vp-1' })

		await VendorPerformanceDetail.created.call(vm)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/VendorPerformance/vp-1')
		expect(vm.scorecard).toEqual({ id: 'vp-1', onTimeBp: 9700 })
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
	})

	it('takes the id from the route when there is no prop', async () => {
		axios.get = vi.fn(async () => ({ data: null }))
		const vm = instance({ $route: { params: { id: 'vp-9' } } })

		await vm.loadScorecard()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/VendorPerformance/vp-9')
		expect(vm.scorecard).toBeNull()
	})

	it('asks for nothing and says so when there is no id', async () => {
		axios.get = vi.fn()
		const vm = instance({ $route: { params: {} } })

		await vm.loadScorecard()

		expect(axios.get).not.toHaveBeenCalled()
		expect(vm.error).toBe('Scorecard id is required')
		expect(vm.loading).toBe(false)
	})

	it('shows the server error, or a generic one, and clears an earlier error first', async () => {
		axios.get = vi.fn(async () => {
			throw { response: { data: { error: 'Gone' } } }
		})
		const vm = instance({ id: 'vp-1', error: 'old' })
		await vm.loadScorecard()
		expect(vm.error).toBe('Gone')
		expect(vm.loading).toBe(false)

		axios.get = vi.fn(async () => {
			throw new Error('offline')
		})
		await vm.loadScorecard()
		expect(vm.error).toBe('Failed to load scorecard')

		axios.get = vi.fn(async () => ({ data: { id: 'vp-1' } }))
		await vm.loadScorecard()
		expect(vm.error).toBe('')
	})
})

describe('VendorPerformanceDetail scoreClass', () => {
	it('names the score band', () => {
		const vm = instance()
		expect(vm.scoreClass(9600)).toBe('vp-detail__score--high')
		expect(vm.scoreClass(8500)).toBe('vp-detail__score--mid')
		expect(vm.scoreClass(8499)).toBe('vp-detail__score--low')
		expect(vm.scoreClass(null)).toBe('vp-detail__score--low')
	})
})
