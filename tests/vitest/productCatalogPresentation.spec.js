/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The product and attribute catalog presentation: the column sets, the
 * provenance banner that states exactly what the rows are, the empty-state
 * text that differs by source, and the money/quantity formatting with its
 * unknown placeholder. The envelope loads live in inventoryCatalogLoad.spec.js;
 * this file covers the computed banners and the formatters those tests leave
 * untouched.
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */

import { describe, expect, it } from 'vitest'
import { vi } from 'vitest'
import ProductAttributeCatalogIndex from '../../src/components/inventory/ProductAttributeCatalogIndex.vue'
import ProductCatalogIndex from '../../src/components/inventory/ProductCatalogIndex.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))

const T = (app, text) => text

/**
 * A fake instance with data, a translator and the computed getters bound.
 *
 * @param {object} Comp The component.
 * @param {object} state Overrides.
 * @return {object} The instance, with `computed(name)`.
 */
function view(Comp, state = {}) {
	const vm = { ...Comp.data.call({ t: T }), t: T, ...state }
	for (const [name, method] of Object.entries(Comp.methods || {})) {
		vm[name] = method.bind(vm)
	}
	for (const [name, fn] of Object.entries(Comp.computed || {})) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	vm.computed = (name) => Comp.computed[name].call(vm)
	return vm
}

describe('ProductCatalogIndex presentation', () => {
	it('lists the eight catalog columns, all sortable, in order', () => {
		const vm = view(ProductCatalogIndex)
		const columns = vm.computed('columns')
		expect(columns.map((c) => c.key)).toEqual([
			'productId', 'sku', 'name', 'category',
			'unitPrice', 'unitCost', 'quantityOnHand', 'status',
		])
		expect(columns.every((c) => c.sortable === true)).toBe(true)
		expect(columns.every((c) => typeof c.label === 'string' && c.label.length > 0)).toBe(true)
	})

	it('states the connected product master in the banner', () => {
		const vm = view(ProductCatalogIndex, { authoritative: true })
		expect(vm.computed('provenanceTitle')).toBe('Product master: connected')
		expect(vm.computed('provenanceDetail')).toContain('authoritative')
		expect(vm.computed('emptyLabel')).toBe('The product master holds no products yet.')
	})

	it('states the local cache in the banner when not authoritative', () => {
		const vm = view(ProductCatalogIndex, { authoritative: false })
		expect(vm.computed('provenanceTitle')).toBe('Product master: not connected')
		expect(vm.computed('provenanceDetail')).toContain('local cache')
		expect(vm.computed('emptyLabel')).toContain('stock or barcode records')
	})

	it('uses an em dash, untranslated, for an unknown field', () => {
		const vm = view(ProductCatalogIndex)
		expect(vm.computed('unknownLabel')).toBe('—')
	})

	it('formats money with two decimals and the currency, defaulting EUR', () => {
		const vm = view(ProductCatalogIndex)
		expect(vm.money(12.5, 'USD')).toBe('12.50 USD')
		expect(vm.money('7', null)).toBe('7.00 EUR')
		expect(vm.money(null)).toBe('—')
		expect(vm.money(undefined)).toBe('—')
		expect(vm.money('nope')).toBe('—')
	})

	it('formats a quantity with two decimals, else the unknown placeholder', () => {
		const vm = view(ProductCatalogIndex)
		expect(vm.quantity(3)).toBe('3.00')
		expect(vm.quantity(null)).toBe('—')
		expect(vm.quantity(undefined)).toBe('—')
		expect(vm.quantity('x')).toBe('—')
	})
})

describe('ProductAttributeCatalogIndex presentation', () => {
	it('lists the attribute columns with the notes column unsortable', () => {
		const vm = view(ProductAttributeCatalogIndex)
		const columns = vm.computed('columns')
		expect(columns.map((c) => c.key)).toEqual([
			'name', 'dataType', 'applicableToCategories',
			'isRequired', 'displayOrder', 'ownedBy', 'validationRule',
		])
		expect(columns.find((c) => c.key === 'validationRule').sortable).toBe(false)
		expect(columns.filter((c) => c.key !== 'validationRule').every((c) => c.sortable)).toBe(true)
	})

	it('states the product-master source when authoritative', () => {
		const vm = view(ProductAttributeCatalogIndex, { authoritative: true })
		expect(vm.computed('provenanceTitle')).toBe('Attribute definitions: from the product master')
		expect(vm.computed('provenanceDetail')).toContain('the product master’s own products declare')
	})

	it('states the integration-contract source when not authoritative', () => {
		const vm = view(ProductAttributeCatalogIndex, { authoritative: false })
		expect(vm.computed('provenanceTitle')).toBe('Attribute definitions: from the integration contract')
		expect(vm.computed('provenanceDetail')).toContain('Owned By')
	})
})
