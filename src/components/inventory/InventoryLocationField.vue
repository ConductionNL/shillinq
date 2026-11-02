<!--
  Inventory Location Field

  The labelled location picker the warehouse operations share: a required
  select over the scanner store's locations, with an empty first option.
  Use it with v-model; the caller passes the translated label, the select's
  accessible name and the empty option's text.

  @spec openspec/specs/inventory-mobile-scanner/spec.md
-->
<template>
	<label>
		<span>{{ label }}</span>
		<select v-model="selected" required :aria-label="selectLabel">
			<option value="">
				{{ placeholder }}
			</option>
			<option v-for="loc in locations" :key="loc.code" :value="loc.code">
				{{ loc.name }} ({{ loc.code }})
			</option>
		</select>
	</label>
</template>

<script>
export default {
	name: 'InventoryLocationField',
	props: {
		modelValue: { type: String, default: '' },
		locations: { type: Array, default: () => [] },
		label: { type: String, required: true },
		selectLabel: { type: String, required: true },
		placeholder: { type: String, required: true },
	},

	emits: ['update:modelValue'],

	computed: {
		selected: {
			/**
			 * The chosen location code.
			 *
			 * @return {string} The code, or an empty string.
			 */
			get() {
				return this.modelValue
			},

			/**
			 * Report a newly chosen location code.
			 *
			 * @param {string} code The chosen code.
			 */
			set(code) {
				this.$emit('update:modelValue', code)
			},
		},
	},
}
</script>
