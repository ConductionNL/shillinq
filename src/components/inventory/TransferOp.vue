<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Transfer Operation

  Implements the inventory_operator-only Transfer flow per REQ-INVENTORY-002:
  select source → scan → select destination → confirm. Both InventoryStock
  mutations are dispatched in a single pendingOp so the server applies them
  atomically.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.2
-->
<template>
	<InventoryOperationForm
		v-bind="formState"
		:title="t('shillinq', 'Transfer Inventory')"
		:submitLabel="t('shillinq', 'Confirm')"
		v-on="formEvents">
		<InventoryLocationField
			v-model="fromLocation"
			:locations="store.locations"
			:label="t('shillinq', 'From location')"
			:selectLabel="t('shillinq', 'Source location')"
			:placeholder="t('shillinq', 'Select source')" />

		<InventorySkuField v-model="sku" @scan="scanning = true" />

		<InventoryLocationField
			v-model="toLocation"
			:locations="availableDestinations"
			:label="t('shillinq', 'To location')"
			:selectLabel="t('shillinq', 'Destination location')"
			:placeholder="t('shillinq', 'Select destination')" />

		<InventoryQuantityField
			v-model="quantity"
			:inputLabel="t('shillinq', 'Quantity to transfer')" />
	</InventoryOperationForm>
</template>

<script>
import InventoryLocationField from './InventoryLocationField.vue'
import InventoryOperationForm from './InventoryOperationForm.vue'
import InventoryQuantityField from './InventoryQuantityField.vue'
import InventorySkuField from './InventorySkuField.vue'
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { inventoryOperation } from './inventoryOperation.js'

export default {
	name: 'TransferOp',
	components: {
		InventoryLocationField,
		InventoryOperationForm,
		InventoryQuantityField,
		InventorySkuField,
	},

	data() {
		return {
			...inventoryOperation.state(),
			fromLocation: '',
			toLocation: '',
			quantity: null,
		}
	},

	computed: {
		...inventoryOperation.computed,

		availableDestinations() {
			return this.store.locations.filter(
				(loc) => loc.code !== this.fromLocation,
			)
		},
	},

	methods: {
		...inventoryOperation.methods,

		/**
		 * Check the form, the locations, the stock and the submit guard in turn,
		 * then record the transfer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async handleConfirm() {
			this.error = null
			this.successMessage = null

			if (
				!this.requiredFieldsPresent()
				|| !this.locationsDiffer()
				|| !(await this.sourceHasStock())
				|| !this.claimSubmitSlot()
			) {
				return
			}
			await this.recordTransfer()
		},

		/**
		 * Whether a source, destination, SKU or a positive quantity is missing.
		 *
		 * @return {boolean} True when the form is incomplete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		transferFieldsMissing() {
			return (
				!this.fromLocation
				|| !this.toLocation
				|| !this.sku
				|| !this.quantity
				|| this.quantity <= 0
			)
		},

		/**
		 * Set the error when a source, destination, SKU or positive quantity
		 * is missing.
		 *
		 * @return {boolean} Whether the form is complete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		requiredFieldsPresent() {
			if (!this.transferFieldsMissing()) {
				return true
			}
			this.error = this.t(
				'shillinq',
				'Source, destination, SKU and a positive quantity are required.',
			)
			return false
		},

		/**
		 * Set the error when the source and destination are the same.
		 *
		 * @return {boolean} Whether the locations differ.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		locationsDiffer() {
			if (this.fromLocation !== this.toLocation) {
				return true
			}
			this.error = this.t('shillinq', 'Source and destination must differ.')
			return false
		},

		/**
		 * Validate the source has enough stock (REQ-INVENTORY-002 acceptance),
		 * setting the error when it has not. A failed read lets the transfer
		 * through; the server rejects it if the quantity ends up negative.
		 *
		 * @return {Promise<boolean>} Whether the transfer may continue.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async sourceHasStock() {
			try {
				if (this.store.db) {
					const onHand = await readStockQuantity(
						this.store.db,
						this.sku,
						this.fromLocation,
					)
					return this.stockCovers(onHand)
				}
			} catch (e) {
				// Best-effort guard.
			}
			return true
		},

		/**
		 * Compare the stock on hand with the quantity, setting the error when
		 * it falls short.
		 *
		 * @param {number} onHand The units at the source location.
		 * @return {boolean} Whether the stock covers the quantity.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		stockCovers(onHand) {
			if (onHand < this.quantity) {
				this.error = this.t(
					'shillinq',
					'Only {onHand} units at {loc}, cannot transfer {qty}.',
					{ onHand, loc: this.fromLocation, qty: this.quantity },
				)
				return false
			}
			return true
		},

		/**
		 * Dispatch both stock mutations as one pending operation and report
		 * the outcome on the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async recordTransfer() {
			await this.queueOperation(this.transferOperation(), {
				queued: () => {
					this.successMessage = this.transferDoneMessage()
					this.sku = ''
					this.quantity = null
				},
				failed: (e) => this.failureText(e),
			})
		},

		/**
		 * The message of a failed submit, or a generic one.
		 *
		 * @param {Error|object|null} e What the submit threw.
		 * @return {string} The error to show.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		failureText(e) {
			return e && e.message
				? e.message
				: this.t('shillinq', 'Could not record transfer.')
		},

		/**
		 * The confirmation shown once the transfer is queued.
		 *
		 * @return {string} The translated message.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		transferDoneMessage() {
			return this.t(
				'shillinq',
				'Transferred {qty} units {from} → {to} (pending sync)',
				{
					qty: this.quantity,
					from: this.fromLocation,
					to: this.toLocation,
				},
			)
		},

		/**
		 * The pending transfer operation for the current form.
		 *
		 * @return {object} The operation the scanner store submits.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		transferOperation() {
			return {
				type: 'transfer',
				sku: this.sku,
				location: this.fromLocation,
				toLocation: this.toLocation,
				quantity: this.quantity,
			}
		},
	},
}
</script>
