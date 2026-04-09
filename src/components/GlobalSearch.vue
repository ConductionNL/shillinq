<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-8.3
-->
<template>
	<div class="global-search">
		<div class="global-search__input-wrapper">
			<MagnifyIcon :size="20" class="global-search__icon" />
			<input
				ref="input"
				v-model="query"
				type="text"
				:placeholder="t('shillinq', 'Search across all entities...')"
				class="global-search__input"
				@input="onInput"
				@focus="dropdownOpen = results.length > 0"
				@blur="onBlur">
		</div>
		<div v-if="dropdownOpen && results.length > 0" class="global-search__dropdown">
			<div v-for="group in groupedResults"
				:key="group.type"
				class="global-search__group">
				<div class="global-search__group-label">{{ group.type }}</div>
				<div v-for="item in group.items"
					:key="item.id"
					class="global-search__result"
					@mousedown.prevent="navigateTo(item)">
					{{ item.name || item.title || item.key || item.fileName || item.id }}
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

export default {
	name: 'GlobalSearch',
	components: {
		MagnifyIcon: Magnify,
	},
	data() {
		return {
			query: '',
			results: [],
			dropdownOpen: false,
			debounceTimer: null,
		}
	},
	computed: {
		groupedResults() {
			const groups = {}
			this.results.forEach(item => {
				const type = item._type || 'other'
				if (!groups[type]) groups[type] = { type, items: [] }
				groups[type].items.push(item)
			})
			return Object.values(groups)
		},
	},
	watch: {
		query(val) {
			if (!val) {
				this.results = []
				this.dropdownOpen = false
			}
		},
	},
	methods: {
		onInput() {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.search(), 300)
		},
		async search() {
			if (!this.query || this.query.length < 2) {
				this.results = []
				return
			}

			const store = useObjectStore()
			const types = ['organization', 'appSettings', 'dashboard', 'dataJob']
			const allResults = []

			for (const type of types) {
				try {
					const items = await store.fetchCollection(type, { _search: this.query, _limit: 5 })
					if (items && items.length) {
						items.forEach(item => {
							allResults.push({ ...item, _type: type })
						})
					}
				} catch {
					// Skip types that fail
				}
			}

			this.results = allResults
			this.dropdownOpen = allResults.length > 0
		},
		navigateTo(item) {
			this.dropdownOpen = false
			this.query = ''
			this.results = []

			const routes = {
				organization: `/organizations/${item.id}`,
				appSettings: '/settings',
				dashboard: '/',
				dataJob: `/data-jobs/${item.id}`,
			}
			const route = routes[item._type] || '/'
			this.$router.push(route)
		},
		onBlur() {
			setTimeout(() => {
				this.dropdownOpen = false
			}, 200)
		},
	},
}
</script>

<style scoped>
.global-search {
	position: relative;
	width: 100%;
	max-width: 400px;
}

.global-search__input-wrapper {
	display: flex;
	align-items: center;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill);
	padding: 4px 12px;
	background: var(--color-main-background);
}

.global-search__icon {
	color: var(--color-text-maxcontrast);
	margin-right: 8px;
}

.global-search__input {
	border: none;
	outline: none;
	background: transparent;
	width: 100%;
	font-size: 14px;
}

.global-search__dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	z-index: 100;
	max-height: 400px;
	overflow: auto;
	margin-top: 4px;
}

.global-search__group-label {
	padding: 8px 12px;
	font-weight: 600;
	font-size: 12px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.global-search__result {
	padding: 8px 12px;
	cursor: pointer;
}

.global-search__result:hover {
	background: var(--color-background-hover);
}
</style>
