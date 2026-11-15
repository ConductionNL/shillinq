<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Receive Operation

  Implements the warehouse-manager-only Receive flow per REQ-INVENTORY-001:
  scan barcode → confirm SKU + qty + location → optimistic local update +
  queued pendingOp → background sync. Permission gate is enforced server-side
  per REQ-PERM-001; the UI mirrors the role list for early feedback.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.1
-->
<template>
	<InventoryOperationForm
		v-bind="formState"
		:title="t('shillinq', 'Receive Goods')"
		:submitLabel="t('shillinq', 'Confirm')"
		v-on="formEvents">
		<InventoryLocationField
			v-model="location"
			:locations="store.locations"
			:label="t('shillinq', 'Location')"
			:selectLabel="t('shillinq', 'Receiving location')"
			:placeholder="t('shillinq', 'Select a location')" />

		<InventorySkuField v-model="sku" @scan="scanning = true" />

		<InventoryQuantityField
			v-model="quantity"
			:inputLabel="t('shillinq', 'Quantity received')" />
	</InventoryOperationForm>
</template>

<script>
import InventoryLocationField from './InventoryLocationField.vue'
import InventoryOperationForm from './InventoryOperationForm.vue'
import InventoryQuantityField from './InventoryQuantityField.vue'
import InventorySkuField from './InventorySkuField.vue'
import { inventoryOperation } from './inventoryOperation.js'

export default {
	name: 'ReceiveOp',
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
			quantity: null,
		}
	},

	computed: {
		...inventoryOperation.computed,
	},

	methods: {
		...inventoryOperation.methods,

		/**
		 * Check the form and the submit guard, then record the receipt.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async handleConfirm() {
			this.error = null
			this.successMessage = null

			if (!this.receiveFieldsPresent() || !this.claimSubmitSlot()) {
				return
			}
			await this.recordReceipt()
		},

		/**
		 * Whether the location, the SKU or a positive quantity is missing.
		 *
		 * @return {boolean} True when the form is incomplete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		receiveFieldsMissing() {
			return (
				!this.location || !this.sku || !this.quantity || this.quantity <= 0
			)
		},

		/**
		 * Set the error when the location, the SKU or a positive quantity is
		 * missing.
		 *
		 * @return {boolean} Whether the form is complete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		receiveFieldsPresent() {
			if (!this.receiveFieldsMissing()) {
				return true
			}
			this.error = this.t(
				'shillinq',
				'Location, SKU and a positive quantity are required.',
			)
			return false
		},

		/**
		 * Queue the receipt as a pending operation and report the outcome on
		 * the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async recordReceipt() {
			const operation = {
				type: 'receive',
				sku: this.sku,
				location: this.location,
				quantity: this.quantity,
			}
			await this.queueOperation(operation, {
				queued: (result) => {
					this.successMessage = this.t(
						'shillinq',
						'Received {qty} units (pending sync)',
						{ qty: this.quantity },
					)
					this.sku = ''
					this.quantity = null
					this.lastTransactionId = result.transactionId
				},
				failed: (e) => this.receiveFailureText(e),
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
		receiveFailureText(e) {
			return e && e.message
				? e.message
				: this.t('shillinq', 'Could not record receipt.')
		},
	},
}
</script>
