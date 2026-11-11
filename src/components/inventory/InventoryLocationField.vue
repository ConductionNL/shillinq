<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Inventory Location Field

  The labelled location picker the warehouse operations share: a required
  select over the locations of the scanner store, with an empty first option.
  Use it with v-model; the caller passes the translated label, the accessible
  name of the select and the text of the empty option.

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
			get: function () {
				return this.modelValue
			},

			/**
			 * Report a newly chosen location code.
			 *
			 * @param {string} code The chosen code.
			 */
			set: function (code) {
				this.$emit('update:modelValue', code)
			},
		},
	},
}
</script>
