<!--
  Receive Operation

  Implements the warehouse-manager-only Receive flow per REQ-INVENTORY-001:
  scan barcode → confirm SKU + qty + location → optimistic local update +
  queued pendingOp → background sync. Permission gate is enforced server-side
  per REQ-PERM-001; the UI mirrors the role list for early feedback.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.1
-->
<template>
	<section class="receive-op">
		<h1>{{ t('shillinq', 'Receive Goods') }}</h1>

		<form class="receive-op__form" @submit.prevent="handleConfirm">
			<label>
				<span>{{ t('shillinq', 'Location') }}</span>
				<select
					v-model="location"
					required
					:aria-label="t('shillinq', 'Receiving location')">
					<option value="">
						{{ t('shillinq', 'Select a location') }}
					</option>
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
				<div class="receive-op__sku-row">
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
				<span>{{ t('shillinq', 'Quantity') }}</span>
				<input
					v-model.number="quantity"
					type="number"
					min="0.01"
					step="0.01"
					required
					:aria-label="t('shillinq', 'Quantity received')" />
			</label>

			<div v-if="error" class="receive-op__error" role="alert">
				{{ error }}
			</div>
			<div v-if="successMessage" class="receive-op__success" role="status">
				{{ successMessage }}
			</div>

			<div class="receive-op__actions">
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
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'
import { claimSubmitSlot } from './claimSubmitSlot.js'

export default {
	name: 'ReceiveOp',
	components: { BarcodeScanner },
	data() {
		return {
			location: '',
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
	},

	methods: {
		claimSubmitSlot,

		handleScan(value) {
			this.sku = value
			this.scanning = false
		},

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
			this.submitting = true
			try {
				const result = await this.store.submitOperation({
					type: 'receive',
					sku: this.sku,
					location: this.location,
					quantity: this.quantity,
				})
				this.successMessage = this.t(
					'shillinq',
					'Received {qty} units (pending sync)',
					{ qty: this.quantity },
				)
				this.sku = ''
				this.quantity = null
				this.lastTransactionId = result.transactionId
			} catch (e) {
				this.error = this.receiveFailureText(e)
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
		receiveFailureText(e) {
			return e && e.message
				? e.message
				: this.t('shillinq', 'Could not record receipt.')
		},
	},
}
</script>

<style scoped>
.receive-op {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.receive-op__form label {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.receive-op__sku-row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.receive-op__sku-row input {
	flex: 1;
}

.receive-op__error {
	color: var(--color-error);
}

.receive-op__success {
	color: var(--color-success);
}

.receive-op__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
