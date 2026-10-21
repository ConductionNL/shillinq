// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The four warehouse scanner operations as configuration for ScanOperation:
// which fields each asks, how it checks them, what it queues and what it says.

import { translate as t } from '@nextcloud/l10n'

/**
 * @typedef {object} ScanOperationConfig
 * @property {string} title Page heading (English source string).
 * @property {string} locationLabel Label of the (source) location select.
 * @property {string} locationAria Accessible name of that select.
 * @property {boolean} toLocation Asks a destination location.
 * @property {boolean} orderLine Asks an optional order line id.
 * @property {string} quantityField `quantity` or `physicalQuantity`.
 * @property {string} quantityLabel Label of the quantity input.
 * @property {string} quantityAria Accessible name of the quantity input.
 * @property {number} quantityMin Lowest quantity the input allows.
 * @property {boolean} reconcile Offers the reconcile checkbox.
 * @property {boolean} variance Shows the system quantity and the variance.
 * @property {string} stockCheck `''`, `error` or `warning` when the location holds less than the quantity.
 * @property {string} submitLabel Label of the confirm button.
 * @property {string} failure Message when queuing fails.
 */

/** @type {Record<string, ScanOperationConfig>} */
export const SCAN_OPERATIONS = Object.freeze({
	receive: {
		title: 'Receive Goods',
		locationLabel: 'Location',
		locationAria: 'Receiving location',
		toLocation: false,
		orderLine: false,
		quantityField: 'quantity',
		quantityLabel: 'Quantity',
		quantityAria: 'Quantity received',
		quantityMin: 0.01,
		reconcile: false,
		variance: false,
		stockCheck: '',
		submitLabel: 'Confirm',
		failure: 'Could not record receipt.',
	},
	transfer: {
		title: 'Transfer Inventory',
		locationLabel: 'From location',
		locationAria: 'Source location',
		toLocation: true,
		orderLine: false,
		quantityField: 'quantity',
		quantityLabel: 'Quantity',
		quantityAria: 'Quantity to transfer',
		quantityMin: 0.01,
		reconcile: false,
		variance: false,
		stockCheck: 'error',
		submitLabel: 'Confirm',
		failure: 'Could not record transfer.',
	},
	pick: {
		title: 'Pick for Order',
		locationLabel: 'Location',
		locationAria: 'Pick location',
		toLocation: false,
		orderLine: true,
		quantityField: 'quantity',
		quantityLabel: 'Quantity',
		quantityAria: 'Quantity to pick',
		quantityMin: 0.01,
		reconcile: false,
		variance: false,
		stockCheck: 'warning',
		submitLabel: 'Confirm pick',
		failure: 'Could not record pick.',
	},
	count: {
		title: 'Inventory Count',
		locationLabel: 'Location',
		locationAria: 'Count location',
		toLocation: false,
		orderLine: false,
		quantityField: 'physicalQuantity',
		quantityLabel: 'Physical count',
		quantityAria: 'Physical count',
		quantityMin: 0,
		reconcile: true,
		variance: true,
		stockCheck: '',
		submitLabel: 'Save count',
		failure: 'Could not record count.',
	},
})

/**
 * The configuration of an operation.
 *
 * @param {string} operation receive, transfer, pick or count.
 * @return {ScanOperationConfig|null} The configuration, or null when unknown.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function scanOperation(operation) {
	return SCAN_OPERATIONS[operation] || null
}

/**
 * Why the form cannot be queued yet, or '' when it can.
 *
 * @param {string} operation The operation.
 * @param {object} form The form values.
 * @return {string} The translated reason, or ''.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function validateScan(operation, form) {
	const f = form || {}
	if (operation === 'count') {
		const count = f.physicalQuantity
		if (!f.location || !f.sku || count === null || count === undefined || count === '' || Number(count) < 0) {
			return t('shillinq', 'Location, SKU and a non-negative physical count are required.')
		}
		return ''
	}
	if (operation === 'transfer') {
		if (!f.location || !f.toLocation || !f.sku || !(Number(f.quantity) > 0)) {
			return t('shillinq', 'Source, destination, SKU and a positive quantity are required.')
		}
		if (f.location === f.toLocation) {
			return t('shillinq', 'Source and destination must differ.')
		}
		return ''
	}
	if (!f.location || !f.sku || !(Number(f.quantity) > 0)) {
		return t('shillinq', 'Location, SKU and a positive quantity are required.')
	}
	return ''
}

/**
 * What the location holding too little stock means for this operation.
 *
 * @param {string} operation The operation.
 * @param {object} form The form values.
 * @param {number} onHand The quantity at the (source) location.
 * @return {string} The translated message, or '' when there is enough.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function stockShortage(operation, form, onHand) {
	const config = scanOperation(operation)
	if (!config || !config.stockCheck || !(onHand < Number(form.quantity))) {
		return ''
	}
	if (operation === 'transfer') {
		return t('shillinq', 'Only {onHand} units at {loc}, cannot transfer {qty}.', { onHand, loc: form.location, qty: form.quantity })
	}
	return t('shillinq', 'Only {onHand} units available; reduce quantity or cancel.', { onHand })
}

/**
 * The pending operation queued for sync.
 *
 * @param {string} operation The operation.
 * @param {object} form The form values.
 * @return {object} The queued operation.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function scanPayload(operation, form) {
	const base = { type: operation, sku: form.sku, location: form.location }
	switch (operation) {
	case 'transfer':
		return { ...base, toLocation: form.toLocation, quantity: form.quantity }
	case 'pick':
		return { ...base, quantity: form.quantity, orderLineId: form.orderLineId || null }
	case 'count':
		return { ...base, physicalQuantity: form.physicalQuantity, reconcile: Boolean(form.reconcile) }
	default:
		return { ...base, quantity: form.quantity }
	}
}

/**
 * The confirmation after queuing.
 *
 * @param {string} operation The operation.
 * @param {object} form The form values as queued.
 * @param {number|null} variance Count only: physical minus system quantity.
 * @return {string} The translated message.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function scanSuccess(operation, form, variance) {
	switch (operation) {
	case 'transfer':
		return t('shillinq', 'Transferred {qty} units {from} → {to} (pending sync)', { qty: form.quantity, from: form.location, to: form.toLocation })
	case 'pick':
		return t('shillinq', 'Picked {qty} × {sku} (pending sync)', { qty: form.quantity, sku: form.sku })
	case 'count':
		return t('shillinq', 'Count recorded: variance {variance} (pending sync)', { variance })
	default:
		return t('shillinq', 'Received {qty} units (pending sync)', { qty: form.quantity })
	}
}

/**
 * The form after a successful queue: location kept, the rest cleared.
 *
 * @param {object} form The form values.
 * @return {object} The next form.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function nextScanForm(form) {
	return { ...emptyScanForm(), location: form.location, toLocation: form.toLocation }
}

/**
 * An empty form.
 *
 * @return {object} The form values.
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function emptyScanForm() {
	return { location: '', toLocation: '', sku: '', orderLineId: '', quantity: null, physicalQuantity: null, reconcile: false }
}
