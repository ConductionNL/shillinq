<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="comment-input">
		<div class="comment-input__wrapper">
			<textarea
				ref="textarea"
				v-model="content"
				class="comment-input__textarea"
				:placeholder="t('shillinq', 'Write a comment... Use @ to mention someone')"
				rows="3"
				@input="onInput"
				@keydown="onKeydown" />

			<MentionAutocomplete
				v-if="showMentions"
				:query="mentionQuery"
				class="comment-input__mentions"
				@select="onMentionSelect" />
		</div>

		<div class="comment-input__footer">
			<span class="comment-input__hint">
				{{ t('shillinq', 'Press Ctrl+Enter to submit') }}
			</span>
			<NcButton
				type="primary"
				:disabled="!content.trim()"
				@click="submit">
				<template #icon>
					<SendOutline :size="20" />
				</template>
				{{ t('shillinq', 'Comment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import MentionAutocomplete from './MentionAutocomplete.vue'
import SendOutline from 'vue-material-design-icons/SendOutline.vue'

export default {
	name: 'CommentInput',
	components: {
		NcButton,
		MentionAutocomplete,
		SendOutline,
	},
	emits: ['submit'],
	data() {
		return {
			content: '',
			mentions: [],
			showMentions: false,
			mentionQuery: '',
			mentionStartPos: -1,
		}
	},
	methods: {
		onInput() {
			const textarea = this.$refs.textarea
			const cursorPos = textarea.selectionStart
			const textBeforeCursor = this.content.substring(0, cursorPos)

			// Check if we are in a @mention context
			const mentionMatch = textBeforeCursor.match(/@(\w*)$/)
			if (mentionMatch) {
				this.showMentions = true
				this.mentionQuery = mentionMatch[1]
				this.mentionStartPos = cursorPos - mentionMatch[0].length
			} else {
				this.showMentions = false
				this.mentionQuery = ''
				this.mentionStartPos = -1
			}
		},
		onKeydown(event) {
			// Ctrl+Enter to submit
			if (event.ctrlKey && event.key === 'Enter') {
				event.preventDefault()
				this.submit()
				return
			}
			// Escape closes mention dropdown
			if (event.key === 'Escape' && this.showMentions) {
				this.showMentions = false
			}
		},
		onMentionSelect(username) {
			// Replace the @query with the selected username
			const before = this.content.substring(0, this.mentionStartPos)
			const after = this.content.substring(this.$refs.textarea.selectionStart)
			this.content = before + '@' + username + ' ' + after

			// Track the mention
			if (!this.mentions.includes(username)) {
				this.mentions.push(username)
			}

			this.showMentions = false
			this.mentionQuery = ''
			this.mentionStartPos = -1

			// Re-focus the textarea
			this.$nextTick(() => {
				const textarea = this.$refs.textarea
				const newPos = before.length + username.length + 2 // +2 for @ and space
				textarea.focus()
				textarea.setSelectionRange(newPos, newPos)
			})
		},
		submit() {
			const trimmed = this.content.trim()
			if (!trimmed) return

			// Extract mentions from final content
			const mentionsInContent = []
			const mentionRegex = /@(\w+)/g
			let match
			while ((match = mentionRegex.exec(trimmed)) !== null) {
				if (!mentionsInContent.includes(match[1])) {
					mentionsInContent.push(match[1])
				}
			}

			this.$emit('submit', {
				content: trimmed,
				mentions: mentionsInContent,
			})

			// Reset
			this.content = ''
			this.mentions = []
			this.showMentions = false
		},
	},
}
</script>

<style scoped>
.comment-input {
	margin-top: 12px;
}

.comment-input__wrapper {
	position: relative;
}

.comment-input__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
	min-height: 60px;
	font-family: inherit;
	font-size: 14px;
	line-height: 1.5;
}

.comment-input__textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.comment-input__mentions {
	position: absolute;
	bottom: 100%;
	left: 0;
	z-index: 100;
}

.comment-input__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 8px;
}

.comment-input__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
