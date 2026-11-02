<!--
  Count Operation

  Implements the counter Count flow per REQ-INVENTORY-004: scan/enter SKU →
  enter physical count → compare to system qty → record variance. The
  reconcile flag is OFF by default — counts are reference points; operators
  approve a stock correction separately so audit trails stay clean.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.4
-->
<template>
	<section class="count-op">
		<h1>{{ t('shillinq', 'Inventory Count') }}</h1>

		<form class="count-op__form" @submit.prevent="handleConfirm">
			<label>
				<span>{{ t('shillinq', 'Location') }}</span>
				<select
					v-model="location"
					required
					:aria-label="t('shillinq', 'Count location')">
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
				<div class="count-op__sku-row">
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
				<span>{{ t('shillinq', 'Physical count') }}</span>
				<input
					v-model.number="physicalQuantity"
					type="number"
					min="0"
					step="0.01"
					required
					:aria-label="t('shillinq', 'Physical count')"
					@input="recomputeVariance" />
			</label>

			<div
				v-if="systemQuantity !== null"
				class="count-op__variance"
				role="status">
				{{ t('shillinq', 'System qty: {sys}', { sys: systemQuantity }) }}
				—
				{{ t('shillinq', 'Variance: {variance}', { variance: variance }) }}
			</div>

			<label>
				<input v-model="reconcile" type="checkbox" />
				{{
					t(
						'shillinq',
						'Update InventoryStock to physical count (reconcile)',
					)
				}}
			</label>

			<div v-if="error" class="count-op__error" role="alert">
				{{ error }}
			</div>
			<div v-if="successMessage" class="count-op__success" role="status">
				{{ successMessage }}
			</div>

			<div class="count-op__actions">
				<button type="submit" :disabled="submitting">
					{{ t('shillinq', 'Save count') }}
				</button>
			</div>
		</form>

		<BarcodeScanner
			v-if="scanning"
			fallbackToManual
			@scan="handleScan"
			@cancel="scanning = false" />
	</section>
</template>

<script>
import BarcodeScanner from './BarcodeScanner.vue'
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'
import { claimSubmitSlot } from './claimSubmitSlot.js'

export default {
	name: 'CountOp',
	components: { BarcodeScanner },
	data() {
		return {
			location: '',
			sku: '',
			physicalQuantity: null,
			systemQuantity: null,
			reconcile: false,
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

		variance() {
			if (this.systemQuantity === null || this.physicalQuantity === null) {
				return null
			}
			return Number(this.physicalQuantity) - Number(this.systemQuantity)
		},
	},

	watch: {
		async sku() {
			await this.recomputeVariance()
		},

		async location() {
			await this.recomputeVariance()
		},
	},

	methods: {
		claimSubmitSlot,

		handleScan(value) {
			this.sku = value
			this.scanning = false
		},

		async recomputeVariance() {
			if (!this.store.db || !this.sku || !this.location) {
				this.systemQuantity = null
				return
			}
			try {
				this.systemQuantity = await readStockQuantity(
					this.store.db,
					this.sku,
					this.location,
				)
			} catch (e) {
				this.systemQuantity = 0
			}
		},

		/**
		 * Check the form and the submit guard, then record the count.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async handleConfirm() {
			this.error = null
			this.successMessage = null

			if (!this.countFieldsPresent() || !this.claimSubmitSlot()) {
				return
			}
			await this.recordCount()
		},

		/**
		 * Whether the location, the SKU or a non-negative count is missing.
		 *
		 * @return {boolean} True when the form is incomplete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		countFieldsMissing() {
			return (
				!this.location
				|| !this.sku
				|| this.physicalQuantity === null
				|| this.physicalQuantity < 0
			)
		},

		/**
		 * Set the error when the location, the SKU or a non-negative count is
		 * missing.
		 *
		 * @return {boolean} Whether the form is complete.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		countFieldsPresent() {
			if (!this.countFieldsMissing()) {
				return true
			}
			this.error = this.t(
				'shillinq',
				'Location, SKU and a non-negative physical count are required.',
			)
			return false
		},

		/**
		 * Queue the count as a pending operation and report the outcome on
		 * the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async recordCount() {
			this.submitting = true
			try {
				await this.store.submitOperation(this.countOperation())
				this.successMessage = this.t(
					'shillinq',
					'Count recorded: variance {variance} (pending sync)',
					{ variance: this.variance },
				)
				this.clearCount()
			} catch (e) {
				this.error = this.countFailureText(e)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * The pending count operation for the current form.
		 *
		 * @return {object} The operation the scanner store submits.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		countOperation() {
			return {
				type: 'count',
				sku: this.sku,
				location: this.location,
				physicalQuantity: this.physicalQuantity,
				reconcile: this.reconcile,
			}
		},

		/**
		 * Empty the form for the next count; the location stays.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		clearCount() {
			this.sku = ''
			this.physicalQuantity = null
			this.systemQuantity = null
			this.reconcile = false
		},

		/**
		 * The message of a failed submit, or a generic one.
		 *
		 * @param {Error|object|null} e What the submit threw.
		 * @return {string} The error to show.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		countFailureText(e) {
			return e && e.message
				? e.message
				: this.t('shillinq', 'Could not record count.')
		},
	},
}
</script>

<style scoped>
.count-op {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.count-op__form label {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.count-op__sku-row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.count-op__sku-row input {
	flex: 1;
}

.count-op__variance {
	color: var(--color-text-maxcontrast);
}

.count-op__error {
	color: var(--color-error);
}

.count-op__success {
	color: var(--color-success);
}

.count-op__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
