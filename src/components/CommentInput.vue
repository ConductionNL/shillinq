<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-6.3
-->
<template>
	<div class="comment-input">
		<textarea
			v-model="content"
			:placeholder="t('shillinq', 'Write a comment...')"
			class="comment-input__textarea"
			@input="onInput"
			@keydown.ctrl.enter="submit" />
		<MentionAutocomplete
			v-if="mentionQuery"
			:query="mentionQuery"
			@select="insertMention" />
		<NcButton type="primary" :disabled="!content.trim()" @click="submit">
			{{ t('shillinq', 'Send') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import MentionAutocomplete from './MentionAutocomplete.vue'
import { useCommentStore } from '../store/modules/comment.js'

export default {
	name: 'CommentInput',
	components: {
		NcButton,
		MentionAutocomplete,
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
	emits: ['submit'],
	data() {
		return {
			content: '',
			mentionQuery: '',
		}
	},
	methods: {
		onInput() {
			const match = this.content.match(/@([a-zA-Z0-9_\-.]*)$/)
			this.mentionQuery = match && match[1].length >= 1 ? match[1] : ''
		},
		insertMention(username) {
			this.content = this.content.replace(/@[a-zA-Z0-9_\-.]*$/, `@${username} `)
			this.mentionQuery = ''
		},
		async submit() {
			if (!this.content.trim()) return

			const commentStore = useCommentStore()
			const result = await commentStore.createComment({
				content: this.content,
				targetType: this.targetType,
				targetId: this.targetId,
			})

			if (result) {
				this.content = ''
				this.$emit('submit', result)
			}
		},
	},
}
</script>

<style scoped>
.comment-input {
	padding: 8px 0;
	position: relative;
}

.comment-input__textarea {
	width: 100%;
	min-height: 60px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
	margin-bottom: 8px;
}
</style>
