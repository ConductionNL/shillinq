<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-7.1 -->
<template>
	<div class="product-category-index">
		<header class="product-category-index__header">
			<h2>{{ t('shillinq', 'Product Categories') }}</h2>
			<div class="product-category-index__actions">
				<NcButton type="secondary" @click="toggleView">
					<template #icon>
						<FileTreeOutline v-if="viewMode === 'flat'" :size="20" />
						<FormatListBulleted v-else :size="20" />
					</template>
					{{ viewMode === 'flat' ? t('shillinq', 'Tree view') : t('shillinq', 'Flat view') }}
				</NcButton>
				<NcButton type="primary" @click="showForm = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('shillinq', 'New Category') }}
				</NcButton>
			</div>
		</header>

		<NcLoadingIcon v-if="isLoading" :size="44" />

		<table v-else-if="categories.length" class="product-category-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Code') }}</th>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
					<th>{{ t('shillinq', 'Sort Order') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="category in displayedCategories"
					:key="category.id"
					class="product-category-index__row"
					@click="navigateToDetail(category)">
					<td>
						<span v-if="viewMode === 'tree'" :style="{ paddingLeft: (category._depth || 0) * 20 + 'px' }">
							{{ category.name }}
						</span>
						<span v-else>{{ category.name }}</span>
					</td>
					<td>{{ category.code }}</td>
					<td>{{ category.description }}</td>
					<td>
						<CheckCircle v-if="category.active" :size="20" class="icon--success" />
						<CloseCircle v-else :size="20" class="icon--muted" />
					</td>
					<td>{{ category.sortOrder }}</td>
				</tr>
			</tbody>
		</table>

		<NcEmptyContent v-else :name="t('shillinq', 'No categories found')">
			<template #icon>
				<FolderOutline :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="showForm = true">
					{{ t('shillinq', 'Create your first category') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ProductCategoryForm
			v-if="showForm"
			@close="showForm = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useProductCategoryStore } from '../../store/modules/productCategory.js'
import ProductCategoryForm from './ProductCategoryForm.vue'

export default {
	name: 'ProductCategoryIndex',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CheckCircle,
		CloseCircle,
		FileTreeOutline,
		FolderOutline,
		FormatListBulleted,
		Plus,
		ProductCategoryForm,
	},
	data() {
		return {
			showForm: false,
			viewMode: 'flat',
		}
	},
	computed: {
		productCategoryStore() {
			return useProductCategoryStore()
		},
		categories() {
			return this.productCategoryStore.objectList || []
		},
		isLoading() {
			return this.productCategoryStore.isLoading
		},
		displayedCategories() {
			if (this.viewMode === 'tree') {
				return this.buildTree(this.categories)
			}
			return [...this.categories].sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
		},
	},
	mounted() {
		this.productCategoryStore.fetchAll()
	},
	methods: {
		toggleView() {
			this.viewMode = this.viewMode === 'flat' ? 'tree' : 'flat'
		},
		navigateToDetail(category) {
			this.$router.push({ name: 'ProductCategoryDetail', params: { categoryId: category.id } })
		},
		onSaved() {
			this.showForm = false
			this.productCategoryStore.fetchAll()
		},
		buildTree(categories, parentId = null, depth = 0) {
			const result = []
			const children = categories
				.filter((c) => (c.parentCategoryId || null) === parentId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			for (const child of children) {
				result.push({ ...child, _depth: depth })
				result.push(...this.buildTree(categories, child.id, depth + 1))
			}
			return result
		},
	},
}
</script>

<style scoped>
.product-category-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.product-category-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.product-category-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.product-category-index__actions {
	display: flex;
	gap: 8px;
}

.product-category-index__table {
	width: 100%;
	border-collapse: collapse;
}

.product-category-index__table th,
.product-category-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.product-category-index__table th {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.product-category-index__row {
	cursor: pointer;
}

.product-category-index__row:hover {
	background-color: var(--color-background-hover);
}

.icon--success {
	color: var(--color-success);
}

.icon--muted {
	color: var(--color-text-maxcontrast);
}
</style>
