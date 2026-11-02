<!--
  Pick Operation

  Implements the inventory_operator Pick flow per REQ-INVENTORY-003:
  scan → reduce qty at location → mark order line picked. The optional
  orderLineId binds the pick to an open order so downstream reporting can
  reconcile pick-to-order.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.3
-->
<template>
	<section class="pick-op">
		<h1>{{ t('shillinq', 'Pick for Order') }}</h1>

		<form class="pick-op__form" @submit.prevent="handleConfirm">
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

			<div v-if="error" class="pick-op__error" role="alert">
				{{ error }}
			</div>
			<div v-if="warning" class="pick-op__warning" role="alert">
				{{ warning }}
			</div>
			<div v-if="successMessage" class="pick-op__success" role="status">
				{{ successMessage }}
			</div>

			<div class="pick-op__actions">
				<button type="submit" :disabled="submitting">
					{{ t('shillinq', 'Confirm pick') }}
				</button>
			</div>
		</form>

		<BarcodeScanner
			v-if="scanning"
			@scan="handleScan"
			@cancel="scanning = false" />
	</section>
</template>

<script>
import BarcodeScanner from './BarcodeScanner.vue'
import InventoryLocationField from './InventoryLocationField.vue'
import InventoryQuantityField from './InventoryQuantityField.vue'
import InventorySkuField from './InventorySkuField.vue'
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'
import { claimSubmitSlot } from './claimSubmitSlot.js'

export default {
	name: 'PickOp',
	components: {
		BarcodeScanner,
		InventoryLocationField,
		InventoryQuantityField,
		InventorySkuField,
	},

	data() {
		return {
			location: '',
			sku: '',
			orderLineId: '',
			quantity: null,
			scanning: false,
			submitting: false,
			error: null,
			warning: null,
			successMessage: null,
			lastSubmittedAt: 0,
		}
	},

	computed: {
		store() {
			return useInventoryMobileScannerStore()
		},
	},

	methods: {
		claimSubmitSlot,

		handleScan(value) {
			this.sku = value
			this.scanning = false
		},

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
			this.submitting = true
			try {
				await this.store.submitOperation(this.pickOperation())
				this.successMessage = this.pickDoneMessage()
				this.sku = ''
				this.quantity = null
				this.orderLineId = ''
			} catch (e) {
				this.error = this.failureText(e)
			} finally {
				this.submitting = false
			}
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

<style scoped>
.pick-op {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.pick-op__form label {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.pick-op__error {
	color: var(--color-error);
}

.pick-op__warning {
	color: var(--color-warning);
}

.pick-op__success {
	color: var(--color-success);
}

.pick-op__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
