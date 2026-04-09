<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="comment-thread">
		<div v-if="unresolvedComments.length === 0 && resolvedComments.length === 0" class="comment-thread__empty">
			<p>{{ t('shillinq', 'No comments yet. Be the first to comment.') }}</p>
		</div>

		<!-- Unresolved comments shown chronologically -->
		<div
			v-for="comment in unresolvedComments"
			:key="comment.id"
			class="comment-thread__item">
			<div class="comment-thread__avatar">
				<NcAvatar
					:user="comment.author || comment.userId"
					:display-name="comment.author || comment.userId"
					:size="32" />
			</div>
			<div class="comment-thread__body">
				<div class="comment-thread__meta">
					<strong class="comment-thread__author">{{ comment.author || comment.userId }}</strong>
					<span class="comment-thread__time">{{ relativeTime(comment.createdAt || comment.timestamp) }}</span>
				</div>
				<div class="comment-thread__content" v-html="highlightMentions(comment.content || comment.body)" />
				<div class="comment-thread__actions">
					<NcButton
						type="tertiary"
						:aria-label="t('shillinq', 'Resolve comment')"
						@click="$emit('resolve', comment.id)">
						<template #icon>
							<CheckOutline :size="18" />
						</template>
						{{ t('shillinq', 'Resolve') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Resolved comments shown collapsed -->
		<div v-if="resolvedComments.length > 0" class="comment-thread__resolved-section">
			<NcButton
				type="tertiary"
				class="comment-thread__toggle"
				@click="showResolved = !showResolved">
				<template #icon>
					<ChevronDown v-if="!showResolved" :size="20" />
					<ChevronUp v-else :size="20" />
				</template>
				{{ showResolved
					? t('shillinq', 'Hide resolved ({count})', { count: resolvedComments.length })
					: t('shillinq', 'Show resolved ({count})', { count: resolvedComments.length })
				}}
			</NcButton>

			<template v-if="showResolved">
				<div
					v-for="comment in resolvedComments"
					:key="comment.id"
					class="comment-thread__item comment-thread__item--resolved">
					<div class="comment-thread__avatar">
						<NcAvatar
							:user="comment.author || comment.userId"
							:display-name="comment.author || comment.userId"
							:size="32" />
					</div>
					<div class="comment-thread__body">
						<div class="comment-thread__meta">
							<strong class="comment-thread__author">{{ comment.author || comment.userId }}</strong>
							<span class="comment-thread__time">{{ relativeTime(comment.createdAt || comment.timestamp) }}</span>
							<CheckCircleOutline :size="16" class="comment-thread__resolved-icon" />
						</div>
						<div class="comment-thread__content" v-html="highlightMentions(comment.content || comment.body)" />
					</div>
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import { NcAvatar, NcButton } from '@nextcloud/vue'
import CheckOutline from 'vue-material-design-icons/CheckOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'

export default {
	name: 'CommentThread',
	components: {
		NcAvatar,
		NcButton,
		CheckOutline,
		CheckCircleOutline,
		ChevronDown,
		ChevronUp,
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
			showResolved: false,
		}
	},
	computed: {
		unresolvedComments() {
			return this.comments
				.filter(c => !c.resolved)
				.sort((a, b) => new Date(a.createdAt || a.timestamp) - new Date(b.createdAt || b.timestamp))
		},
		resolvedComments() {
			return this.comments
				.filter(c => c.resolved)
				.sort((a, b) => new Date(a.createdAt || a.timestamp) - new Date(b.createdAt || b.timestamp))
		},
	},
	methods: {
		relativeTime(ts) {
			if (!ts) return ''
			const now = new Date()
			const date = new Date(ts)
			const diffMs = now - date
			const diffSec = Math.floor(diffMs / 1000)
			const diffMin = Math.floor(diffSec / 60)
			const diffHour = Math.floor(diffMin / 60)
			const diffDay = Math.floor(diffHour / 24)

			if (diffSec < 60) return t('shillinq', 'just now')
			if (diffMin < 60) return t('shillinq', '{count} min ago', { count: diffMin })
			if (diffHour < 24) return t('shillinq', '{count} hr ago', { count: diffHour })
			if (diffDay < 30) return t('shillinq', '{count} days ago', { count: diffDay })
			return date.toLocaleDateString()
		},
		highlightMentions(text) {
			if (!text) return ''
			// Escape HTML first for safety
			const escaped = text
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
			// Highlight @mentions
			return escaped.replace(/@(\w+)/g, '<span class="comment-thread__mention">@$1</span>')
		},
	},
}
</script>

<style scoped>
.comment-thread__empty {
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.comment-thread__empty p {
	margin: 0;
}

.comment-thread__item {
	display: flex;
	gap: 10px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border-dark, var(--color-border));
}

.comment-thread__item:last-child {
	border-bottom: none;
}

.comment-thread__item--resolved {
	opacity: 0.65;
}

.comment-thread__avatar {
	flex-shrink: 0;
}

.comment-thread__body {
	flex: 1;
	min-width: 0;
}

.comment-thread__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.comment-thread__author {
	font-weight: 600;
	font-size: 13px;
}

.comment-thread__time {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.comment-thread__resolved-icon {
	color: var(--color-success);
}

.comment-thread__content {
	line-height: 1.5;
	word-break: break-word;
}

.comment-thread__actions {
	margin-top: 4px;
}

.comment-thread__resolved-section {
	margin-top: 8px;
}

.comment-thread__toggle {
	margin-bottom: 4px;
}
</style>

<style>
/* Unscoped so v-html mention spans pick it up */
.comment-thread__mention {
	background-color: var(--color-primary-element-light, #e8f0fe);
	color: var(--color-primary-element, #0082c9);
	border-radius: 4px;
	padding: 1px 4px;
	font-weight: 600;
}
</style>
