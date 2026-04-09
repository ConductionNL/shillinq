<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="comment-detail">
		<header class="comment-detail__header">
			<NcButton type="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('shillinq', 'Back to comments') }}
			</NcButton>
			<h2>{{ t('shillinq', 'Comment detail') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<div v-else-if="comment" class="comment-detail__content">
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Author') }}</label>
				<span>{{ comment.author || comment.userId }}</span>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Target type') }}</label>
				<span>{{ comment.targetType }}</span>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Target ID') }}</label>
				<span>{{ comment.targetId }}</span>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Content') }}</label>
				<p class="comment-detail__body">
					{{ comment.content || comment.body }}
				</p>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Mentions') }}</label>
				<span v-if="comment.mentions && comment.mentions.length">
					{{ comment.mentions.join(', ') }}
				</span>
				<span v-else class="comment-detail__empty">{{ t('shillinq', 'None') }}</span>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Created at') }}</label>
				<span>{{ formatTimestamp(comment.createdAt || comment.timestamp) }}</span>
			</div>
			<div class="comment-detail__field">
				<label>{{ t('shillinq', 'Resolved') }}</label>
				<span>{{ comment.resolved ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</span>
			</div>
			<div v-if="comment.resolvedBy" class="comment-detail__field">
				<label>{{ t('shillinq', 'Resolved by') }}</label>
				<span>{{ comment.resolvedBy }}</span>
			</div>
			<div v-if="comment.resolvedAt" class="comment-detail__field">
				<label>{{ t('shillinq', 'Resolved at') }}</label>
				<span>{{ formatTimestamp(comment.resolvedAt) }}</span>
			</div>

			<div class="comment-detail__actions">
				<NcButton
					v-if="!comment.resolved"
					type="primary"
					@click="resolveComment">
					<template #icon>
						<CheckCircleOutline :size="20" />
					</template>
					{{ t('shillinq', 'Resolve') }}
				</NcButton>

				<NcButton
					type="warning"
					@click="anonymiseComment">
					<template #icon>
						<AccountOffOutline :size="20" />
					</template>
					{{ t('shillinq', 'Anonymise') }}
				</NcButton>

				<NcButton
					type="error"
					@click="deleteComment">
					<template #icon>
						<DeleteOutline :size="20" />
					</template>
					{{ t('shillinq', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<NcEmptyContent
			v-else
			:name="t('shillinq', 'Comment not found')"
			:description="t('shillinq', 'The requested comment could not be found.')">
			<template #icon>
				<CommentOutline :size="64" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { useCommentStore } from '../../store/modules/comment.js'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import AccountOffOutline from 'vue-material-design-icons/AccountOffOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'

export default {
	name: 'CommentDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		ArrowLeft,
		CheckCircleOutline,
		AccountOffOutline,
		DeleteOutline,
		CommentOutline,
	},
	data() {
		return {
			commentStore: useCommentStore(),
		}
	},
	computed: {
		loading() {
			return this.commentStore.loading
		},
		comment() {
			const id = this.$route.params.id
			return this.commentStore.comments.find(c => c.id === id || c.id === parseInt(id, 10))
		},
	},
	methods: {
		goBack() {
			this.$router.push({ name: 'CommentList' })
		},
		formatTimestamp(ts) {
			if (!ts) return ''
			const date = new Date(ts)
			return date.toLocaleString()
		},
		async resolveComment() {
			if (this.comment) {
				await this.commentStore.resolveComment(this.comment.id)
			}
		},
		async anonymiseComment() {
			if (!this.comment) return
			// Anonymise by updating the comment author to anonymous
			try {
				const { generateUrl } = await import('@nextcloud/router')
				const url = generateUrl(`/apps/shillinq/api/v1/comments/${this.comment.id}/anonymise`)
				const response = await fetch(url, {
					method: 'PATCH',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					const idx = this.commentStore.comments.findIndex(c => c.id === this.comment.id)
					if (idx !== -1) this.commentStore.comments.splice(idx, 1, data)
				}
			} catch (error) {
				console.error('Failed to anonymise comment:', error)
			}
		},
		async deleteComment() {
			if (this.comment) {
				await this.commentStore.deleteComment(this.comment.id)
				this.goBack()
			}
		},
	},
}
</script>

<style scoped>
.comment-detail {
	padding: 8px 4px 24px;
	max-width: 800px;
}

.comment-detail__header {
	margin-bottom: 20px;
}

.comment-detail__header h2 {
	margin: 8px 0;
	font-size: 22px;
	font-weight: 600;
}

.comment-detail__field {
	margin-bottom: 16px;
}

.comment-detail__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-transform: uppercase;
}

.comment-detail__body {
	margin: 0;
	line-height: 1.5;
	white-space: pre-wrap;
}

.comment-detail__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.comment-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
