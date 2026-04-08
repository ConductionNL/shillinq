<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.2 -->
<template>
	<NcDialog
		:name="isEdit ? t('shillinq', 'Edit Product') : t('shillinq', 'New Product')"
		size="normal"
		@closing="$emit('close')">
		<form class="product-form" @submit.prevent="save">
			<div class="product-form__field">
				<label for="product-sku">{{ t('shillinq', 'SKU') }} *</label>
				<input
					id="product-sku"
					v-model="form.sku"
					type="text"
					required
					:placeholder="t('shillinq', 'Unique product SKU')">
				<p class="product-form__hint">
					{{ t('shillinq', 'SKU must be unique across all products.') }}
				</p>
			</div>

			<div class="product-form__field">
				<label for="product-name">{{ t('shillinq', 'Name') }} *</label>
				<input
					id="product-name"
					v-model="form.name"
					type="text"
					required
					:placeholder="t('shillinq', 'Product name')">
			</div>

			<div class="product-form__field">
				<label for="product-description">{{ t('shillinq', 'Description') }}</label>
				<textarea
					id="product-description"
					v-model="form.description"
					rows="3"
					:placeholder="t('shillinq', 'Product description')" />
			</div>

			<div class="product-form__field">
				<label for="product-unit">{{ t('shillinq', 'Unit') }}</label>
				<input
					id="product-unit"
					v-model="form.unit"
					type="text"
					:placeholder="t('shillinq', 'e.g. pcs, kg, liter')">
			</div>

			<div class="product-form__field">
				<label for="product-category">{{ t('shillinq', 'Category') }}</label>
				<select id="product-category" v-model="form.categoryId">
					<option :value="null">
						{{ t('shillinq', 'No category') }}
					</option>
					<option
						v-for="cat in categories"
						:key="cat.id"
						:value="cat.id">
						{{ cat.name }}
					</option>
				</select>
			</div>

			<div class="product-form__row">
				<div class="product-form__field">
					<label for="product-price">{{ t('shillinq', 'Purchase Price') }}</label>
					<input
						id="product-price"
						v-model.number="form.purchasePrice"
						type="number"
						step="0.01"
						min="0"
						:placeholder="t('shillinq', '0.00')">
				</div>

				<div class="product-form__field">
					<label for="product-currency">{{ t('shillinq', 'Currency') }}</label>
					<input
						id="product-currency"
						v-model="form.currency"
						type="text"
						maxlength="3"
						:placeholder="t('shillinq', 'EUR')">
				</div>
			</div>

			<div class="product-form__row">
				<div class="product-form__field">
					<label for="product-tax-rate">{{ t('shillinq', 'Tax Rate (%)') }}</label>
					<input
						id="product-tax-rate"
						v-model.number="form.taxRate"
						type="number"
						step="0.01"
						min="0"
						max="100"
						:placeholder="t('shillinq', '0')">
				</div>

				<div class="product-form__field">
					<label for="product-lead-time">{{ t('shillinq', 'Lead Time (Days)') }}</label>
					<input
						id="product-lead-time"
						v-model.number="form.leadTimeDays"
						type="number"
						min="0"
						:placeholder="t('shillinq', '0')">
				</div>
			</div>

			<div class="product-form__field product-form__field--inline">
				<input
					id="product-active"
					v-model="form.active"
					type="checkbox">
				<label for="product-active">{{ t('shillinq', 'Active') }}</label>
			</div>

			<div class="product-form__field">
				<label for="product-notes">{{ t('shillinq', 'Notes') }}</label>
				<textarea
					id="product-notes"
					v-model="form.notes"
					rows="2"
					:placeholder="t('shillinq', 'Additional notes')" />
			</div>

			<div class="product-form__actions">
				<NcButton type="secondary" @click="$emit('close')">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="saving || !form.sku || !form.name">
					{{ saving ? t('shillinq', 'Saving...') : t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useProductStore } from '../../store/modules/product.js'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'

export default {
	name: 'ProductForm',
	components: {
		NcButton,
		NcDialog,
	},
	props: {
		product: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'saved'],
	data() {
		return {
			form: {
				sku: '',
				name: '',
				description: '',
				unit: '',
				active: true,
				categoryId: null,
				purchasePrice: null,
				currency: 'EUR',
				taxRate: null,
				leadTimeDays: null,
				notes: '',
			},
			saving: false,
		}
	},
	computed: {
		productStore() {
			return useProductStore()
		},
		productCategoryStore() {
			return useProductCategoryStore()
		},
		isEdit() {
			return !!this.product?.id
		},
		categories() {
			return this.productCategoryStore.objectList || []
		},
	},
	created() {
		if (this.product) {
			this.form = {
				sku: this.product.sku || '',
				name: this.product.name || '',
				description: this.product.description || '',
				unit: this.product.unit || '',
				active: this.product.active !== false,
				categoryId: this.product.categoryId || null,
				purchasePrice: this.product.purchasePrice ?? null,
				currency: this.product.currency || 'EUR',
				taxRate: this.product.taxRate ?? null,
				leadTimeDays: this.product.leadTimeDays ?? null,
				notes: this.product.notes || '',
			}
		}
		this.productCategoryStore.fetchAll()
	},
	methods: {
		async save() {
			this.saving = true
			try {
				const data = { ...this.form }
				if (this.isEdit) {
					await this.productStore.save({ ...data, id: this.product.id })
				} else {
					await this.productStore.save(data)
				}
				this.$emit('saved')
			} catch (error) {
				console.error('Failed to save product:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.product-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.product-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
}

.product-form__field label {
	font-weight: 600;
}

.product-form__field--inline {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.product-form__row {
	display: flex;
	gap: 12px;
}

.product-form__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.product-form__field input[type="text"],
.product-form__field input[type="number"],
.product-form__field textarea,
.product-form__field select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.product-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
