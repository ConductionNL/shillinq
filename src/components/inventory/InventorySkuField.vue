<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Inventory SKU Field

  The SKU or barcode input the warehouse operations share, with a Scan
  button next to it. Use it with v-model; a click on Scan emits scan, and the
  caller opens the barcode scanner.

  @spec openspec/specs/inventory-mobile-scanner/spec.md
-->
<template>
	<label>
		<span>{{ t('shillinq', 'SKU / barcode') }}</span>
		<div class="inventory-sku-field__row">
			<input
				v-model="value"
				type="text"
				required
				:placeholder="t('shillinq', 'Tap scan or type SKU')"
				:aria-label="t('shillinq', 'Stock keeping unit')" />
			<button type="button" @click="requestScan">
				{{ t('shillinq', 'Scan') }}
			</button>
		</div>
	</label>
</template>

<script>
export default {
	name: 'InventorySkuField',
	props: {
		modelValue: { type: String, default: '' },
	},

	emits: ['update:modelValue', 'scan'],

	computed: {
		value: {
			/**
			 * The entered SKU.
			 *
			 * @return {string} The SKU, or an empty string.
			 */
			get: function () {
				return this.modelValue
			},

			/**
			 * Report a newly entered SKU.
			 *
			 * @param {string} sku The entered SKU.
			 */
			set: function (sku) {
				this.$emit('update:modelValue', sku)
			},
		},
	},

	methods: {
		/**
		 * Ask the caller to open the barcode scanner.
		 */
		requestScan() {
			this.$emit('scan')
		},
	},
}
</script>

<style scoped>
.inventory-sku-field__row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.inventory-sku-field__row input {
	flex: 1;
}
</style>
