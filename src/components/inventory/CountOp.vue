<!--
  Count Operation

  Implements the counter Count flow per REQ-INVENTORY-004: scan/enter SKU →
  enter physical count → compare to system qty → record variance. The
  reconcile flag is OFF by default — counts are reference points; operators
  approve a stock correction separately so audit trails stay clean.

  @spec openspec/changes/inventory-mobile-scanner/tasks.md#T3.4
-->
<template>
	<InventoryOperationForm
		:title="t('shillinq', 'Inventory Count')"
		:submitLabel="t('shillinq', 'Save count')"
		v-bind="formState"
		fallbackToManual
		@submit="handleConfirm"
		@scan="handleScan"
		@cancel="scanning = false">
		<InventoryLocationField
			v-model="location"
			:locations="store.locations"
			:label="t('shillinq', 'Location')"
			:selectLabel="t('shillinq', 'Count location')"
			:placeholder="t('shillinq', 'Select a location')" />

		<InventorySkuField v-model="sku" @scan="scanning = true" />

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

		<div v-if="systemQuantity !== null" class="count-op__variance" role="status">
			{{ t('shillinq', 'System qty: {sys}', { sys: systemQuantity }) }}
			—
			{{ t('shillinq', 'Variance: {variance}', { variance: variance }) }}
		</div>

		<label>
			<input v-model="reconcile" type="checkbox" />
			{{
				t('shillinq', 'Update InventoryStock to physical count (reconcile)')
			}}
		</label>
	</InventoryOperationForm>
</template>

<script>
import InventoryLocationField from './InventoryLocationField.vue'
import InventoryOperationForm from './InventoryOperationForm.vue'
import InventorySkuField from './InventorySkuField.vue'
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { inventoryOperation } from './inventoryOperation.js'

export default {
	name: 'CountOp',
	components: {
		InventoryLocationField,
		InventoryOperationForm,
		InventorySkuField,
	},

	data() {
		return {
			...inventoryOperation.state(),
			location: '',
			physicalQuantity: null,
			systemQuantity: null,
			reconcile: false,
		}
	},

	computed: {
		...inventoryOperation.computed,

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
		...inventoryOperation.methods,

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
			await this.queueOperation(this.countOperation(), {
				queued: () => {
					this.successMessage = this.t(
						'shillinq',
						'Count recorded: variance {variance} (pending sync)',
						{ variance: this.variance },
					)
					this.clearCount()
				},
				failed: (e) => this.countFailureText(e),
			})
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
.count-op__variance {
	color: var(--color-text-maxcontrast);
}
</style>
