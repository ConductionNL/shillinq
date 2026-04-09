<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="comment-list">
		<header class="comment-list__header">
			<h2>{{ t('shillinq', 'Comments') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<CnIndexPage v-else>
			<template #default>
				<table class="comment-list__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Author') }}</th>
							<th>{{ t('shillinq', 'Target type') }}</th>
							<th>{{ t('shillinq', 'Target ID') }}</th>
							<th>{{ t('shillinq', 'Timestamp') }}</th>
							<th>{{ t('shillinq', 'Resolved') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="comment in comments"
							:key="comment.id"
							class="comment-list__row"
							@click="navigateToDetail(comment)">
							<td>{{ comment.author || comment.userId }}</td>
							<td>{{ comment.targetType }}</td>
							<td>{{ comment.targetId }}</td>
							<td>{{ formatTimestamp(comment.createdAt || comment.timestamp) }}</td>
							<td>
								<CheckCircleOutline v-if="comment.resolved" :size="20" class="comment-list__icon--resolved" />
								<CloseCircleOutline v-else :size="20" class="comment-list__icon--unresolved" />
							</td>
						</tr>
					</tbody>
				</table>

				<NcEmptyContent
					v-if="!loading && comments.length === 0"
					:name="t('shillinq', 'No comments yet')"
					:description="t('shillinq', 'Comments will appear here once they are added to items.')">
					<template #icon>
						<CommentOutline :size="64" />
					</template>
				</NcEmptyContent>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useCommentStore } from '../../store/modules/comment.js'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'

export default {
	name: 'CommentList',
	components: {
		CnIndexPage,
		NcLoadingIcon,
		NcEmptyContent,
		CheckCircleOutline,
		CloseCircleOutline,
		CommentOutline,
	},
	data() {
		return {
			commentStore: useCommentStore(),
		}
	},
	computed: {
		comments() {
			return this.commentStore.comments
		},
		loading() {
			return this.commentStore.loading
		},
	},
	mounted() {
		this.commentStore.fetchComments('', '')
	},
	methods: {
		navigateToDetail(comment) {
			this.$router.push({ name: 'CommentDetail', params: { id: comment.id } })
		},
		formatTimestamp(ts) {
			if (!ts) return ''
			const date = new Date(ts)
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.comment-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.comment-list__header {
	margin-bottom: 20px;
}

.comment-list__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.comment-list__table {
	width: 100%;
	border-collapse: collapse;
}

.comment-list__table th {
	text-align: left;
	padding: 8px 12px;
	font-weight: 600;
	border-bottom: 2px solid var(--color-border);
}

.comment-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.comment-list__row {
	cursor: pointer;
}

.comment-list__row:hover {
	background-color: var(--color-background-hover);
}

.comment-list__icon--resolved {
	color: var(--color-success);
}

.comment-list__icon--unresolved {
	color: var(--color-warning);
}
</style>
