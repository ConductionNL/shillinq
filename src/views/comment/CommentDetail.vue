<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-4.2
-->
<template>
	<div class="comment-detail">
		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else-if="comment">
			<h2>{{ t('shillinq', 'Comment Detail') }}</h2>
			<div class="comment-detail__card">
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
			</div>
			<div class="comment-detail__actions">
				<NcButton v-if="isDpo" type="warning" @click="anonymise">
					{{ t('shillinq', 'Anonymise') }}
				</NcButton>
			</div>
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
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'CommentDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
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
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${id}`)
				const response = await fetch(url, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						content: '[anonymised]',
						author: '[anonymised]',
						mentions: [],
					}),
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
.comment-detail {
	padding: 20px;
}

.comment-detail__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
}

.comment-detail__field {
	margin-bottom: 12px;
}

.comment-detail__actions {
	display: flex;
	gap: 8px;
}
</style>
