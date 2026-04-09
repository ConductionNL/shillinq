<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div v-if="filteredUsers.length > 0" class="mention-autocomplete">
		<ul class="mention-autocomplete__list">
			<li
				v-for="(user, index) in filteredUsers"
				:key="user.id"
				class="mention-autocomplete__item"
				:class="{ 'mention-autocomplete__item--active': index === activeIndex }"
				@mousedown.prevent="selectUser(user)"
				@mouseenter="activeIndex = index">
				<NcAvatar
					:user="user.id"
					:display-name="user.displayName"
					:size="24" />
				<span class="mention-autocomplete__name">{{ user.displayName }}</span>
				<span class="mention-autocomplete__id">{{ user.id }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'MentionAutocomplete',
	components: {
		NcAvatar,
	},
	props: {
		query: {
			type: String,
			default: '',
		},
	},
	emits: ['select'],
	data() {
		return {
			users: [],
			activeIndex: 0,
			loading: false,
		}
	},
	computed: {
		filteredUsers() {
			if (!this.query) return this.users.slice(0, 8)
			const q = this.query.toLowerCase()
			return this.users
				.filter(u =>
					u.id.toLowerCase().includes(q)
					|| (u.displayName && u.displayName.toLowerCase().includes(q)),
				)
				.slice(0, 8)
		},
	},
	watch: {
		query: {
			immediate: true,
			handler() {
				this.activeIndex = 0
				this.searchUsers()
			},
		},
	},
	methods: {
		async searchUsers() {
			if (this.loading) return
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/users/search?search=' + encodeURIComponent(this.query || ''))
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.users = (data.results || data || []).map(u => ({
						id: u.id || u.uid || u.userId,
						displayName: u.displayName || u.displayname || u.name || u.id || u.uid,
					}))
				}
			} catch (error) {
				console.error('Failed to search users for mentions:', error)
			} finally {
				this.loading = false
			}
		},
		selectUser(user) {
			this.$emit('select', user.id)
		},
	},
}
</script>

<style scoped>
.mention-autocomplete {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	max-height: 240px;
	overflow-y: auto;
	min-width: 220px;
}

.mention-autocomplete__list {
	list-style: none;
	margin: 0;
	padding: 4px 0;
}

.mention-autocomplete__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	cursor: pointer;
}

.mention-autocomplete__item:hover,
.mention-autocomplete__item--active {
	background-color: var(--color-background-hover);
}

.mention-autocomplete__name {
	font-weight: 600;
	font-size: 13px;
}

.mention-autocomplete__id {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
