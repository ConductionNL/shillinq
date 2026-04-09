<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.1
-->
<template>
	<div class="collaboration-panel">
		<PresenceStrip :target-type="targetType" :target-id="targetId" />
		<NcAppNavigationItem :name="t('shillinq', 'Comments')" :count="unresolvedCount">
			<template #icon>
				<CommentTextOutline :size="20" />
			</template>
			<CommentThread
				:target-type="targetType"
				:target-id="targetId"
				:comments="comments"
				@resolve="onResolve" />
			<CommentInput
				:target-type="targetType"
				:target-id="targetId"
				@submit="onCommentAdded" />
		</NcAppNavigationItem>
		<NcAppNavigationItem :name="t('shillinq', 'Team')">
			<template #icon>
				<AccountGroup :size="20" />
			</template>
			<CollaborationRoleChip
				v-for="role in roles"
				:key="role.id"
				:role="role"
				:can-remove="true"
				@remove="onRoleRemoved" />
			<NcButton type="secondary" @click="showRoleDialog = true">
				{{ t('shillinq', 'Add Member') }}
			</NcButton>
			<CollaborationRoleForm
				v-if="showRoleDialog"
				:target-type="targetType"
				:target-id="targetId"
				@close="showRoleDialog = false"
				@created="onRoleCreated" />
		</NcAppNavigationItem>
	</div>
</template>

<script>
import { NcAppNavigationItem, NcButton } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import CollaborationRoleChip from './CollaborationRoleChip.vue'
import CollaborationRoleForm from '../views/collaborationRole/CollaborationRoleForm.vue'
import CommentInput from './CommentInput.vue'
import CommentThread from './CommentThread.vue'
import PresenceStrip from './PresenceStrip.vue'
import { useCommentStore } from '../store/modules/comment.js'
import { useCollaborationRoleStore } from '../store/modules/collaborationRole.js'
import { startHeartbeat, stopHeartbeat } from '../store/modules/presence.js'

export default {
	name: 'DocumentCollaborationPanel',
	components: {
		NcAppNavigationItem,
		NcButton,
		AccountGroup,
		CollaborationRoleChip,
		CollaborationRoleForm,
		CommentInput,
		CommentTextOutline,
		CommentThread,
		PresenceStrip,
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
			showRoleDialog: false,
		}
	},
	computed: {
		commentStore() {
			return useCommentStore()
		},
		roleStore() {
			return useCollaborationRoleStore()
		},
		comments() {
			return this.commentStore.byTarget(this.targetType, this.targetId)
		},
		roles() {
			return this.roleStore.rolesForTarget(this.targetType, this.targetId)
		},
		unresolvedCount() {
			return this.comments.filter((c) => !c.resolved).length
		},
	},
	mounted() {
		startHeartbeat(this.targetType, this.targetId)
		this.commentStore.fetchByTarget(this.targetType, this.targetId)
		this.roleStore.fetchByTarget(this.targetType, this.targetId)
	},
	beforeDestroy() {
		stopHeartbeat()
	},
	methods: {
		onCommentAdded() {
			this.commentStore.fetchByTarget(this.targetType, this.targetId)
		},
		onResolve(id) {
			this.commentStore.resolveComment(id)
		},
		onRoleCreated() {
			this.showRoleDialog = false
			this.roleStore.fetchByTarget(this.targetType, this.targetId)
		},
		onRoleRemoved() {
			this.roleStore.fetchByTarget(this.targetType, this.targetId)
		},
	},
}
</script>

<style scoped>
.collaboration-panel {
	padding: 8px;
}
</style>
