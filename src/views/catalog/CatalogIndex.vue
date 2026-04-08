<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-8.1 -->
<template>
	<div class="catalog-index">
		<header class="catalog-index__header">
			<h2>{{ t('shillinq', 'Catalogs') }}</h2>
			<NcButton type="primary" @click="showNewDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'New Catalog') }}
			</NcButton>
		</header>

		<div class="catalog-index__filters">
			<NcButton
				v-for="chip in statusChips"
				:key="chip.value"
				:type="activeStatus === chip.value ? 'primary' : 'secondary'"
				@click="filterByStatus(chip.value)">
				{{ chip.label }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="filteredCatalogs.length === 0"
			:name="t('shillinq', 'No catalogs found')"
			:description="t('shillinq', 'Create a new catalog or change the active filter.')">
			<template #icon>
				<BookOpenVariantOutline :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="catalog-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Effective From') }}</th>
					<th>{{ t('shillinq', 'Effective To') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="catalog in filteredCatalogs"
					:key="catalog.id"
					class="catalog-index__row"
					@click="goToDetail(catalog.id)">
					<td>{{ catalog.name }}</td>
					<td>
						<span :class="'catalog-index__badge--' + catalog.status">
							{{ catalog.status }}
						</span>
					</td>
					<td>{{ catalog.effectiveFrom || '-' }}</td>
					<td>{{ catalog.effectiveTo || '-' }}</td>
				</tr>
			</tbody>
		</table>

		<CatalogForm
			v-if="showNewDialog"
			@close="showNewDialog = false"
			@saved="onCatalogSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CatalogForm from './CatalogForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CatalogIndex',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		BookOpenVariantOutline,
		PlusIcon,
		CatalogForm,
	},

	data() {
		return {
			loading: false,
			activeStatus: null,
			showNewDialog: false,
			catalogs: [],
		}
	},

	computed: {
		statusChips() {
			return [
				{ label: this.t('shillinq', 'All'), value: null },
				{ label: this.t('shillinq', 'Draft'), value: 'draft' },
				{ label: this.t('shillinq', 'Active'), value: 'active' },
				{ label: this.t('shillinq', 'Archived'), value: 'archived' },
			]
		},
		filteredCatalogs() {
			if (!this.activeStatus) {
				return this.catalogs
			}
			return this.catalogs.filter((c) => c.status === this.activeStatus)
		},
	},

	async created() {
		await this.loadCatalogs()
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		filterByStatus(status) {
			this.activeStatus = status
		},

		goToDetail(id) {
			this.$router.push({ name: 'CatalogDetail', params: { id } })
		},

		async loadCatalogs() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				this.catalogs = await objectStore.fetchObjects('catalog')
			} catch (error) {
				console.error('Failed to load catalogs:', error)
			} finally {
				this.loading = false
			}
		},

		async onCatalogSaved() {
			this.showNewDialog = false
			await this.loadCatalogs()
		},
	},
}
</script>

<style scoped>
.catalog-index {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.catalog-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.catalog-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.catalog-index__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.catalog-index__table {
	width: 100%;
	border-collapse: collapse;
}

.catalog-index__table th,
.catalog-index__table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.catalog-index__row {
	cursor: pointer;
}

.catalog-index__row:hover {
	background-color: var(--color-background-hover);
}

.catalog-index__badge--draft {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.catalog-index__badge--active {
	color: var(--color-success);
	font-weight: 600;
}

.catalog-index__badge--archived {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>
