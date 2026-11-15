/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * CountOp: the variance between the physical and system counts, the
 * system-quantity recompute off the local stock db, the pending count
 * operation it queues, the form reset after a count and the failure text.
 * The end-to-end handleConfirm flow is golden-tested in
 * inventoryCountReceiveConfirm.golden.spec.js; this file covers the variance,
 * recompute, operation-shape and reset methods directly.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readStockQuantity } from '../../src/composables/useInventoryDb.js'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/composables/useInventoryDb.js', () => ({ readStockQuantity: vi.fn() }))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => ({ db: { name: 'db' }, submitOperation: vi.fn() }),
}))
vi.mock('../../src/components/inventory/InventoryLocationField.vue', () => ({ default: {} }))
vi.mock('../../src/components/inventory/InventoryOperationForm.vue', () => ({ default: {} }))
vi.mock('../../src/components/inventory/InventorySkuField.vue', () => ({ default: {} }))

const { default: CountOp } = await import('../../src/components/inventory/CountOp.vue')

const T = (app, text, vars) => (vars ? `${text}|${JSON.stringify(vars)}` : text)

/**
 * A fake CountOp with data, a translator, a store and bound methods. The
 * `variance` computed is resolved on read.
 *
 * @param {object} state Field overrides.
 * @param {object} store Store overrides.
 * @return {object} The instance.
 */
function op(state = {}, store = {}) {
	const vm = { ...CountOp.data.call({}), t: T, ...state }
	vm.store = { db: { name: 'db' }, ...store }
	for (const [name, fn] of Object.entries(CountOp.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.defineProperty(vm, 'variance', {
		get: () => CountOp.computed.variance.call(vm),
		configurable: true,
	})
	return vm
}

describe('CountOp variance', () => {
	it('is null until both counts are known', () => {
		expect(op({ systemQuantity: null, physicalQuantity: 5 }).variance).toBeNull()
		expect(op({ systemQuantity: 5, physicalQuantity: null }).variance).toBeNull()
	})

	it('is the physical count minus the system count', () => {
		expect(op({ systemQuantity: 8, physicalQuantity: 5 }).variance).toBe(-3)
		expect(op({ systemQuantity: '2', physicalQuantity: '9' }).variance).toBe(7)
	})
})

describe('CountOp recomputeVariance', () => {
	beforeEach(() => { readStockQuantity.mockReset() })

	it('clears the system quantity when the db, the SKU or the location is missing', async () => {
		const vm = op({ sku: '', location: 'A', systemQuantity: 4 })
		await vm.recomputeVariance()
		expect(vm.systemQuantity).toBeNull()
		expect(readStockQuantity).not.toHaveBeenCalled()
	})

	it('reads the stock quantity for the SKU and location', async () => {
		readStockQuantity.mockResolvedValueOnce(12)
		const vm = op({ sku: 'SKU-1', location: 'A-1' })
		await vm.recomputeVariance()
		expect(readStockQuantity).toHaveBeenCalledWith({ name: 'db' }, 'SKU-1', 'A-1')
		expect(vm.systemQuantity).toBe(12)
	})

	it('falls back to zero when the stock read throws', async () => {
		readStockQuantity.mockRejectedValueOnce(new Error('db gone'))
		const vm = op({ sku: 'SKU-1', location: 'A-1' })
		await vm.recomputeVariance()
		expect(vm.systemQuantity).toBe(0)
	})
})

describe('CountOp form', () => {
	it('reports the fields missing until location, SKU and a non-negative count are present', () => {
		expect(op({ location: '', sku: 'S', physicalQuantity: 1 }).countFieldsMissing()).toBe(true)
		expect(op({ location: 'A', sku: '', physicalQuantity: 1 }).countFieldsMissing()).toBe(true)
		expect(op({ location: 'A', sku: 'S', physicalQuantity: null }).countFieldsMissing()).toBe(true)
		expect(op({ location: 'A', sku: 'S', physicalQuantity: -1 }).countFieldsMissing()).toBe(true)
		expect(op({ location: 'A', sku: 'S', physicalQuantity: 0 }).countFieldsMissing()).toBe(false)
	})

	it('sets the error and returns false when a field is missing', () => {
		const vm = op({ location: '', sku: 'S', physicalQuantity: 1 })
		expect(vm.countFieldsPresent()).toBe(false)
		expect(vm.error).toBe('Location, SKU and a non-negative physical count are required.')
	})

	it('returns true and leaves no error when the fields are present', () => {
		const vm = op({ location: 'A', sku: 'S', physicalQuantity: 0, error: null })
		expect(vm.countFieldsPresent()).toBe(true)
		expect(vm.error).toBeNull()
	})

	it('shapes the pending count operation from the form', () => {
		const vm = op({ sku: 'S', location: 'A', physicalQuantity: 7, reconcile: true })
		expect(vm.countOperation()).toEqual({
			type: 'count', sku: 'S', location: 'A', physicalQuantity: 7, reconcile: true,
		})
	})

	it('empties the form but keeps the location after a count', () => {
		const vm = op({ sku: 'S', location: 'A', physicalQuantity: 7, systemQuantity: 5, reconcile: true })
		vm.clearCount()
		expect(vm.sku).toBe('')
		expect(vm.physicalQuantity).toBeNull()
		expect(vm.systemQuantity).toBeNull()
		expect(vm.reconcile).toBe(false)
		expect(vm.location).toBe('A')
	})

	it('shows a thrown message, else a generic one, for a failed submit', () => {
		const vm = op()
		expect(vm.countFailureText({ message: 'conflict' })).toBe('conflict')
		expect(vm.countFailureText(null)).toBe('Could not record count.')
		expect(vm.countFailureText({})).toBe('Could not record count.')
	})
})
