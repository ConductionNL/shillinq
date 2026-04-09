<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="presence-strip">
		<div v-if="records.length > 0" class="presence-strip__avatars">
			<div
				v-for="record in records"
				:key="record.userId || record.id"
				class="presence-strip__avatar-wrapper"
				:title="presenceTitle(record)">
				<NcAvatar
					:user="record.userId || record.id"
					:display-name="record.displayName || record.userId || record.id"
					:size="32" />
				<PencilOutline
					v-if="record.isEditing"
					:size="14"
					class="presence-strip__editing-overlay" />
			</div>
		</div>
		<span v-else class="presence-strip__empty">
			{{ t('shillinq', 'No one else is viewing this item') }}
		</span>
	</div>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import { usePresenceStore } from '../store/modules/presence.js'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'

export default {
	name: 'PresenceStrip',
	components: {
		NcAvatar,
		PencilOutline,
	},
	props: {
		targetType: {
			type: String,
			required: true,
		},
		targetId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			presenceStore: usePresenceStore(),
			refreshInterval: null,
		}
	},
	computed: {
		records() {
			return this.presenceStore.records
		},
	},
	mounted() {
		this.presenceStore.fetchPresence(this.targetType, this.targetId)
		this.refreshInterval = setInterval(() => {
			this.presenceStore.fetchPresence(this.targetType, this.targetId)
		}, 30000)
	},
	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
			this.refreshInterval = null
		}
	},
	methods: {
		presenceTitle(record) {
			const name = record.displayName || record.userId || record.id
			if (record.isEditing) {
				return t('shillinq', '{name} is editing', { name })
			}
			return t('shillinq', '{name} is viewing', { name })
		},
	},
}
</script>

<style scoped>
.presence-strip {
	padding: 4px 0;
}

.presence-strip__avatars {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.presence-strip__avatar-wrapper {
	position: relative;
	display: inline-flex;
}

.presence-strip__editing-overlay {
	position: absolute;
	bottom: -2px;
	right: -2px;
	background: var(--color-main-background);
	border-radius: 50%;
	padding: 1px;
	color: var(--color-primary-element);
	box-shadow: 0 0 2px rgba(0, 0, 0, 0.2);
}

.presence-strip__empty {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
