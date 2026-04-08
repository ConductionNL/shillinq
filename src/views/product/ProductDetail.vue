<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.2 -->
<template>
	<div class="product-detail">
		<NcLoadingIcon v-if="isLoading" :size="44" />

		<template v-else-if="product">
			<header class="product-detail__header">
				<NcButton type="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('shillinq', 'Back to products') }}
				</NcButton>
				<h2>{{ product.name }}</h2>
				<NcButton type="secondary" @click="showForm = true">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('shillinq', 'Edit') }}
				</NcButton>
			</header>

			<div class="product-detail__tabs">
				<NcButton
					:type="activeTab === 'details' ? 'primary' : 'secondary'"
					@click="activeTab = 'details'">
					{{ t('shillinq', 'Details') }}
				</NcButton>
				<NcButton
					:type="activeTab === 'catalogs' ? 'primary' : 'secondary'"
					@click="activeTab = 'catalogs'">
					{{ t('shillinq', 'Catalogs') }}
				</NcButton>
				<NcButton
					:type="activeTab === 'orders' ? 'primary' : 'secondary'"
					@click="activeTab = 'orders'">
					{{ t('shillinq', 'Orders') }}
				</NcButton>
			</div>

			<!-- Details tab -->
			<div v-if="activeTab === 'details'" class="product-detail__content">
				<dl class="product-detail__properties">
					<dt>{{ t('shillinq', 'SKU') }}</dt>
					<dd>{{ product.sku }}</dd>

					<dt>{{ t('shillinq', 'Name') }}</dt>
					<dd>{{ product.name }}</dd>

					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ product.description || '-' }}</dd>

					<dt>{{ t('shillinq', 'Unit') }}</dt>
					<dd>{{ product.unit || '-' }}</dd>

					<dt>{{ t('shillinq', 'Category') }}</dt>
					<dd>{{ categoryName }}</dd>

					<dt>{{ t('shillinq', 'Purchase Price') }}</dt>
					<dd>{{ formatPrice(product.purchasePrice, product.currency) }}</dd>

					<dt>{{ t('shillinq', 'Currency') }}</dt>
					<dd>{{ product.currency || 'EUR' }}</dd>

					<dt>{{ t('shillinq', 'Tax Rate') }}</dt>
					<dd>{{ product.taxRate != null ? product.taxRate + '%' : '-' }}</dd>

					<dt>{{ t('shillinq', 'Lead Time (Days)') }}</dt>
					<dd>{{ product.leadTimeDays ?? '-' }}</dd>

					<dt>{{ t('shillinq', 'Active') }}</dt>
					<dd>{{ product.active ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>

					<dt>{{ t('shillinq', 'Notes') }}</dt>
					<dd>{{ product.notes || '-' }}</dd>
				</dl>
			</div>

			<!-- Catalogs tab -->
			<div v-if="activeTab === 'catalogs'" class="product-detail__content">
				<NcLoadingIcon v-if="catalogItemsLoading" :size="32" />
				<table v-else-if="filteredCatalogItems.length" class="product-detail__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Catalog') }}</th>
							<th>{{ t('shillinq', 'Price') }}</th>
							<th>{{ t('shillinq', 'Active') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in filteredCatalogItems" :key="item.id">
							<td>{{ item.catalogId }}</td>
							<td>{{ formatPrice(item.price, item.currency) }}</td>
							<td>
								<CheckCircle v-if="item.active" :size="20" class="icon--success" />
								<CloseCircle v-else :size="20" class="icon--muted" />
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-else :name="t('shillinq', 'Not listed in any catalog')">
					<template #icon>
						<BookOpenPageVariantOutline :size="64" />
					</template>
				</NcEmptyContent>
			</div>

			<!-- Orders tab -->
			<div v-if="activeTab === 'orders'" class="product-detail__content">
				<NcLoadingIcon v-if="purchaseOrdersLoading" :size="32" />
				<table v-else-if="filteredOrderLines.length" class="product-detail__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Order') }}</th>
							<th>{{ t('shillinq', 'Quantity') }}</th>
							<th>{{ t('shillinq', 'Unit Price') }}</th>
							<th>{{ t('shillinq', 'Total') }}</th>
							<th>{{ t('shillinq', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="line in filteredOrderLines" :key="line.id">
							<td>{{ line.purchaseOrderId }}</td>
							<td>{{ line.quantity }}</td>
							<td>{{ formatPrice(line.unitPrice, line.currency) }}</td>
							<td>{{ formatPrice(line.totalPrice, line.currency) }}</td>
							<td>{{ line.status || '-' }}</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-else :name="t('shillinq', 'No orders for this product')">
					<template #icon>
						<ClipboardTextOutline :size="64" />
					</template>
				</NcEmptyContent>
			</div>
		</template>

		<NcEmptyContent v-else :name="t('shillinq', 'Product not found')">
			<template #icon>
				<PackageVariantClosed :size="64" />
			</template>
		</NcEmptyContent>

		<ProductForm
			v-if="showForm"
			:product="product"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import BookOpenPageVariantOutline from 'vue-material-design-icons/BookOpenPageVariantOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { useProductStore } from '../../store/modules/product.js'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'
import { useCatalogItemStore } from '../../store/modules/catalogItem.js'
import { usePurchaseOrderStore } from '../../store/modules/purchaseOrder.js'
import ProductForm from './ProductForm.vue'

export default {
	name: 'ProductDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ArrowLeft,
		BookOpenPageVariantOutline,
		CheckCircle,
		ClipboardTextOutline,
		CloseCircle,
		PackageVariantClosed,
		Pencil,
		ProductForm,
	},
	data() {
		return {
			activeTab: 'details',
			showForm: false,
		}
	},
	computed: {
		productStore() {
			return useProductStore()
		},
		productCategoryStore() {
			return useProductCategoryStore()
		},
		catalogItemStore() {
			return useCatalogItemStore()
		},
		purchaseOrderStore() {
			return usePurchaseOrderStore()
		},
		productId() {
			return this.$route.params.productId
		},
		product() {
			return this.productStore.objectItem
		},
		isLoading() {
			return this.productStore.isLoading
		},
		catalogItemsLoading() {
			return this.catalogItemStore.isLoading
		},
		purchaseOrdersLoading() {
			return this.purchaseOrderStore.isLoading
		},
		categoryName() {
			if (!this.product?.categoryId) return '-'
			const category = (this.productCategoryStore.objectList || []).find(
				(c) => c.id === this.product.categoryId,
			)
			return category ? category.name : this.product.categoryId
		},
		filteredCatalogItems() {
			return (this.catalogItemStore.objectList || []).filter(
				(item) => item.productId === this.productId,
			)
		},
		filteredOrderLines() {
			return (this.purchaseOrderStore.objectList || []).filter(
				(line) => line.productId === this.productId,
			)
		},
	},
	watch: {
		productId: {
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
				this.productStore.fetchOne(id),
				this.productCategoryStore.fetchAll(),
				this.catalogItemStore.fetchAll(),
				this.purchaseOrderStore.fetchAll(),
			])
		},
		goBack() {
			this.$router.push({ name: 'ProductIndex' })
		},
		formatPrice(price, currency) {
			if (price == null) return '-'
			return `${currency || 'EUR'} ${Number(price).toFixed(2)}`
		},
		onSaved() {
			this.showForm = false
			this.productStore.fetchOne(this.productId)
		},
	},
}
</script>

<style scoped>
.product-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.product-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.product-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
	flex: 1;
}

.product-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.product-detail__properties {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px 16px;
}

.product-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.product-detail__properties dd {
	margin: 0;
}

.product-detail__table {
	width: 100%;
	border-collapse: collapse;
}

.product-detail__table th,
.product-detail__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.product-detail__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.icon--success {
	color: var(--color-success);
}

.icon--muted {
	color: var(--color-text-maxcontrast);
}
</style>
