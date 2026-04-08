<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-8.1 -->
<template>
	<div class="catalog-detail">
		<NcLoadingIcon v-if="loading" :size="44" />

		<template v-else-if="catalog">
			<div v-if="catalog.status === 'archived'" class="catalog-detail__banner catalog-detail__banner--archived">
				<LockIcon :size="20" />
				{{ t('shillinq', 'This catalog is archived and read-only.') }}
			</div>

			<header class="catalog-detail__header">
				<div>
					<h2>{{ catalog.name }}</h2>
					<span :class="'catalog-detail__status--' + catalog.status">
						{{ catalog.status }}
					</span>
				</div>
				<div class="catalog-detail__actions">
					<NcButton
						v-if="catalog.status === 'draft'"
						type="primary"
						@click="transitionStatus('active')">
						<template #icon>
							<CheckIcon :size="20" />
						</template>
						{{ t('shillinq', 'Activate') }}
					</NcButton>
					<NcButton
						v-if="catalog.status === 'active'"
						type="secondary"
						@click="transitionStatus('archived')">
						<template #icon>
							<ArchiveIcon :size="20" />
						</template>
						{{ t('shillinq', 'Archive') }}
					</NcButton>
					<NcButton
						v-if="catalog.status !== 'archived'"
						@click="showEditDialog = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('shillinq', 'Edit') }}
					</NcButton>
				</div>
			</header>

			<div class="catalog-detail__tabs">
				<NcButton
					v-for="tab in tabs"
					:key="tab.id"
					:type="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</NcButton>
			</div>

			<!-- Details tab -->
			<div v-if="activeTab === 'details'" class="catalog-detail__section">
				<table class="catalog-detail__properties">
					<tr>
						<th>{{ t('shillinq', 'Description') }}</th>
						<td>{{ catalog.description || '-' }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Supplier Profile') }}</th>
						<td>{{ catalog.supplierProfileId || '-' }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Effective From') }}</th>
						<td>{{ catalog.effectiveFrom || '-' }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Effective To') }}</th>
						<td>{{ catalog.effectiveTo || '-' }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Owner') }}</th>
						<td>{{ catalog.ownerId || '-' }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Contract Reference') }}</th>
						<td>{{ catalog.contractReference || '-' }}</td>
					</tr>
				</table>
			</div>

			<!-- Items tab -->
			<div v-if="activeTab === 'items'" class="catalog-detail__section">
				<NcEmptyContent
					v-if="catalogItems.length === 0"
					:name="t('shillinq', 'No items yet')"
					:description="t('shillinq', 'Add items to this catalog via the Import tab or the API.')">
					<template #icon>
						<PackageVariantClosedIcon :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="catalog-detail__items-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'SKU') }}</th>
							<th>{{ t('shillinq', 'Product Name') }}</th>
							<th>{{ t('shillinq', 'Unit Price') }}</th>
							<th>{{ t('shillinq', 'Currency') }}</th>
							<th>{{ t('shillinq', 'Category') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in catalogItems" :key="item.id">
							<td>{{ item.sku }}</td>
							<td>{{ item.productName }}</td>
							<td>{{ item.unitPrice }}</td>
							<td>{{ item.currency }}</td>
							<td>{{ item.category || '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Import tab -->
			<div v-if="activeTab === 'import'" class="catalog-detail__section">
				<CatalogImportPanel
					v-if="catalog.status !== 'archived'"
					:catalog-id="catalog.id"
					@imported="loadCatalogItems" />
				<p v-else>
					{{ t('shillinq', 'Import is disabled for archived catalogs.') }}
				</p>
			</div>
		</template>

		<NcEmptyContent
			v-else
			:name="t('shillinq', 'Catalog not found')">
			<template #icon>
				<AlertCircleOutlineIcon :size="64" />
			</template>
		</NcEmptyContent>

		<CatalogForm
			v-if="showEditDialog"
			:catalog="catalog"
			@close="showEditDialog = false"
			@saved="onCatalogSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArchiveIcon from 'vue-material-design-icons/Archive.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PackageVariantClosedIcon from 'vue-material-design-icons/PackageVariantClosed.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CatalogForm from './CatalogForm.vue'
import CatalogImportPanel from '../../components/CatalogImportPanel.vue'

export default {
	name: 'CatalogDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutlineIcon,
		ArchiveIcon,
		CheckIcon,
		LockIcon,
		PackageVariantClosedIcon,
		PencilIcon,
		CatalogForm,
		CatalogImportPanel,
	},

	data() {
		return {
			loading: false,
			catalog: null,
			catalogItems: [],
			activeTab: 'details',
			showEditDialog: false,
		}
	},

	computed: {
		tabs() {
			return [
				{ id: 'details', label: this.t('shillinq', 'Details') },
				{ id: 'items', label: this.t('shillinq', 'Items') },
				{ id: 'import', label: this.t('shillinq', 'Import') },
			]
		},
	},

	async created() {
		await this.loadCatalog()
		await this.loadCatalogItems()
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		async loadCatalog() {
			this.loading = true
			try {
				const id = this.$route.params.id
				const url = generateUrl(`/apps/shillinq/api/v1/catalogs/${id}`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.catalog = await response.json()
				}
			} catch (error) {
				console.error('Failed to load catalog:', error)
			} finally {
				this.loading = false
			}
		},

		async loadCatalogItems() {
			try {
				const id = this.$route.params.id
				const url = generateUrl(`/apps/shillinq/api/v1/catalogs/${id}/items`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.catalogItems = data.results || data
				}
			} catch (error) {
				console.error('Failed to load catalog items:', error)
			}
		},

		async transitionStatus(newStatus) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/catalogs/${this.catalog.id}`)
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ status: newStatus }),
				})
				if (response.ok) {
					this.catalog = await response.json()
				}
			} catch (error) {
				console.error('Failed to update catalog status:', error)
			}
		},

		async onCatalogSaved() {
			this.showEditDialog = false
			await this.loadCatalog()
		},
	},
}
</script>

<style scoped>
.catalog-detail {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.catalog-detail__banner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	border-radius: var(--border-radius-large);
	margin-bottom: 16px;
}

.catalog-detail__banner--archived {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.catalog-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 16px;
}

.catalog-detail__header h2 {
	margin: 0 0 4px;
	font-size: 22px;
	font-weight: 600;
}

.catalog-detail__actions {
	display: flex;
	gap: 8px;
}

.catalog-detail__status--draft {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.catalog-detail__status--active {
	color: var(--color-success);
	font-weight: 600;
}

.catalog-detail__status--archived {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.catalog-detail__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.catalog-detail__section {
	margin-top: 8px;
}

.catalog-detail__properties th {
	padding: 8px 16px 8px 0;
	text-align: left;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	vertical-align: top;
}

.catalog-detail__properties td {
	padding: 8px 0;
}

.catalog-detail__items-table {
	width: 100%;
	border-collapse: collapse;
}

.catalog-detail__items-table th,
.catalog-detail__items-table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}
</style>
