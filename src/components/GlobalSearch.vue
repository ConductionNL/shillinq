<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="global-search">
		<input
			v-model="query"
			type="search"
			:placeholder="t('shillinq', 'Search...')"
			class="global-search__input"
			@input="onInput"
			@focus="showDropdown = true">

		<div v-if="showDropdown && groupedResults.length > 0" class="global-search__dropdown">
			<div v-for="group in groupedResults"
				:key="group.schema"
				class="global-search__group">
				<h4 class="global-search__group-title">
					{{ group.label }}
				</h4>
				<ul class="global-search__list">
					<li v-for="result in group.items"
						:key="result.id"
						class="global-search__item"
						@click="navigateTo(result, group.schema)">
						{{ result.name || result.title || result.key || result.fileName }}
					</li>
				</ul>
			</div>
		</div>

		<div v-if="showDropdown && query.length > 0 && groupedResults.length === 0 && !searching"
			class="global-search__dropdown global-search__no-results">
			{{ t('shillinq', 'No results found') }}
		</div>
	</div>
</template>

<script>
import { useOrganizationStore } from '../store/modules/organization.js'
import { useAppSettingsStore } from '../store/modules/appSettings.js'
import { useDashboardStore } from '../store/modules/dashboard.js'
import { useDataJobStore } from '../store/modules/dataJob.js'

let debounceTimer = null

export default {
	name: 'GlobalSearch',

	data() {
		return {
			query: '',
			showDropdown: false,
			searching: false,
			results: {
				organization: [],
				appSettings: [],
				dashboard: [],
				dataJob: [],
			},
		}
	},

	computed: {
		groupedResults() {
			const groups = []
			const labelMap = {
				organization: t('shillinq', 'Organizations'),
				appSettings: t('shillinq', 'App Settings'),
				dashboard: t('shillinq', 'Dashboards'),
				dataJob: t('shillinq', 'Data Jobs'),
			}

			for (const [schema, items] of Object.entries(this.results)) {
				if (items.length > 0) {
					groups.push({ schema, label: labelMap[schema], items })
				}
			}
			return groups
		},
	},

	mounted() {
		document.addEventListener('click', this.onClickOutside)
	},
	beforeDestroy() {
		document.removeEventListener('click', this.onClickOutside)
	},

	methods: {
		onInput() {
			if (debounceTimer) {
				clearTimeout(debounceTimer)
			}

			if (this.query.length === 0) {
				this.results = { organization: [], appSettings: [], dashboard: [], dataJob: [] }
				this.showDropdown = false
				return
			}

			debounceTimer = setTimeout(() => this.search(), 300)
		},

		async search() {
			this.searching = true
			this.showDropdown = true

			const stores = {
				organization: useOrganizationStore(),
				appSettings: useAppSettingsStore(),
				dashboard: useDashboardStore(),
				dataJob: useDataJobStore(),
			}

			const searchPromises = Object.entries(stores).map(
				async ([key, store]) => {
					try {
						await store.fetchObjects({ _search: this.query })
						this.results[key] = (store.objectList ?? []).slice(0, 5)
					} catch {
						this.results[key] = []
					}
				},
			)

			await Promise.all(searchPromises)
			this.searching = false
		},

		navigateTo(result, schema) {
			this.showDropdown = false
			this.query = ''

			const routeMap = {
				organization: { name: 'OrganizationDetail', params: { id: result.id } },
				appSettings: { name: 'AppSettingsPage' },
				dashboard: { name: 'Dashboard' },
				dataJob: { name: 'DataJobDetail', params: { id: result.id } },
			}

			this.$router.push(routeMap[schema])
		},

		onClickOutside(event) {
			if (!this.$el.contains(event.target)) {
				this.showDropdown = false
			}
		},
	},
}
</script>

<style scoped>
.global-search {
	position: relative;
	width: 300px;
}

.global-search__input {
	width: 100%;
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.global-search__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	z-index: 100;
	max-height: 400px;
	overflow-y: auto;
}

.global-search__group {
	padding: 8px 0;
}

.global-search__group-title {
	padding: 4px 12px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.global-search__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.global-search__item {
	padding: 8px 12px;
	cursor: pointer;
}

.global-search__item:hover {
	background: var(--color-background-hover);
}

.global-search__no-results {
	padding: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
