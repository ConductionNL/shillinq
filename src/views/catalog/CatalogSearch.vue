<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-8.2 -->
<template>
	<div class="catalog-search">
		<header class="catalog-search__header">
			<h2>{{ t('shillinq', 'Catalog Search') }}</h2>
		</header>

		<div class="catalog-search__controls">
			<NcTextField
				v-model="searchQuery"
				:label="t('shillinq', 'Search products...')"
				class="catalog-search__input"
				@input="onSearchInput" />

			<NcSelect
				v-model="selectedCategory"
				:options="categories"
				:placeholder="t('shillinq', 'All categories')"
				class="catalog-search__category"
				@input="onSearchInput" />
		</div>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="results.length === 0 && hasSearched"
			:name="t('shillinq', 'No results')"
			:description="t('shillinq', 'Try adjusting your search terms or category filter.')">
			<template #icon>
				<MagnifyIcon :size="64" />
			</template>
		</NcEmptyContent>

		<div v-else class="catalog-search__results">
			<div
				v-for="item in results"
				:key="item.id"
				class="catalog-search__result-card">
				<div class="catalog-search__result-info">
					<h3>{{ item.productName }}</h3>
					<p class="catalog-search__supplier">
						{{ item.supplierName }}
					</p>
					<p class="catalog-search__price">
						{{ formatPrice(item.unitPrice, item.currency) }}
					</p>
					<p class="catalog-search__meta">
						{{ t('shillinq', 'Catalog') }}: {{ item.catalogName }}
						<template v-if="item.contractReference">
							&middot; {{ item.contractReference }}
						</template>
					</p>
				</div>
				<div class="catalog-search__result-actions">
					<NcTextField
						v-model.number="item._quantity"
						type="number"
						:label="t('shillinq', 'Qty')"
						min="1"
						class="catalog-search__qty-input" />
					<NcButton type="primary" @click="addToBasket(item)">
						<template #icon>
							<CartPlusIcon :size="20" />
						</template>
						{{ t('shillinq', 'Add to basket') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import CartPlusIcon from 'vue-material-design-icons/CartPlus.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'CatalogSearch',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CartPlusIcon,
		MagnifyIcon,
	},

	data() {
		return {
			searchQuery: '',
			selectedCategory: null,
			categories: [],
			results: [],
			loading: false,
			hasSearched: false,
			debounceTimer: null,
		}
	},

	async created() {
		await this.loadCategories()
	},

	beforeDestroy() {
		if (this.debounceTimer) {
			clearTimeout(this.debounceTimer)
		}
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		onSearchInput() {
			if (this.debounceTimer) {
				clearTimeout(this.debounceTimer)
			}
			this.debounceTimer = setTimeout(() => {
				this.performSearch()
			}, 300)
		},

		async performSearch() {
			if (!this.searchQuery && !this.selectedCategory) {
				this.results = []
				this.hasSearched = false
				return
			}

			this.loading = true
			this.hasSearched = true
			try {
				const url = new URL(
					generateUrl('/apps/shillinq/api/v1/catalog/search'),
					window.location.origin,
				)
				if (this.searchQuery) {
					url.searchParams.set('q', this.searchQuery)
				}
				if (this.selectedCategory) {
					url.searchParams.set('category', this.selectedCategory)
				}

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.results = (data.results || data).map((item) => ({
						...item,
						_quantity: 1,
					}))
				}
			} catch (error) {
				console.error('Catalog search failed:', error)
			} finally {
				this.loading = false
			}
		},

		async loadCategories() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/catalog/categories')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.categories = await response.json()
				}
			} catch (error) {
				console.error('Failed to load categories:', error)
			}
		},

		formatPrice(price, currency) {
			if (price == null) return '-'
			const cur = currency || 'EUR'
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: cur,
			}).format(price)
		},

		async addToBasket(item) {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/order-baskets/current/lines')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						catalogItemId: item.id,
						quantity: item._quantity || 1,
					}),
				})
				if (response.ok) {
					this.$emit('basket-updated')
				}
			} catch (error) {
				console.error('Failed to add item to basket:', error)
			}
		},
	},
}
</script>

<style scoped>
.catalog-search {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.catalog-search__header h2 {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.catalog-search__controls {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.catalog-search__input {
	flex: 2;
}

.catalog-search__category {
	flex: 1;
}

.catalog-search__results {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.catalog-search__result-card {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.catalog-search__result-info h3 {
	margin: 0 0 4px;
	font-size: 16px;
}

.catalog-search__supplier {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.catalog-search__price {
	margin: 4px 0;
	font-weight: 600;
	font-size: 15px;
}

.catalog-search__meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.catalog-search__result-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.catalog-search__qty-input {
	width: 70px;
}
</style>
