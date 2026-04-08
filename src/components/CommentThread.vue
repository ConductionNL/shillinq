<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.2
-->
<template>
	<div class="comment-thread">
		<div
			v-for="comment in sortedComments"
			:key="comment.id"
			:class="['comment-thread__item', { 'comment-thread__item--resolved': comment.resolved }]">
			<template v-if="!comment.resolved || expandedResolved[comment.id]">
				<div class="comment-thread__header">
					<NcAvatar :user="comment.author" :size="28" />
					<span class="comment-thread__author">{{ comment.author }}</span>
					<span class="comment-thread__time">{{ relativeTime(comment.timestamp) }}</span>
					<span v-if="comment.editedAt" class="comment-thread__edited">
						({{ t('shillinq', 'edited') }})
					</span>
				</div>
				<div class="comment-thread__content" v-html="renderMentions(comment.content)" />
				<div class="comment-thread__actions">
					<NcButton
						v-if="!comment.resolved"
						type="tertiary"
						@click="$emit('resolve', comment.id)">
						{{ t('shillinq', 'Resolve') }}
					</NcButton>
				</div>
			</template>
			<div
				v-if="comment.resolved && !expandedResolved[comment.id]"
				class="comment-thread__collapsed"
				@click="toggleResolved(comment.id)">
				<span>{{ t('shillinq', 'Resolved by') }} {{ comment.resolvedBy }}</span>
				<NcButton type="tertiary">
					{{ t('shillinq', 'Show') }}
				</NcButton>
			</div>
			<div
				v-if="comment.resolved && expandedResolved[comment.id]"
				class="comment-thread__hide"
				@click="toggleResolved(comment.id)">
				<NcButton type="tertiary">
					{{ t('shillinq', 'Hide') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcAvatar, NcButton } from '@nextcloud/vue'

export default {
	name: 'CommentThread',
	components: {
		NcAvatar,
		NcButton,
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
		comments: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['resolve'],
	data() {
		return {
			expandedResolved: {},
		}
	},
	computed: {
		sortedComments() {
			return [...this.comments].sort(
				(a, b) => new Date(a.timestamp) - new Date(b.timestamp),
			)
		},
	},
	methods: {
		toggleResolved(id) {
			this.$set(this.expandedResolved, id, !this.expandedResolved[id])
		},
		relativeTime(timestamp) {
			if (!timestamp) return ''
			const diff = Date.now() - new Date(timestamp).getTime()
			const minutes = Math.floor(diff / 60000)
			if (minutes < 1) return this.t('shillinq', 'just now')
			if (minutes < 60) return minutes + 'm'
			const hours = Math.floor(minutes / 60)
			if (hours < 24) return hours + 'h'
			const days = Math.floor(hours / 24)
			return days + 'd'
		},
		renderMentions(content) {
			if (!content) return ''
			return content.replace(
				/@([a-zA-Z0-9_\-.]+)/g,
				'<span class="mention-chip">@$1</span>',
			)
		},
	},
}
</script>

<style scoped>
.comment-thread__item {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.comment-thread__item--resolved {
	opacity: 0.7;
}

.comment-thread__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.comment-thread__author {
	font-weight: 600;
}

.comment-thread__time {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.comment-thread__edited {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-style: italic;
}

.comment-thread__content {
	padding-left: 36px;
}

.comment-thread__actions {
	padding-left: 36px;
	margin-top: 4px;
}

.comment-thread__collapsed {
	display: flex;
	align-items: center;
	justify-content: space-between;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>

<style>
.mention-chip {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
	padding: 1px 4px;
	border-radius: 4px;
	font-weight: 600;
}
</style>
