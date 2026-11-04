/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'
import { claimSubmitSlot } from './claimSubmitSlot.js'

/**
 * The form state every warehouse operation starts with.
 *
 * @return {object} The SKU, scanner, submit and feedback state.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export function operationState() {
	return {
		sku: '',
		scanning: false,
		submitting: false,
		error: null,
		successMessage: null,
		lastSubmittedAt: 0,
	}
}

/**
 * The computed properties every warehouse operation shares.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export const operationComputed = {
	/**
	 * The inventory scanner store.
	 *
	 * @return {object} The store.
	 */
	store() {
		return useInventoryMobileScannerStore()
	},

	/**
	 * The feedback and progress state the operation form shows.
	 *
	 * @return {object} The error, success message, submitting and scanning state.
	 */
	formState() {
		return {
			error: this.error,
			successMessage: this.successMessage,
			submitting: this.submitting,
			scanning: this.scanning,
		}
	},

	/**
	 * The handlers of the operation form events.
	 *
	 * @return {object} The submit, scan and cancel handlers.
	 */
	formEvents() {
		return {
			submit: this.handleConfirm,
			scan: this.handleScan,
			cancel: this.closeScanner,
		}
	},
}

/**
 * The methods every warehouse operation shares. Spread them into the
 * component's methods, so this is the component.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export const operationMethods = {
	claimSubmitSlot,

	/**
	 * Take a scanned SKU and close the scanner.
	 *
	 * @param {string} value The scanned SKU.
	 */
	handleScan(value) {
		this.sku = value
		this.scanning = false
	},

	/**
	 * Close the scanner without a value.
	 */
	closeScanner() {
		this.scanning = false
	},

	/**
	 * Submit one pending operation while the form shows it is submitting,
	 * then report the outcome: queued receives the store answer, failed
	 * receives what the submit threw and returns the error to show.
	 *
	 * @param {object} operation The operation the scanner store submits.
	 * @param {{queued: function(object): void, failed: function(Error): string}} outcome The outcome handlers.
	 * @return {Promise<void>}
	 */
	async queueOperation(operation, outcome) {
		this.submitting = true
		try {
			outcome.queued(await this.store.submitOperation(operation))
		} catch (e) {
			this.error = outcome.failed(e)
		} finally {
			this.submitting = false
		}
	},
}

/**
 * Everything a warehouse operation shares, as one import: its starting
 * state, its computed properties and its methods.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export const inventoryOperation = {
	state: operationState,
	computed: operationComputed,
	methods: operationMethods,
}
