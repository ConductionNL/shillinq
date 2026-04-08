<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.3
-->
<template>
	<div v-if="results.length > 0" class="mention-autocomplete">
		<div
			v-for="user in results"
			:key="user.id"
			class="mention-autocomplete__item"
			@click="$emit('select', user.id)">
			<NcAvatar :user="user.id" :size="24" />
			<span class="mention-autocomplete__name">{{ user.displayName || user.id }}</span>
		</div>
	</div>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import { generateOcsUrl } from '@nextcloud/router'

export default {
	name: 'MentionAutocomplete',
	components: {
		NcAvatar,
	},
	props: {
		query: {
			type: String,
			required: true,
		},
	},
	emits: ['select'],
	data() {
		return {
			results: [],
		}
	},
	watch: {
		query: {
			immediate: true,
			handler: 'search',
		},
	},
	methods: {
		async search() {
			if (!this.query || this.query.length < 1) {
				this.results = []
				return
			}
			try {
				const url = generateOcsUrl('/cloud/users/details?search={query}&limit=5', {
					query: this.query,
				})
				const response = await fetch(url, {
					headers: {
						'OCS-APIRequest': 'true',
						requesttoken: OC.requestToken,
					},
				})
				if (response.ok) {
					const data = await response.json()
					const users = data?.ocs?.data?.users || {}
					this.results = Object.entries(users).map(([id, info]) => ({
						id,
						displayName: info.displayname || id,
					}))
				}
			} catch (error) {
				console.error('User search failed:', error)
				this.results = []
			}
		},
	},
}
</script>

<style scoped>
.mention-autocomplete {
	position: absolute;
	bottom: 100%;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	z-index: 100;
	max-height: 200px;
	overflow-y: auto;
}

.mention-autocomplete__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
}

.mention-autocomplete__item:hover {
	background-color: var(--color-background-hover);
}

.mention-autocomplete__name {
	font-weight: 500;
}
</style>
