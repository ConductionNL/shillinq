<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.1 -->
<template>
	<div class="product-category-detail">
		<NcLoadingIcon v-if="isLoading" :size="44" />

		<template v-else-if="category">
			<header class="product-category-detail__header">
				<NcButton type="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('shillinq', 'Back to categories') }}
				</NcButton>
				<h2>{{ category.name }}</h2>
				<NcButton type="secondary" @click="showForm = true">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('shillinq', 'Edit') }}
				</NcButton>
			</header>

			<div class="product-category-detail__tabs">
				<NcButton
					:type="activeTab === 'details' ? 'primary' : 'secondary'"
					@click="activeTab = 'details'">
					{{ t('shillinq', 'Details') }}
				</NcButton>
				<NcButton
					:type="activeTab === 'products' ? 'primary' : 'secondary'"
					@click="activeTab = 'products'">
					{{ t('shillinq', 'Products') }}
				</NcButton>
			</div>

			<!-- Details tab -->
			<div v-if="activeTab === 'details'" class="product-category-detail__content">
				<dl class="product-category-detail__properties">
					<dt>{{ t('shillinq', 'Name') }}</dt>
					<dd>{{ category.name }}</dd>

					<dt>{{ t('shillinq', 'Code') }}</dt>
					<dd>{{ category.code || '-' }}</dd>

					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ category.description || '-' }}</dd>

					<dt>{{ t('shillinq', 'Parent Category') }}</dt>
					<dd>{{ parentCategoryName }}</dd>

					<dt>{{ t('shillinq', 'Active') }}</dt>
					<dd>{{ category.active ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>

					<dt>{{ t('shillinq', 'Sort Order') }}</dt>
					<dd>{{ category.sortOrder ?? '-' }}</dd>
				</dl>
			</div>

			<!-- Products tab -->
			<div v-if="activeTab === 'products'" class="product-category-detail__content">
				<NcLoadingIcon v-if="productsLoading" :size="32" />
				<table v-else-if="filteredProducts.length" class="product-category-detail__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'SKU') }}</th>
							<th>{{ t('shillinq', 'Name') }}</th>
							<th>{{ t('shillinq', 'Unit') }}</th>
							<th>{{ t('shillinq', 'Purchase Price') }}</th>
							<th>{{ t('shillinq', 'Active') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="product in filteredProducts"
							:key="product.id"
							class="product-category-detail__row"
							@click="navigateToProduct(product)">
							<td>{{ product.sku }}</td>
							<td>{{ product.name }}</td>
							<td>{{ product.unit }}</td>
							<td>{{ product.purchasePrice }}</td>
							<td>
								<CheckCircle v-if="product.active" :size="20" class="icon--success" />
								<CloseCircle v-else :size="20" class="icon--muted" />
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-else :name="t('shillinq', 'No products in this category')">
					<template #icon>
						<PackageVariantClosed :size="64" />
					</template>
				</NcEmptyContent>
			</div>
		</template>

		<NcEmptyContent v-else :name="t('shillinq', 'Category not found')">
			<template #icon>
				<FolderOutline :size="64" />
			</template>
		</NcEmptyContent>

		<ProductCategoryForm
			v-if="showForm"
			:category="category"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'
import { useProductStore } from '../../store/modules/product.js'
import ProductCategoryForm from './ProductCategoryForm.vue'

export default {
	name: 'ProductCategoryDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ArrowLeft,
		CheckCircle,
		CloseCircle,
		FolderOutline,
		PackageVariantClosed,
		Pencil,
		ProductCategoryForm,
	},
	data() {
		return {
			activeTab: 'details',
			showForm: false,
		}
	},
	computed: {
		productCategoryStore() {
			return useProductCategoryStore()
		},
		productStore() {
			return useProductStore()
		},
		categoryId() {
			return this.$route.params.categoryId
		},
		category() {
			return this.productCategoryStore.objectItem
		},
		isLoading() {
			return this.productCategoryStore.isLoading
		},
		productsLoading() {
			return this.productStore.isLoading
		},
		filteredProducts() {
			return (this.productStore.objectList || []).filter(
				(product) => product.categoryId === this.categoryId,
			)
		},
		parentCategoryName() {
			if (!this.category?.parentCategoryId) {
				return t('shillinq', 'None')
			}
			const parent = (this.productCategoryStore.objectList || []).find(
				(c) => c.id === this.category.parentCategoryId,
			)
			return parent ? parent.name : this.category.parentCategoryId
		},
	},
	watch: {
		categoryId: {
			handler(id) {
				if (id) {
					this.loadData(id)
				}
			},
			immediate: true,
		},
	},
	methods: {
		async loadData(id) {
			await Promise.all([
				this.productCategoryStore.fetchOne(id),
				this.productCategoryStore.fetchAll(),
				this.productStore.fetchAll(),
			])
		},
		goBack() {
			this.$router.push({ name: 'ProductCategoryIndex' })
		},
		navigateToProduct(product) {
			this.$router.push({ name: 'ProductDetail', params: { productId: product.id } })
		},
		onSaved() {
			this.showForm = false
			this.productCategoryStore.fetchOne(this.categoryId)
		},
	},
}
</script>

<style scoped>
.product-category-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.product-category-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.product-category-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
	flex: 1;
}

.product-category-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.product-category-detail__properties {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px 16px;
}

.product-category-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.product-category-detail__properties dd {
	margin: 0;
}

.product-category-detail__table {
	width: 100%;
	border-collapse: collapse;
}

.product-category-detail__table th,
.product-category-detail__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.product-category-detail__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.product-category-detail__row {
	cursor: pointer;
}

.product-category-detail__row:hover {
	background-color: var(--color-background-hover);
}

.icon--success {
	color: var(--color-success);
}

.icon--muted {
	color: var(--color-text-maxcontrast);
}
</style>
