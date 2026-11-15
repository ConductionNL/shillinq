/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The inventory operations and catalogs through their own templates: the
 * inputs write their fields, the scan opens the scanner, the count shows its
 * variance, the SKU and location watchers re-read the system quantity, the
 * sync badge only syncs online, and the catalog cells render through the
 * `#column-*` slots CnDataTable actually calls.
 *
 * Each template is rendered both ways a slot can be taken (server and client
 * branch); the client branch's vnodes carry the real v-model assignments.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchEnvelope } from '../../src/components/inventory/catalogEnvelope.js'
import { readStockQuantity } from '../../src/composables/useInventoryDb.js'
import { findVnodes, renderSsr } from './helpers/ssrStandIns.js'

const store = {
	db: { name: 'db' },
	locations: [],
	isOnline: true,
	conflicts: [],
	triggerSyncNow: vi.fn(),
}

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/composables/useInventoryDb.js', () => ({ readStockQuantity: vi.fn() }))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => store,
}))
vi.mock('../../src/components/inventory/catalogEnvelope.js', () => ({
	fetchEnvelope: vi.fn(),
	rowsOf: (envelope, key) => envelope[key],
	isNoAdministration: () => false,
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))

const { default: CountOp } = await import('../../src/components/inventory/CountOp.vue')
const { default: PickOp } = await import('../../src/components/inventory/PickOp.vue')
const { default: SyncStatusBadge } = await import('../../src/components/inventory/SyncStatusBadge.vue')
const { default: ProductCatalogIndex } = await import('../../src/components/inventory/ProductCatalogIndex.vue')
const { default: ProductAttributeCatalogIndex } = await import('../../src/components/inventory/ProductAttributeCatalogIndex.vue')

const FIELDS = ['InventoryOperationForm', 'InventoryLocationField', 'InventorySkuField', 'InventoryQuantityField']

/**
 * The first native input inside the operation form's slot matching a predicate.
 *
 * @param {object} seen The recorded stand-ins.
 * @param {Function} match The predicate on the input's props.
 * @return {object} The input vnode.
 */
function formInput(seen, match) {
	return findVnodes(seen['InventoryOperationForm:vnodes'], (v) => v.type === 'input' && match(v.props || {}))[0]
}

/**
 * The text of every `#column-*` slot in rendered markup, by column.
 *
 * @param {string} html The markup.
 * @return {object} Column => text.
 */
function columnCells(html) {
	const cells = {}
	for (const [, key, inner] of html.matchAll(/<slot-column-(\w+)>([\s\S]*?)<\/slot-column-\1>/g)) {
		cells[key] = inner.replace(/<[^>]+>|<!--[\s\S]*?-->/g, '').replace(/\s+/g, ' ').trim()
	}
	return cells
}

beforeEach(() => {
	vi.clearAllMocks()
	store.isOnline = true
})

describe('CountOp', () => {
	it.each(['ssr', 'vnode'])('shows the system quantity and the variance once it is known (%s slot)', async (slotMode) => {
		const known = await renderSsr(CountOp, { standIns: FIELDS, slotMode, data: { systemQuantity: 7, physicalQuantity: 10 } })
		const unknown = await renderSsr(CountOp, { standIns: FIELDS, slotMode, data: { systemQuantity: null, physicalQuantity: 10 } })

		expect(known.html).toContain('System qty: 7')
		expect(known.html).toContain('Variance: 3')
		expect(unknown.html).not.toContain('System qty')
	})

	it.each(['ssr', 'vnode'])('opens the scanner when the SKU field asks for a scan (%s slot)', async (slotMode) => {
		const { vm, seen } = await renderSsr(CountOp, { standIns: FIELDS, slotMode })

		expect(vm.scanning).toBe(false)
		seen.InventorySkuField.onScan()

		expect(vm.scanning).toBe(true)
	})

	it('writes the physical count and re-reads the system quantity on input', async () => {
		readStockQuantity.mockResolvedValue(4)
		const { vm, seen } = await renderSsr(CountOp, { standIns: FIELDS, slotMode: 'vnode', data: { sku: 'SKU-1', location: 'A-01' } })
		const count = formInput(seen, (p) => p.type === 'number')

		count.props['onUpdate:modelValue'](12)
		await count.props.onInput()

		expect(vm.physicalQuantity).toBe(12)
		expect(readStockQuantity).toHaveBeenCalledWith(store.db, 'SKU-1', 'A-01')
		expect(vm.systemQuantity).toBe(4)
		expect(vm.variance).toBe(8)
	})

	it('sets the reconcile flag from its checkbox', async () => {
		const { vm, seen } = await renderSsr(CountOp, { standIns: FIELDS, slotMode: 'vnode' })

		formInput(seen, (p) => p.type === 'checkbox').props['onUpdate:modelValue'](true)

		expect(vm.reconcile).toBe(true)
	})

	it('re-reads the system quantity when the SKU or the location changes', async () => {
		const vm = { recomputeVariance: vi.fn().mockResolvedValue(undefined) }

		await CountOp.watch.sku.call(vm)
		await CountOp.watch.location.call(vm)

		expect(vm.recomputeVariance).toHaveBeenCalledTimes(2)
	})
})

describe('PickOp', () => {
	it('writes the optional order line id from its input', async () => {
		const { vm, seen } = await renderSsr(PickOp, { standIns: FIELDS, slotMode: 'vnode' })

		formInput(seen, (p) => p['aria-label'] === 'Order line id').props['onUpdate:modelValue']('OL-42')

		expect(vm.orderLineId).toBe('OL-42')
	})
})

describe('SyncStatusBadge', () => {
	it('syncs on click when online, and does nothing offline', async () => {
		await SyncStatusBadge.methods.handleClick.call({ store })
		expect(store.triggerSyncNow).toHaveBeenCalledTimes(1)

		store.isOnline = false
		await SyncStatusBadge.methods.handleClick.call({ store })
		expect(store.triggerSyncNow).toHaveBeenCalledTimes(1)
	})
})

describe('catalog cells', () => {
	it('render each product column through its slot, with the unknown placeholder where empty', async () => {
		const row = { productId: 'P-1', name: '', category: 'Tools', unitPrice: 12.5, currency: 'USD', unitCost: null, quantityOnHand: '3', status: null }
		const { html } = await renderSsr(ProductCatalogIndex, { standIns: ['CnDataTable'], slotProps: () => ({ row }) })

		expect(columnCells(html)).toMatchObject({
			productId: 'P-1',
			name: '—',
			category: 'Tools',
			unitPrice: '12.50 USD',
			unitCost: '—',
			quantityOnHand: '3.00',
			status: '—',
		})
		expect(fetchEnvelope).not.toHaveBeenCalled()
	})

	it('render the attribute flags through their slots', async () => {
		const required = await renderSsr(ProductAttributeCatalogIndex, { standIns: ['CnDataTable'], slotProps: () => ({ row: { isRequired: true, validationRule: '' } }) })
		const optional = await renderSsr(ProductAttributeCatalogIndex, { standIns: ['CnDataTable'], slotProps: () => ({ row: { isRequired: false, validationRule: '^[A-Z]+$' } }) })

		expect(columnCells(required.html)).toEqual({ isRequired: 'Yes', validationRule: '—' })
		expect(columnCells(optional.html)).toEqual({ isRequired: 'No', validationRule: '^[A-Z]+$' })
	})
})
