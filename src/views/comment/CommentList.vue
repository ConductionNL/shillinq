<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-4.1
-->
<template>
	<div class="comment-list">
		<h2>{{ t('shillinq', 'Comments') }}</h2>
		<div class="comment-list__filters">
			<NcSelect
				v-model="filterTargetType"
				:options="targetTypeOptions"
				:placeholder="t('shillinq', 'Filter by target type')"
				@input="fetchComments" />
			<NcSelect
				v-model="filterResolved"
				:options="resolvedOptions"
				:placeholder="t('shillinq', 'Filter by status')"
				@input="fetchComments" />
		</div>
		<NcLoadingIcon v-if="loading" :size="44" />
		<table v-else class="comment-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Author') }}</th>
					<th>{{ t('shillinq', 'Target Type') }}</th>
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
					@click="goToDetail(comment.id)">
					<td>{{ comment.author }}</td>
					<td>{{ comment.targetType }}</td>
					<td>{{ comment.targetId }}</td>
					<td>{{ formatDate(comment.timestamp) }}</td>
					<td>{{ comment.resolved ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</td>
				</tr>
			</tbody>
		</table>
		<NcEmptyContent
			v-if="!loading && comments.length === 0"
			:name="t('shillinq', 'No comments found')">
			<template #icon>
				<CommentTextOutline :size="20" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import { useCommentStore } from '../../store/modules/comment.js'

export default {
	name: 'CommentList',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		CommentTextOutline,
	},
	data() {
		return {
			filterTargetType: null,
			filterResolved: null,
			targetTypeOptions: ['Invoice', 'PurchaseOrder', 'Contract', 'NegotiationThread'],
			resolvedOptions: [
				{ id: 'all', label: this.t('shillinq', 'All') },
				{ id: 'unresolved', label: this.t('shillinq', 'Unresolved') },
				{ id: 'resolved', label: this.t('shillinq', 'Resolved') },
			],
		}
	},
	computed: {
		commentStore() {
			return useCommentStore()
		},
		loading() {
			return this.commentStore.loading
		},
		comments() {
			return this.commentStore.comments
		},
	},
	mounted() {
		this.fetchComments()
	},
	methods: {
		async fetchComments() {
			const targetType = this.filterTargetType || ''
			const targetId = ''
			if (targetType) {
				await this.commentStore.fetchByTarget(targetType, targetId)
			}
		},
		goToDetail(id) {
			this.$router.push({ name: 'CommentDetail', params: { commentId: id } })
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
	},
}
</script>

<style scoped>
.comment-list {
	padding: 20px;
}

.comment-list__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.comment-list__table {
	width: 100%;
	border-collapse: collapse;
}

.comment-list__table th,
.comment-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.comment-list__row {
	cursor: pointer;
}

.comment-list__row:hover {
	background-color: var(--color-background-hover);
}
</style>
