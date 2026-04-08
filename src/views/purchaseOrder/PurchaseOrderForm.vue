<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-10.1 -->
<template>
	<NcDialog :name="t('shillinq', 'New Purchase Order')"
		size="normal"
		@close="$emit('close')">
		<form class="purchase-order-form" @submit.prevent="submit">
			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'PO Number') }}</label>
				<input type="text"
					:value="form.poNumber || t('shillinq', 'Auto-generated')"
					disabled
					class="purchase-order-form__input purchase-order-form__input--readonly" />
			</div>

			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'Supplier') }} *</label>
				<select v-model="form.supplierProfileId"
					class="purchase-order-form__input"
					required>
					<option value="" disabled>
						{{ t('shillinq', 'Select a supplier...') }}
					</option>
					<option v-for="supplier in suppliers"
						:key="supplier.id"
						:value="supplier.id">
						{{ supplier.name }}
					</option>
				</select>
			</div>

			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'Delivery Address') }} *</label>
				<textarea v-model="form.deliveryAddress"
					class="purchase-order-form__input"
					required
					rows="3"
					:placeholder="t('shillinq', 'Enter delivery address...')" />
			</div>

			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'Expected Delivery Date') }} *</label>
				<input v-model="form.expectedDeliveryDate"
					type="date"
					class="purchase-order-form__input"
					required />
			</div>

			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'Cost Centre') }}</label>
				<select v-model="form.costCentreId"
					class="purchase-order-form__input">
					<option value="">
						{{ t('shillinq', 'None') }}
					</option>
					<option v-for="centre in costCentres"
						:key="centre.id"
						:value="centre.id">
						{{ centre.name }}
					</option>
				</select>
			</div>

			<div class="purchase-order-form__field">
				<label>{{ t('shillinq', 'Notes') }}</label>
				<textarea v-model="form.notes"
					class="purchase-order-form__input"
					rows="3"
					:placeholder="t('shillinq', 'Optional notes...')" />
			</div>
		</form>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || !isValid"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ t('shillinq', 'Create Purchase Order') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { usePurchaseOrderStore } from '../../store/modules/purchaseOrder.js'

export default {
	name: 'PurchaseOrderForm',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	emits: ['close', 'saved'],
	data() {
		return {
			saving: false,
			suppliers: [],
			costCentres: [],
			form: {
				poNumber: '',
				supplierProfileId: '',
				deliveryAddress: '',
				expectedDeliveryDate: '',
				costCentreId: '',
				notes: '',
			},
		}
	},
	computed: {
		purchaseOrderStore() {
			return usePurchaseOrderStore()
		},
		isValid() {
			return (
				this.form.supplierProfileId
				&& this.form.deliveryAddress.trim()
				&& this.form.expectedDeliveryDate
			)
		},
	},
	mounted() {
		this.loadFormData()
	},
	methods: {
		t,
		async loadFormData() {
			try {
				this.suppliers = await this.purchaseOrderStore.fetchSuppliers?.() || []
				this.costCentres = await this.purchaseOrderStore.fetchCostCentres?.() || []
			} catch (error) {
				console.error('Failed to load form data:', error)
			}
		},
		async submit() {
			if (!this.isValid || this.saving) return
			this.saving = true
			try {
				await this.purchaseOrderStore.create(this.form)
				this.$emit('saved')
			} catch (error) {
				console.error('Failed to create purchase order:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.purchase-order-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.purchase-order-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.purchase-order-form__field label {
	font-weight: 600;
	font-size: 14px;
}

.purchase-order-form__input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
}

.purchase-order-form__input--readonly {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}
</style>
