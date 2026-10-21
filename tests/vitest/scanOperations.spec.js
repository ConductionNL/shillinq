/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The four warehouse scanner operations as one configuration
 * (src/views/inventory/scanOperations.js), which ScanOperation.vue renders.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))

const {
	SCAN_OPERATIONS,
	emptyScanForm,
	nextScanForm,
	scanOperation,
	scanPayload,
	scanSuccess,
	stockShortage,
	validateScan,
} = await import('../../src/views/inventory/scanOperations.js')

const manifest = (await import('../../src/manifest.d/inventory-mobile-scanner.json')).default

describe('scanOperations', () => {
	it('configures exactly the four operations, and the manifest pages use them', () => {
		expect(Object.keys(SCAN_OPERATIONS).sort()).toEqual(['count', 'pick', 'receive', 'transfer'])
		const pages = manifest.pages.filter((p) => p.component === 'ScanOperation')
		expect(pages.map((p) => [p.id, p.config.operation])).toEqual([
			['MobileScannerReceive', 'receive'],
			['MobileScannerTransfer', 'transfer'],
			['MobileScannerPick', 'pick'],
			['MobileScannerCount', 'count'],
		])
		expect(scanOperation('unknown')).toBeNull()
	})

	it('requires location, code and a positive quantity; count allows zero', () => {
		const form = { ...emptyScanForm(), location: 'A1', sku: 'SKU-1' }
		expect(validateScan('receive', form)).toContain('positive quantity')
		expect(validateScan('receive', { ...form, quantity: 2 })).toBe('')
		expect(validateScan('pick', { ...form, sku: '', quantity: 2 })).toContain('required')
		expect(validateScan('count', { ...form, physicalQuantity: 0 })).toBe('')
		expect(validateScan('count', { ...form, physicalQuantity: -1 })).toContain('non-negative')
	})

	it('needs a different destination for a transfer', () => {
		const form = { ...emptyScanForm(), location: 'A1', sku: 'SKU-1', quantity: 2 }
		expect(validateScan('transfer', form)).toContain('destination')
		expect(validateScan('transfer', { ...form, toLocation: 'A1' })).toBe('Source and destination must differ.')
		expect(validateScan('transfer', { ...form, toLocation: 'B2' })).toBe('')
	})

	it('flags too little stock for transfer and pick only', () => {
		const form = { location: 'A1', sku: 'SKU-1', quantity: 5 }
		expect(stockShortage('transfer', form, 3)).toBe('Only 3 units at A1, cannot transfer 5.')
		expect(stockShortage('pick', form, 3)).toBe('Only 3 units available; reduce quantity or cancel.')
		expect(stockShortage('pick', form, 5)).toBe('')
		expect(stockShortage('receive', form, 0)).toBe('')
		expect(SCAN_OPERATIONS.transfer.stockCheck).toBe('error')
		expect(SCAN_OPERATIONS.pick.stockCheck).toBe('warning')
	})

	it('queues the same operations the four screens queued', () => {
		const form = { ...emptyScanForm(), location: 'A1', toLocation: 'B2', sku: 'SKU-1', quantity: 2, physicalQuantity: 7, reconcile: true }
		expect(scanPayload('receive', form)).toEqual({ type: 'receive', sku: 'SKU-1', location: 'A1', quantity: 2 })
		expect(scanPayload('transfer', form)).toEqual({ type: 'transfer', sku: 'SKU-1', location: 'A1', toLocation: 'B2', quantity: 2 })
		expect(scanPayload('pick', form)).toEqual({ type: 'pick', sku: 'SKU-1', location: 'A1', quantity: 2, orderLineId: null })
		expect(scanPayload('count', form)).toEqual({ type: 'count', sku: 'SKU-1', location: 'A1', physicalQuantity: 7, reconcile: true })
	})

	it('confirms what was queued and keeps the locations for the next scan', () => {
		const form = { ...emptyScanForm(), location: 'A1', toLocation: 'B2', sku: 'SKU-1', quantity: 2 }
		expect(scanSuccess('receive', form)).toBe('Received 2 units (pending sync)')
		expect(scanSuccess('count', form, -1)).toBe('Count recorded: variance -1 (pending sync)')
		expect(nextScanForm(form)).toEqual({ ...emptyScanForm(), location: 'A1', toLocation: 'B2' })
	})
})
