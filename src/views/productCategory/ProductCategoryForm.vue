<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.1 -->
<template>
	<NcDialog
		:name="isEdit ? t('shillinq', 'Edit Category') : t('shillinq', 'New Category')"
		size="normal"
		@closing="$emit('close')">
		<form class="product-category-form" @submit.prevent="save">
			<div class="product-category-form__field">
				<label for="category-name">{{ t('shillinq', 'Name') }} *</label>
				<input
					id="category-name"
					v-model="form.name"
					type="text"
					required
					:placeholder="t('shillinq', 'Category name')">
			</div>

			<div class="product-category-form__field">
				<label for="category-code">{{ t('shillinq', 'Code') }}</label>
				<input
					id="category-code"
					v-model="form.code"
					type="text"
					:placeholder="t('shillinq', 'Category code')">
			</div>

			<div class="product-category-form__field">
				<label for="category-description">{{ t('shillinq', 'Description') }}</label>
				<textarea
					id="category-description"
					v-model="form.description"
					rows="3"
					:placeholder="t('shillinq', 'Category description')" />
			</div>

			<div class="product-category-form__field">
				<label for="category-parent">{{ t('shillinq', 'Parent Category') }}</label>
				<select id="category-parent" v-model="form.parentCategoryId">
					<option :value="null">
						{{ t('shillinq', 'None (top-level)') }}
					</option>
					<option
						v-for="cat in availableParents"
						:key="cat.id"
						:value="cat.id">
						{{ cat.name }}
					</option>
				</select>
			</div>

			<div class="product-category-form__field product-category-form__field--inline">
				<input
					id="category-active"
					v-model="form.active"
					type="checkbox">
				<label for="category-active">{{ t('shillinq', 'Active') }}</label>
			</div>

			<div class="product-category-form__field">
				<label for="category-sort-order">{{ t('shillinq', 'Sort Order') }}</label>
				<input
					id="category-sort-order"
					v-model.number="form.sortOrder"
					type="number"
					min="0"
					:placeholder="t('shillinq', '0')">
			</div>

			<div class="product-category-form__actions">
				<NcButton type="secondary" @click="$emit('close')">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="saving || !form.name">
					{{ saving ? t('shillinq', 'Saving...') : t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'

export default {
	name: 'ProductCategoryForm',
	components: {
		NcButton,
		NcDialog,
	},
	props: {
		category: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'saved'],
	data() {
		return {
			form: {
				name: '',
				code: '',
				description: '',
				parentCategoryId: null,
				active: true,
				sortOrder: 0,
			},
			saving: false,
		}
	},
	computed: {
		productCategoryStore() {
			return useProductCategoryStore()
		},
		isEdit() {
			return !!this.category?.id
		},
		availableParents() {
			const categories = this.productCategoryStore.objectList || []
			if (this.isEdit) {
				return categories.filter((c) => c.id !== this.category.id)
			}
			return categories
		},
	},
	created() {
		if (this.category) {
			this.form = {
				name: this.category.name || '',
				code: this.category.code || '',
				description: this.category.description || '',
				parentCategoryId: this.category.parentCategoryId || null,
				active: this.category.active !== false,
				sortOrder: this.category.sortOrder || 0,
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
					await this.productCategoryStore.save({ ...data, id: this.category.id })
				} else {
					await this.productCategoryStore.save(data)
				}
				this.$emit('saved')
			} catch (error) {
				console.error('Failed to save category:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.product-category-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.product-category-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.product-category-form__field label {
	font-weight: 600;
}

.product-category-form__field--inline {
	flex-direction: row;
	align-items: center;
	gap: 8px;
}

.product-category-form__field input[type="text"],
.product-category-form__field input[type="number"],
.product-category-form__field textarea,
.product-category-form__field select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.product-category-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
