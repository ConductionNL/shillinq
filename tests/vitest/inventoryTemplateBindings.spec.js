/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The template wiring of the inventory components, server-rendered from
 * their real templates: the v-model and scan bindings each warehouse
 * operation hands its child fields, the scan / cancel relay of the shared
 * operation frame, the scanner's camera states, the catalog tables' column
 * slots and error line, and the sync badge's refresh ticker. Child
 * components are replaced by stand-ins that record the props and listeners
 * they receive, so a spec can fire a listener and read back the state the
 * template wrote.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-002
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { renderWithStandIns } from './helpers/renderWithStandIns.js'

const scanner = vi.hoisted(() => ({ store: null }))

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readStockQuantity: async () => 7,
}))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => scanner.store,
}))

const { default: ReceiveOp } = await import('../../src/components/inventory/ReceiveOp.vue')
const { default: TransferOp } = await import('../../src/components/inventory/TransferOp.vue')
const { default: PickOp } = await import('../../src/components/inventory/PickOp.vue')
const { default: CountOp } = await import('../../src/components/inventory/CountOp.vue')
const { default: InventoryOperationForm } = await import('../../src/components/inventory/InventoryOperationForm.vue')
const { default: InventoryLocationField } = await import('../../src/components/inventory/InventoryLocationField.vue')
const { default: BarcodeScanner } = await import('../../src/components/inventory/BarcodeScanner.vue')
const { default: ProductCatalogIndex } = await import('../../src/components/inventory/ProductCatalogIndex.vue')
const { default: ProductAttributeCatalogIndex } = await import('../../src/components/inventory/ProductAttributeCatalogIndex.vue')
const { default: SyncStatusBadge } = await import('../../src/components/inventory/SyncStatusBadge.vue')

const LOCATIONS = [
	{ code: 'A', name: 'Aisle A' },
	{ code: 'B', name: 'Back room' },
]

/**
 * A CnDataTable stand-in that renders each row through the parent's
 * `#column-<key>` scoped slots, one cell per column.
 *
 * @return {object} The component.
 */
function tableStandIn() {
	return {
		name: 'CnDataTable',
		props: { columns: Array, rows: Array, loading: Boolean, emptyText: String },
		setup(props, { slots }) {
			return () => h('table', { 'data-empty': props.emptyText },
				(props.rows || []).map((row) => h('tr', {},
					props.columns.map((col) => h('td', { 'data-col': col.key },
						slots[`column-${col.key}`]?.({ row, value: row[col.key] }) ?? String(row[col.key] ?? ''))))))
		},
	}
}

const render = renderWithStandIns

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<!--[^>]*-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

const OP_CHILDREN = ['InventoryOperationForm', 'InventoryLocationField', 'InventorySkuField', 'InventoryQuantityField']

afterEach(() => {
	scanner.store = null
	vi.useRealTimers()
})

describe('warehouse operations bind their fields to their own state', () => {
	it.each([
		['ReceiveOp', ReceiveOp],
		['PickOp', PickOp],
		['CountOp', CountOp],
	])('%s writes the chosen location, the typed SKU and a scan request back', async (_name, component) => {
		scanner.store = { db: null, locations: LOCATIONS }
		const { vm, seen } = await render(component, { record: OP_CHILDREN })

		const [location] = seen.InventoryLocationField
		expect(location.locations).toBe(LOCATIONS)
		location['onUpdate:modelValue']('B')
		expect(vm.location).toBe('B')

		const [sku] = seen.InventorySkuField
		sku['onUpdate:modelValue']('SKU-9')
		expect(vm.sku).toBe('SKU-9')
		expect(vm.scanning).toBe(false)
		sku.onScan()
		expect(vm.scanning).toBe(true)
	})

	it.each([
		['ReceiveOp', ReceiveOp],
		['PickOp', PickOp],
		['TransferOp', TransferOp],
	])('%s writes the entered quantity back', async (_name, component) => {
		scanner.store = { db: null, locations: LOCATIONS }
		const { vm, seen } = await render(component, { record: OP_CHILDREN })

		const [quantity] = seen.InventoryQuantityField
		quantity['onUpdate:modelValue'](3)
		expect(vm.quantity).toBe(3)
	})

	it('TransferOp binds source and destination to separate fields', async () => {
		scanner.store = { db: null, locations: LOCATIONS }
		const { vm, seen } = await render(TransferOp, { record: OP_CHILDREN })

		const [from, to] = seen.InventoryLocationField
		expect(from.label).toBe('From location')
		expect(to.label).toBe('To location')
		from['onUpdate:modelValue']('A')
		to['onUpdate:modelValue']('B')
		expect([vm.fromLocation, vm.toLocation]).toEqual(['A', 'B'])

		const [sku] = seen.InventorySkuField
		sku['onUpdate:modelValue']('SKU-2')
		sku.onScan()
		expect([vm.sku, vm.scanning]).toEqual(['SKU-2', true])
	})

	it('CountOp asks for a manual fallback and labels its own submit', async () => {
		scanner.store = { db: null, locations: LOCATIONS }
		const { seen } = await render(CountOp, { record: OP_CHILDREN })

		const [form] = seen.InventoryOperationForm
		expect(form.fallbackToManual).toBe('')
		expect(form.submitLabel).toBe('Save count')
		expect(form.title).toBe('Inventory Count')
	})
})

describe('InventoryOperationForm', () => {
	it('relays the scanner\'s scan value and cancel as its own events', async () => {
		const onScan = vi.fn()
		const onCancel = vi.fn()
		const { seen } = await render(InventoryOperationForm, {
			props: { title: 'Receive', submitLabel: 'Go', scanning: true, fallbackToManual: true, onScan, onCancel },
			record: ['BarcodeScanner'],
		})

		const [barcode] = seen.BarcodeScanner
		expect(barcode.fallbackToManual).toBe(true)
		barcode.onScan('8712345678906')
		barcode.onCancel()
		expect(onScan).toHaveBeenCalledWith('8712345678906')
		expect(onCancel).toHaveBeenCalledTimes(1)
	})

	it('renders no scanner while not scanning', async () => {
		const { seen, html } = await render(InventoryOperationForm, {
			props: { title: 'Receive', submitLabel: 'Go' },
			record: ['BarcodeScanner'],
		})
		expect(seen.BarcodeScanner).toBeUndefined()
		expect(html).not.toContain('BarcodeScanner')
	})
})

describe('InventoryLocationField', () => {
	it('offers only the empty option when no locations are passed', async () => {
		const { html } = await render(InventoryLocationField, {
			props: { label: 'Location', selectLabel: 'Pick one', placeholder: 'Select' },
		})
		expect(html.match(/<option/g)).toHaveLength(1)
		expect(text(html)).toBe('Location Select')
	})
})

describe('BarcodeScanner', () => {
	it('shows the camera error instead of a viewport', async () => {
		const { html } = await render(BarcodeScanner, { data: { cameraError: 'Camera unavailable; use manual entry.' } })
		expect(html).toContain('role="status"')
		expect(text(html)).toContain('Camera unavailable; use manual entry.')
		expect(html).not.toContain('<video')
	})

	it('shows the live viewport and its hint once the camera is ready', async () => {
		const { html } = await render(BarcodeScanner, { data: { cameraReady: true } })
		expect(html).toContain('<video')
		expect(html).toContain('aria-label="Live camera preview for barcode scanning"')
		expect(text(html)).toContain('Point the camera at the barcode')
		expect(html).not.toContain('barcode-scanner__error')
	})
})

describe('ProductCatalogIndex template', () => {
	const ROW = {
		productId: 'P-1',
		name: '',
		category: null,
		unitPrice: 18.99,
		currency: 'USD',
		unitCost: null,
		quantityOnHand: 4,
		status: 'active',
	}

	it('renders each column through its own slot', async () => {
		const { html } = await render(ProductCatalogIndex, {
			data: { products: [ROW], loading: false },
			components: { CnDataTable: tableStandIn() },
		})
		const cell = (key) => text(html.match(new RegExp(`<td data-col="${key}">(.*?)</td>`))[1])
		expect(html).toContain('<code class="product-catalog__id"')
		expect(cell('productId')).toBe('P-1')
		expect(cell('name')).toBe('—')
		expect(cell('category')).toBe('—')
		expect(cell('unitPrice')).toBe('18.99 USD')
		expect(cell('unitCost')).toBe('—')
		expect(cell('quantityOnHand')).toBe('4.00')
		expect(cell('status')).toBe('active')
	})

	it('shows the error line only when there is an error', async () => {
		const failed = await render(ProductCatalogIndex, {
			data: { error: 'Could not load the product catalog.', loading: false },
			components: { CnDataTable: tableStandIn() },
		})
		expect(failed.html).toContain('data-testid="product-catalog-error"')
		expect(text(failed.html)).toContain('Could not load the product catalog.')

		const fine = await render(ProductCatalogIndex, { components: { CnDataTable: tableStandIn() } })
		expect(fine.html).not.toContain('product-catalog-error')
	})

	it('loads the catalog when mounted', () => {
		const loadCatalog = vi.fn()
		ProductCatalogIndex.mounted.call({ loadCatalog })
		expect(loadCatalog).toHaveBeenCalledTimes(1)
	})
})

describe('ProductAttributeCatalogIndex template', () => {
	it('renders the required flag as Yes/No and a missing rule as a dash', async () => {
		const { html } = await render(ProductAttributeCatalogIndex, {
			data: {
				attributes: [
					{ key: 'colour', isRequired: true, validationRule: 'enum:red,blue' },
					{ key: 'size', isRequired: false, validationRule: '' },
				],
				loading: false,
			},
			components: { CnDataTable: tableStandIn() },
		})
		const cells = (key) => [...html.matchAll(new RegExp(`<td data-col="${key}">(.*?)</td>`, 'g'))].map((m) => text(m[1]))
		expect(cells('isRequired')).toEqual(['Yes', 'No'])
		expect(cells('validationRule')).toEqual(['enum:red,blue', '—'])
		expect(html).toContain('data-empty="No attribute definitions are available."')
	})

	it('shows the error line only when there is an error', async () => {
		const { html } = await render(ProductAttributeCatalogIndex, {
			data: { error: 'Could not load', loading: false },
			components: { CnDataTable: tableStandIn() },
		})
		expect(html).toContain('data-testid="product-attributes-error"')
		expect(text(html)).toContain('Could not load')
	})

	it('loads the attributes when mounted', () => {
		const loadAttributes = vi.fn()
		ProductAttributeCatalogIndex.mounted.call({ loadAttributes })
		expect(loadAttributes).toHaveBeenCalledTimes(1)
	})
})

describe('SyncStatusBadge ticker', () => {
	it('refreshes `now` every 30 seconds after mounting and stops on unmount', () => {
		vi.useFakeTimers()
		vi.setSystemTime(Date.parse('2026-06-01T12:00:00Z'))
		const vm = { now: 0, ticker: null }
		SyncStatusBadge.mounted.call(vm)
		expect(vm.ticker).not.toBeNull()

		vi.advanceTimersByTime(29_999)
		expect(vm.now).toBe(0)
		vi.advanceTimersByTime(1)
		expect(vm.now).toBe(Date.parse('2026-06-01T12:00:30Z'))

		SyncStatusBadge.beforeUnmount.call(vm)
		expect(vm.ticker).toBeNull()
		vi.advanceTimersByTime(60_000)
		expect(vm.now).toBe(Date.parse('2026-06-01T12:00:30Z'))
	})

	it('unmounting without a ticker is a no-op', () => {
		const vm = { ticker: null }
		SyncStatusBadge.beforeUnmount.call(vm)
		expect(vm.ticker).toBeNull()
	})
})
