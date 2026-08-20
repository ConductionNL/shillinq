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
			<label>
				<span>{{ t('shillinq', 'Location') }}</span>
				<select
					v-model="location"
					required
					:aria-label="t('shillinq', 'Pick location')">
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
				<div class="pick-op__sku-row">
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
				<span>{{ t('shillinq', 'Order line id (optional)') }}</span>
				<input
					v-model="orderLineId"
					type="text"
					:aria-label="t('shillinq', 'Order line id')" />
			</label>

			<label>
				<span>{{ t('shillinq', 'Quantity') }}</span>
				<input
					v-model.number="quantity"
					type="number"
					min="0.01"
					step="0.01"
					required
					:aria-label="t('shillinq', 'Quantity to pick')" />
			</label>

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
import { readStockQuantity } from '../../composables/useInventoryDb.js'
import { useInventoryMobileScannerStore } from '../../store/modules/inventoryMobileScanner.js'

export default {
	name: 'PickOp',
	components: { BarcodeScanner },
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
		handleScan(value) {
			this.sku = value
			this.scanning = false
		},

		async handleConfirm() {
			this.error = null
			this.warning = null
			this.successMessage = null

			if (
				!this.location
				|| !this.sku
				|| !this.quantity
				|| this.quantity <= 0
			) {
				this.error = this.t(
					'shillinq',
					'Location, SKU and a positive quantity are required.',
				)
				return
			}

			try {
				if (this.store.db) {
					const onHand = await readStockQuantity(
						this.store.db,
						this.sku,
						this.location,
					)
					if (onHand < this.quantity) {
						this.warning = this.t(
							'shillinq',
							'Only {onHand} units available; reduce quantity or cancel.',
							{ onHand },
						)
						return
					}
				}
			} catch (e) {
				// Best-effort guard.
			}

			const now = Date.now()
			if (now - this.lastSubmittedAt < 5000) {
				this.error = this.t(
					'shillinq',
					'Already submitted; waiting for server ACK.',
				)
				return
			}
			this.lastSubmittedAt = now

			this.submitting = true
			try {
				await this.store.submitOperation({
					type: 'pick',
					sku: this.sku,
					location: this.location,
					quantity: this.quantity,
					orderLineId: this.orderLineId || null,
				})
				this.successMessage = this.t(
					'shillinq',
					'Picked {qty} × {sku} (pending sync)',
					{ qty: this.quantity, sku: this.sku },
				)
				this.sku = ''
				this.quantity = null
				this.orderLineId = ''
			} catch (e) {
				this.error =
					e && e.message
						? e.message
						: this.t('shillinq', 'Could not record pick.')
			} finally {
				this.submitting = false
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

.pick-op__sku-row {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.pick-op__sku-row input {
	flex: 1;
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
