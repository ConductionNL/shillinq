/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The product catalog and attribute catalog loads: the request, the envelope
 * unpacking with its defaults, the 403 answer and the generic failure.
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProductAttributeCatalogIndex from '../../src/components/inventory/ProductAttributeCatalogIndex.vue'
import ProductCatalogIndex from '../../src/components/inventory/ProductCatalogIndex.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))

const NO_ADMIN = 'You have no administration yet, so there is no inventory to show. Ask an administrator for access.'

/**
 * A fake instance with data, a translator and bound methods.
 *
 * @param {object} Comp The component.
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function view(Comp, state = {}) {
	const vm = { ...Comp.data.call({ t: (app, text) => text }), t: (app, text) => text, ...state }
	for (const [name, method] of Object.entries(Comp.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

const cases = [
	{ Comp: ProductCatalogIndex, load: 'loadCatalog', url: '/index.php/apps/shillinq/api/inventory/products', list: 'products', fallbackSource: 'local-cache', failed: 'Could not load the product catalog.' },
	{ Comp: ProductAttributeCatalogIndex, load: 'loadAttributes', url: '/index.php/apps/shillinq/api/inventory/product-attributes', list: 'attributes', fallbackSource: 'contract', failed: 'Could not load the product attribute definitions.' },
]

describe.each(cases)('$load', ({ Comp, load, url, list, fallbackSource, failed }) => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('unpacks the envelope', async () => {
		const vm = view(Comp, { error: 'old', loading: false })
		axios.get.mockImplementationOnce(async () => {
			expect(vm.loading).toBe(true)
			expect(vm.error).toBe('')
			return { data: { [list]: [{ id: 1 }], source: 'pipelinq', authoritative: true } }
		})
		await vm[load]()
		expect(axios.get).toHaveBeenCalledWith(url)
		expect(vm[list]).toEqual([{ id: 1 }])
		expect(vm.source).toBe('pipelinq')
		expect(vm.authoritative).toBe(true)
		expect(vm.loading).toBe(false)
	})

	it('defaults a partial or empty envelope', async () => {
		const vm = view(Comp, { source: 'x', authoritative: true })
		axios.get.mockResolvedValueOnce({ data: { [list]: 'nope', authoritative: 'true' } })
		await vm[load]()
		expect(vm[list]).toEqual([])
		expect(vm.source).toBe(fallbackSource)
		expect(vm.authoritative).toBe(false)

		axios.get.mockResolvedValueOnce({ data: null })
		await vm[load]()
		expect(vm[list]).toEqual([])
		expect(vm.source).toBe(fallbackSource)
	})

	it('answers a 403 with the no-administration message', async () => {
		const vm = view(Comp, { [list]: [{ id: 'old' }], source: 'pipelinq' })
		axios.get.mockRejectedValueOnce({ response: { status: 403 } })
		await vm[load]()
		expect(vm[list]).toEqual([])
		expect(vm.error).toBe(NO_ADMIN)
		expect(vm.source).toBe('pipelinq')
		expect(vm.loading).toBe(false)
	})

	it('reports any other failure', async () => {
		const vm = view(Comp, { [list]: [{ id: 'old' }] })
		axios.get.mockRejectedValueOnce({ response: { status: 500 } })
		await vm[load]()
		expect(vm[list]).toEqual([])
		expect(vm.error).toBe(failed)

		axios.get.mockRejectedValueOnce(new Error('offline'))
		await vm[load]()
		expect(vm.error).toBe(failed)
		expect(vm.loading).toBe(false)
	})
})
