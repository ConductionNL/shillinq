<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.4
-->
<template>
	<div v-if="activeViewers.length > 0" class="presence-strip">
		<div
			v-for="record in activeViewers"
			:key="record.userId"
			class="presence-strip__avatar"
			:title="record.userId">
			<NcAvatar :user="record.userId" :size="28" />
			<PencilIcon
				v-if="record.isEditing"
				:size="12"
				class="presence-strip__editing" />
		</div>
	</div>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { usePresenceStore } from '../store/modules/presence.js'

export default {
	name: 'PresenceStrip',
	components: {
		NcAvatar,
		PencilIcon,
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
			pollTimer: null,
		}
	},
	computed: {
		presenceStore() {
			return usePresenceStore()
		},
		activeViewers() {
			return this.presenceStore.records
		},
	},
	mounted() {
		this.fetchViewers()
		this.pollTimer = setInterval(() => this.fetchViewers(), 30000)
	},
	beforeDestroy() {
		if (this.pollTimer) {
			clearInterval(this.pollTimer)
		}
	},
	methods: {
		fetchViewers() {
			this.presenceStore.fetchActiveViewers(this.targetType, this.targetId)
		},
	},
}
</script>

<style scoped>
.presence-strip {
	display: flex;
	gap: 4px;
	padding: 8px 0;
	flex-wrap: wrap;
}

.presence-strip__avatar {
	position: relative;
	display: inline-block;
}

.presence-strip__editing {
	position: absolute;
	bottom: -2px;
	right: -2px;
	background: var(--color-warning);
	border-radius: 50%;
	padding: 1px;
}
</style>
