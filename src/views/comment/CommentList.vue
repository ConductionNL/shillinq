<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-4.1
-->
<template>
	<CnIndexPage
		:title="t('shillinq', 'Comments')"
		:objects="filteredComments"
		:columns="columns"
		:loading="loading"
		:selectable="false"
		:show-mass-import="false"
		:show-mass-export="false"
		:show-mass-copy="false"
		:show-mass-delete="false"
		:show-form-dialog="false"
		:empty-text="emptyText"
		row-key="id"
		@row-click="goToDetail">
		<template #action-items>
			<NcSelect
				v-model="filterTargetType"
				:options="targetTypeOptions"
				:placeholder="t('shillinq', 'Filter by target type')"
				@input="fetchComments" />
			<NcSelect
				v-model="filterResolved"
				:options="resolvedOptions"
				:placeholder="t('shillinq', 'Filter by status')"
				@input="applyFilter" />
		</template>
	</CnIndexPage>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useCommentStore } from '../../store/modules/comment.js'

export default {
	name: 'CommentList',
	components: {
		CnIndexPage,
		NcSelect,
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
			columns: [
				{ key: 'author', label: this.t('shillinq', 'Author') },
				{ key: 'targetType', label: this.t('shillinq', 'Target Type') },
				{ key: 'targetId', label: this.t('shillinq', 'Target ID') },
				{ key: 'timestamp', label: this.t('shillinq', 'Timestamp') },
				{ key: 'resolved', label: this.t('shillinq', 'Resolved') },
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
		filteredComments() {
			let comments = this.commentStore.comments
			if (this.filterResolved && this.filterResolved.id !== 'all') {
				const wantResolved = this.filterResolved.id === 'resolved'
				comments = comments.filter((c) => Boolean(c.resolved) === wantResolved)
			}
			return comments
		},
		emptyText() {
			if (!this.filterTargetType) {
				return this.t('shillinq', 'Select a target type to load comments')
			}
			return this.t('shillinq', 'No comments found')
		},
	},
	methods: {
		async fetchComments() {
			const targetType = this.filterTargetType || ''
			if (targetType) {
				// targetId is omitted — load all comments for the type (admin view)
				await this.commentStore.fetchByTarget(targetType, '')
			}
		},
		applyFilter() {
			// filtering is done client-side via filteredComments computed
		},
		goToDetail(comment) {
			this.$router.push({ name: 'CommentDetail', params: { commentId: comment.id } })
		},
	},
}
</script>
