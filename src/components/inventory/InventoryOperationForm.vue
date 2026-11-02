<!--
  Inventory Operation Form

  The frame the four warehouse operations share: the heading, the form whose
  fields the caller puts in the default slot, the error, warning and success
  lines, the submit button and the barcode scanner. It emits submit, scan
  (with the scanned value) and cancel; the caller keeps the state.

  @spec openspec/specs/inventory-mobile-scanner/spec.md
-->
<template>
	<section class="inventory-operation">
		<h1>{{ title }}</h1>

		<form class="inventory-operation__form" @submit.prevent="$emit('submit')">
			<slot />

			<div v-if="error" class="inventory-operation__error" role="alert">
				{{ error }}
			</div>
			<div v-if="warning" class="inventory-operation__warning" role="alert">
				{{ warning }}
			</div>
			<div
				v-if="successMessage"
				class="inventory-operation__success"
				role="status">
				{{ successMessage }}
			</div>

			<div class="inventory-operation__actions">
				<button type="submit" :disabled="submitting">
					{{ submitLabel }}
				</button>
			</div>
		</form>

		<BarcodeScanner
			v-if="scanning"
			:fallbackToManual="fallbackToManual"
			@scan="$emit('scan', $event)"
			@cancel="$emit('cancel')" />
	</section>
</template>

<script>
import BarcodeScanner from './BarcodeScanner.vue'

export default {
	name: 'InventoryOperationForm',
	components: { BarcodeScanner },
	props: {
		title: { type: String, required: true },
		submitLabel: { type: String, required: true },
		error: { type: String, default: null },
		warning: { type: String, default: null },
		successMessage: { type: String, default: null },
		submitting: { type: Boolean, default: false },
		scanning: { type: Boolean, default: false },
		fallbackToManual: { type: Boolean, default: false },
	},

	emits: ['submit', 'scan', 'cancel'],
}
</script>

<style scoped>
.inventory-operation {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px);
}

.inventory-operation__form :deep(label) {
	display: flex;
	flex-direction: column;
	margin-bottom: var(--default-grid-baseline, 4px);
}

.inventory-operation__error {
	color: var(--color-error);
}

.inventory-operation__warning {
	color: var(--color-warning);
}

.inventory-operation__success {
	color: var(--color-success);
}

.inventory-operation__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
