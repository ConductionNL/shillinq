<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Pick Operation

  Implements the inventory_operator Pick flow per REQ-INVENTORY-003:
  scan → reduce qty at location → mark order line picked. The optional
  orderLineId binds the pick to an open order so downstream reporting can
  reconcile pick-to-order.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.3
-->
<template>
	<InventoryOperationForm
		v-bind="formState"
		:warning="warning"
		:title="t('shillinq', 'Pick for Order')"
		:submitLabel="t('shillinq', 'Confirm pick')"
		v-on="formEvents">
		<InventoryLocationField
			v-model="location"
			:locations="store.locations"
			:label="t('shillinq', 'Location')"
			:selectLabel="t('shillinq', 'Pick location')"
			:placeholder="t('shillinq', 'Select a location')" />

		<InventorySkuField v-model="sku" @scan="scanning = true" />

		<label>
			<span>{{ t('shillinq', 'Order line id (optional)') }}</span>
			<input
				v-model="orderLineId"
				type="text"
				:aria-label="t('shillinq', 'Order line id')" />
		</label>

		<InventoryQuantityField
			v-model="quantity"
			:inputLabel="t('shillinq', 'Quantity to pick')" />
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
	name: 'PickOp',
	components: {
		InventoryLocationField,
		InventoryOperationForm,
		InventoryQuantityField,
		InventorySkuField,
	},

	data() {
		return {
			...inventoryOperation.state(),
			location: '',
			orderLineId: '',
			quantity: null,
			warning: null,
		}
	},

	computed: {
		...inventoryOperation.computed,
	},

	methods: {
		...inventoryOperation.methods,

		/**
		 * Check the form, the stock and the submit guard in turn, then record
		 * the pick.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async handleConfirm() {
			this.error = null
			this.warning = null
			this.successMessage = null

			if (
				!this.requiredFieldsPresent()
				|| !(await this.locationHasStock())
				|| !this.claimSubmitSlot()
			) {
				return
			}
			await this.recordPick()
		},

		/**
		 * Whether a location, SKU or a positive quantity is missing.
		 *
		 * @return {boolean} True when the form is incomplete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		pickFieldsMissing() {
			return (
				!this.location || !this.sku || !this.quantity || this.quantity <= 0
			)
		},

		/**
		 * Set the error when a location, SKU or positive quantity is missing.
		 *
		 * @return {boolean} Whether the form is complete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		requiredFieldsPresent() {
			if (!this.pickFieldsMissing()) {
				return true
			}
			this.error = this.t(
				'shillinq',
				'Location, SKU and a positive quantity are required.',
			)
			return false
		},

		/**
		 * Check the location has enough stock, setting the warning when it
		 * has not. A failed read lets the pick through.
		 *
		 * @return {Promise<boolean>} Whether the pick may continue.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async locationHasStock() {
			try {
				if (this.store.db) {
					const onHand = await readStockQuantity(
						this.store.db,
						this.sku,
						this.location,
					)
					return this.stockCovers(onHand)
				}
			} catch (e) {
				// Best-effort guard.
			}
			return true
		},

		/**
		 * Compare the stock on hand with the quantity, setting the warning
		 * when it falls short.
		 *
		 * @param {number} onHand The units at the location.
		 * @return {boolean} Whether the stock covers the quantity.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		stockCovers(onHand) {
			if (onHand < this.quantity) {
				this.warning = this.t(
					'shillinq',
					'Only {onHand} units available; reduce quantity or cancel.',
					{ onHand },
				)
				return false
			}
			return true
		},

		/**
		 * Queue the pick as a pending operation and report the outcome on
		 * the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async recordPick() {
			await this.queueOperation(this.pickOperation(), {
				queued: () => {
					this.successMessage = this.pickDoneMessage()
					this.sku = ''
					this.quantity = null
					this.orderLineId = ''
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
				: this.t('shillinq', 'Could not record pick.')
		},

		/**
		 * The confirmation shown once the pick is queued.
		 *
		 * @return {string} The translated message.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		pickDoneMessage() {
			return this.t('shillinq', 'Picked {qty} × {sku} (pending sync)', {
				qty: this.quantity,
				sku: this.sku,
			})
		},

		/**
		 * The pending pick operation for the current form.
		 *
		 * @return {object} The operation the scanner store submits.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		pickOperation() {
			return {
				type: 'pick',
				sku: this.sku,
				location: this.location,
				quantity: this.quantity,
				orderLineId: this.orderLineId || null,
			}
		},
	},
}
</script>
