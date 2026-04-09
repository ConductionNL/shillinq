<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="document-collaboration-panel">
		<div class="document-collaboration-panel__section">
			<NcAppNavigationItem
				:name="commentsLabel"
				:open.sync="commentsOpen"
				:allow-collapse="true">
				<template #icon>
					<CommentOutline :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble v-if="unresolvedCount > 0" type="highlighted">
						{{ unresolvedCount }}
					</NcCounterBubble>
				</template>
				<template #default>
					<div class="document-collaboration-panel__comments">
						<CommentThread
							:target-type="targetType"
							:target-id="targetId"
							:comments="comments"
							@resolve="onResolveComment" />
						<CommentInput @submit="onSubmitComment" />
					</div>
				</template>
			</NcAppNavigationItem>
		</div>

		<div class="document-collaboration-panel__section">
			<NcAppNavigationItem
				:name="t('shillinq', 'Team')"
				:open.sync="teamOpen"
				:allow-collapse="true">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
				<template #default>
					<div class="document-collaboration-panel__team">
						<PresenceStrip
							:target-type="targetType"
							:target-id="targetId" />
						<div class="document-collaboration-panel__roles">
							<CollaborationRoleChip
								v-for="role in roles"
								:key="role.id"
								:role="role"
								@remove="onRemoveRole(role)" />
						</div>
						<NcButton
							type="secondary"
							class="document-collaboration-panel__add-member"
							@click="showAddMember = true">
							<template #icon>
								<AccountPlusOutline :size="20" />
							</template>
							{{ t('shillinq', 'Add member') }}
						</NcButton>
					</div>
				</template>
			</NcAppNavigationItem>
		</div>

		<CollaborationRoleForm
			v-if="showAddMember"
			@close="showAddMember = false"
			@created="onMemberAdded" />
	</div>
</template>

<script>
import { NcAppNavigationItem, NcButton, NcCounterBubble } from '@nextcloud/vue'
import { useCommentStore } from '../store/modules/comment.js'
import { useCollaborationRoleStore } from '../store/modules/collaborationRole.js'
import { startHeartbeat, stopHeartbeat } from '../store/modules/presence.js'
import CommentThread from './CommentThread.vue'
import CommentInput from './CommentInput.vue'
import PresenceStrip from './PresenceStrip.vue'
import CollaborationRoleChip from './CollaborationRoleChip.vue'
import CollaborationRoleForm from '../views/collaborationRole/CollaborationRoleForm.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'

export default {
	name: 'DocumentCollaborationPanel',
	components: {
		NcAppNavigationItem,
		NcButton,
		NcCounterBubble,
		CommentThread,
		CommentInput,
		PresenceStrip,
		CollaborationRoleChip,
		CollaborationRoleForm,
		CommentOutline,
		AccountGroupOutline,
		AccountPlusOutline,
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
			commentStore: useCommentStore(),
			roleStore: useCollaborationRoleStore(),
			commentsOpen: true,
			teamOpen: true,
			showAddMember: false,
		}
	},
	computed: {
		comments() {
			return this.commentStore.byTarget(this.targetType, this.targetId)
		},
		unresolvedCount() {
			return this.commentStore.unresolvedCount(this.targetType, this.targetId)
		},
		roles() {
			return this.roleStore.rolesForTarget(this.targetType, this.targetId)
		},
		commentsLabel() {
			return t('shillinq', 'Comments')
		},
	},
	mounted() {
		startHeartbeat(this.targetType, this.targetId)
		this.commentStore.fetchComments(this.targetType, this.targetId)
		this.roleStore.fetchRoles(this.targetType, this.targetId)
	},
	beforeDestroy() {
		stopHeartbeat()
	},
	methods: {
		async onResolveComment(commentId) {
			await this.commentStore.resolveComment(commentId)
		},
		async onSubmitComment({ content, mentions }) {
			await this.commentStore.createComment({
				targetType: this.targetType,
				targetId: this.targetId,
				content,
				mentions,
			})
		},
		async onRemoveRole(role) {
			await this.roleStore.deleteRole(role.id)
		},
		onMemberAdded() {
			this.showAddMember = false
			this.roleStore.fetchRoles(this.targetType, this.targetId)
		},
	},
}
</script>

<style scoped>
.document-collaboration-panel {
	padding: 8px 0;
}

.document-collaboration-panel__section {
	margin-bottom: 4px;
}

.document-collaboration-panel__comments {
	padding: 8px 12px;
}

.document-collaboration-panel__team {
	padding: 8px 12px;
}

.document-collaboration-panel__roles {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 8px 0;
}

.document-collaboration-panel__add-member {
	margin-top: 8px;
}
</style>
