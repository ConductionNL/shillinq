<!--
  Inventory Quantity Field

  The positive quantity input the receive, transfer and pick operations
  share. Use it with v-model; the value is cast to a number as it is typed,
  and the caller passes the accessible name of the input.

  @spec openspec/specs/inventory-mobile-scanner/spec.md
-->
<template>
	<label>
		<span>{{ t('shillinq', 'Quantity') }}</span>
		<input
			v-model.number="value"
			type="number"
			min="0.01"
			step="0.01"
			required
			:aria-label="inputLabel" />
	</label>
</template>

<script>
export default {
	name: 'InventoryQuantityField',
	props: {
		modelValue: { type: [Number, String], default: null },
		inputLabel: { type: String, required: true },
	},

	emits: ['update:modelValue'],

	computed: {
		value: {
			/**
			 * The entered quantity.
			 *
			 * @return {number|string|null} The quantity as typed.
			 */
			get: function () {
				return this.modelValue
			},

			/**
			 * Report a newly entered quantity.
			 *
			 * @param {number|string} quantity The quantity, a number when it parses.
			 */
			set: function (quantity) {
				this.$emit('update:modelValue', quantity)
			},
		},
	},
}
</script>
