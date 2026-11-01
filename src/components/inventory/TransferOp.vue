<!--
  Transfer Operation

  Implements the inventory_operator-only Transfer flow per REQ-INVENTORY-002:
  select source → scan → select destination → confirm. Both InventoryStock
  mutations are dispatched in a single pendingOp so the server applies them
  atomically.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.2
-->
<template>
	<section class="transfer-op">
		<h1>{{ t('shillinq', 'Transfer Inventory') }}</h1>

		<form class="transfer-op__form" @submit.prevent="handleConfirm">
			<label>
				<span>{{ t('shillinq', 'From location') }}</span>
				<select
					v-model="fromLocation"
					required
					:aria-label="t('shillinq', 'Source location')">
					<option value="">{{ t('shillinq', 'Select source') }}</option>
					<option
						v-for="loc in store.locations"
						:key="loc.code"
						:value="loc.code">
						{{ loc.name }} ({{ loc.code }})
					</option>
				</select>
			</label>

			<label>
				<span>{{ t('shillinq', 'SKU / barcode') }}</span>
				<div class="transfer-op__sku-row">
					<input
						v-model="sku"
						type="text"
						required
						:placeholder="t('shillinq', 'Tap scan or type SKU')"
						:aria-label="t('shillinq', 'Stock keeping unit')" />
					<button type="button" @click="scanning = true">
						{{ t('shillinq', 'Scan') }}
					</button>
				</div>
			</label>

			<label>
				<span>{{ t('shillinq', 'To location') }}</span>
				<select
					v-model="toLocation"
					required
					:aria-label="t('shillinq', 'Destination location')">
					<option value="">
						{{ t('shillinq', 'Select destination') }}
					</option>
					<option
						v-for="loc in availableDestinations"
						:key="loc.code"
						:value="loc.code">
						{{ loc.name }} ({{ loc.code }})
					</option>
				</select>
			</label>

			<label>
				<span>{{ t('shillinq', 'Quantity') }}</span>
				<input
					v-model.number="quantity"
					type="number"
					min="0.01"
					step="0.01"
					required
					:aria-label="t('shillinq', 'Quantity to transfer')" />
			</label>

			<div v-if="error" class="transfer-op__error" role="alert">
				{{ error }}
			</div>
			<div v-if="successMessage" class="transfer-op__success" role="status">
				{{ successMessage }}
			</div>

			<div class="transfer-op__actions">
				<button type="submit" :disabled="submitting">
					{{ t('shillinq', 'Confirm') }}
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
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'TransferOp',
	components: { BarcodeScanner },
	data() {
		return {
			fromLocation: '',
			toLocation: '',
			sku: '',
			quantity: null,
			scanning: false,
			submitting: false,
			error: null,
			successMessage: null,
			lastSubmittedAt: 0,
		}
	},

	computed: {
		store() {
			return useInventoryMobileScannerStore()
		},

		availableDestinations() {
			return this.store.locations.filter(
				(loc) => loc.code !== this.fromLocation,
			)
		},
	},

	methods: {
		handleScan(value) {
			this.sku = value
			this.scanning = false
		},

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
		 * Refuse a second submit within five seconds, otherwise note the time.
		 *
		 * @return {boolean} Whether this submit may go ahead.
		 */
		claimSubmitSlot() {
			const now = Date.now()
			if (now - this.lastSubmittedAt < 5000) {
				this.error = this.t(
					'shillinq',
					'Already submitted; waiting for server ACK.',
				)
				return false
			}
			this.lastSubmittedAt = now
			return true
		},

		/**
		 * Dispatch both stock mutations as one pending operation and report
		 * the outcome on the form.
		 *
		 * @return {Promise<void>}
		 */
		async recordTransfer() {
			this.submitting = true
			try {
				await this.store.submitOperation(this.transferOperation())
				this.successMessage = this.transferDoneMessage()
				this.sku = ''
				this.quantity = null
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

<style scoped>
.transfer-op {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.transfer-op__form label {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.transfer-op__sku-row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.transfer-op__sku-row input {
	flex: 1;
}

.transfer-op__error {
	color: var(--color-error);
}

.transfer-op__success {
	color: var(--color-success);
}

.transfer-op__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
