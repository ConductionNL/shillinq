<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-4.2
-->
<template>
	<div class="comment-detail-page">
		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else-if="comment">
			<CnConfigurationCard :title="t('shillinq', 'Comment Detail')">
				<div class="comment-detail__field">
					<strong>{{ t('shillinq', 'Author') }}:</strong>
					<span>{{ comment.author }}</span>
				</div>
				<div class="comment-detail__field">
					<strong>{{ t('shillinq', 'Content') }}:</strong>
					<p>{{ comment.content }}</p>
				</div>
				<div class="comment-detail__field">
					<strong>{{ t('shillinq', 'Target') }}:</strong>
					<span>{{ comment.targetType }} — {{ comment.targetId }}</span>
				</div>
				<div class="comment-detail__field">
					<strong>{{ t('shillinq', 'Timestamp') }}:</strong>
					<span>{{ formatDate(comment.timestamp) }}</span>
				</div>
				<div v-if="comment.mentions && comment.mentions.length" class="comment-detail__field">
					<strong>{{ t('shillinq', 'Mentions') }}:</strong>
					<span>{{ comment.mentions.join(', ') }}</span>
				</div>
				<div class="comment-detail__field">
					<strong>{{ t('shillinq', 'Resolved') }}:</strong>
					<span>{{ comment.resolved ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</span>
				</div>
				<div v-if="comment.resolved" class="comment-detail__field">
					<strong>{{ t('shillinq', 'Resolved by') }}:</strong>
					<span>{{ comment.resolvedBy }} {{ t('shillinq', 'on') }} {{ formatDate(comment.resolvedAt) }}</span>
				</div>
				<div v-if="comment.editedAt" class="comment-detail__field">
					<strong>{{ t('shillinq', 'Edited at') }}:</strong>
					<span>{{ formatDate(comment.editedAt) }}</span>
				</div>
				<div class="comment-detail__actions">
					<NcButton v-if="isDpo" type="warning" @click="anonymise">
						{{ t('shillinq', 'Anonymise') }}
					</NcButton>
				</div>
			</CnConfigurationCard>
		</template>
		<NcEmptyContent v-else :name="t('shillinq', 'Comment not found')">
			<template #icon>
				<CommentTextOutline :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { CnConfigurationCard } from '@conduction/nextcloud-vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'CommentDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnConfigurationCard,
		CommentTextOutline,
	},
	data() {
		return {
			comment: null,
			loading: true,
		}
	},
	computed: {
		isDpo() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
	},
	async mounted() {
		await this.fetchComment()
	},
	methods: {
		async fetchComment() {
			this.loading = true
			try {
				const id = this.$route.params.commentId
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}`)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.comment = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch comment:', error)
			} finally {
				this.loading = false
			}
		},
		async anonymise() {
			try {
				const id = this.$route.params.commentId
				// Use the dedicated anonymise endpoint so the author field is also zeroed out.
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}/anonymise`)
				const response = await fetch(url, {
					method: 'PATCH',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.comment = await response.json()
				}
			} catch (error) {
				console.error('Failed to anonymise comment:', error)
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
	},
}
</script>

<style scoped>
.comment-detail-page {
	padding: 20px;
}

.comment-detail__field {
	margin-bottom: 12px;
}

.comment-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
