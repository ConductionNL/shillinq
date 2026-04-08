<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.2 -->
<template>
	<div class="product-index">
		<header class="product-index__header">
			<h2>{{ t('shillinq', 'Products') }}</h2>
			<NcButton type="primary" @click="showForm = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('shillinq', 'New Product') }}
			</NcButton>
		</header>

		<div class="product-index__filters">
			<NcButton
				:type="filterActive === null ? 'primary' : 'secondary'"
				@click="filterActive = null">
				{{ t('shillinq', 'All') }}
			</NcButton>
			<NcButton
				:type="filterActive === true ? 'primary' : 'secondary'"
				@click="filterActive = true">
				<template #icon>
					<CheckCircle :size="20" />
				</template>
				{{ t('shillinq', 'Active') }}
			</NcButton>
			<NcButton
				:type="filterActive === false ? 'primary' : 'secondary'"
				@click="filterActive = false">
				<template #icon>
					<CloseCircle :size="20" />
				</template>
				{{ t('shillinq', 'Inactive') }}
			</NcButton>

			<select
				v-model="filterCategoryId"
				class="product-index__category-filter">
				<option :value="null">
					{{ t('shillinq', 'All categories') }}
				</option>
				<option
					v-for="cat in categories"
					:key="cat.id"
					:value="cat.id">
					{{ cat.name }}
				</option>
			</select>
		</div>

		<NcLoadingIcon v-if="isLoading" :size="44" />

		<table v-else-if="filteredProducts.length" class="product-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'SKU') }}</th>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Unit') }}</th>
					<th>{{ t('shillinq', 'Category') }}</th>
					<th>{{ t('shillinq', 'Purchase Price') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="product in filteredProducts"
					:key="product.id"
					class="product-index__row"
					@click="navigateToDetail(product)">
					<td>{{ product.sku }}</td>
					<td>{{ product.name }}</td>
					<td>{{ product.unit }}</td>
					<td>{{ getCategoryName(product.categoryId) }}</td>
					<td>{{ formatPrice(product.purchasePrice, product.currency) }}</td>
					<td>
						<CheckCircle v-if="product.active" :size="20" class="icon--success" />
						<CloseCircle v-else :size="20" class="icon--muted" />
					</td>
				</tr>
			</tbody>
		</table>

		<NcEmptyContent v-else :name="t('shillinq', 'No products found')">
			<template #icon>
				<PackageVariantClosed :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="showForm = true">
					{{ t('shillinq', 'Create your first product') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ProductForm
			v-if="showForm"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useProductStore } from '../../store/modules/product.js'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'
import ProductForm from './ProductForm.vue'

export default {
	name: 'ProductIndex',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CheckCircle,
		CloseCircle,
		PackageVariantClosed,
		Plus,
		ProductForm,
	},
	data() {
		return {
			showForm: false,
			filterActive: null,
			filterCategoryId: null,
		}
	},
	computed: {
		productStore() {
			return useProductStore()
		},
		productCategoryStore() {
			return useProductCategoryStore()
		},
		products() {
			return this.productStore.objectList || []
		},
		categories() {
			return this.productCategoryStore.objectList || []
		},
		isLoading() {
			return this.productStore.isLoading
		},
		filteredProducts() {
			let result = this.products
			if (this.filterActive !== null) {
				result = result.filter((p) => p.active === this.filterActive)
			}
			if (this.filterCategoryId !== null) {
				result = result.filter((p) => p.categoryId === this.filterCategoryId)
			}
			return result
		},
	},
	mounted() {
		this.productStore.fetchAll()
		this.productCategoryStore.fetchAll()
	},
	methods: {
		navigateToDetail(product) {
			this.$router.push({ name: 'ProductDetail', params: { productId: product.id } })
		},
		getCategoryName(categoryId) {
			if (!categoryId) return '-'
			const category = this.categories.find((c) => c.id === categoryId)
			return category ? category.name : categoryId
		},
		formatPrice(price, currency) {
			if (price == null) return '-'
			return `${currency || 'EUR'} ${Number(price).toFixed(2)}`
		},
		onSaved() {
			this.showForm = false
			this.productStore.fetchAll()
		},
	},
}
</script>

<style scoped>
.product-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.product-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.product-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.product-index__filters {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.product-index__category-filter {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.product-index__table {
	width: 100%;
	border-collapse: collapse;
}

.product-index__table th,
.product-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.product-index__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.product-index__row {
	cursor: pointer;
}

.product-index__row:hover {
	background-color: var(--color-background-hover);
}

.icon--success {
	color: var(--color-success);
}

.icon--muted {
	color: var(--color-text-maxcontrast);
}
</style>
