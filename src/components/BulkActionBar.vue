<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-8.1
-->
<template>
	<div v-if="selectedIds.length > 0" class="bulk-action-bar">
		<span class="bulk-action-bar__count">
			{{ t('shillinq', '{count} selected', { count: selectedIds.length }) }}
		</span>
		<div class="bulk-action-bar__actions">
			<NcButton @click="bulkApprove">
				{{ t('shillinq', 'Approve') }}
			</NcButton>
			<NcButton @click="bulkExport">
				{{ t('shillinq', 'Export CSV') }}
			</NcButton>
			<NcButton type="error" @click="confirmDelete">
				{{ t('shillinq', 'Delete') }}
			</NcButton>
		</div>

		<NcDialog
			v-if="showDeleteConfirm"
			:name="t('shillinq', 'Confirm Delete')"
			@close="showDeleteConfirm = false">
			<p>{{ t('shillinq', 'Are you sure you want to delete {count} items?', { count: selectedIds.length }) }}</p>
			<div class="bulk-action-bar__confirm-actions">
				<NcButton @click="showDeleteConfirm = false">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="bulkDelete">
					{{ t('shillinq', 'Delete') }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BulkActionBar',
	components: {
		NcButton,
		NcDialog,
	},
	props: {
		selectedIds: {
			type: Array,
			required: true,
		},
		schema: {
			type: String,
			required: true,
		},
	},
	emits: ['bulk-action'],
	data() {
		return {
			showDeleteConfirm: false,
		}
	},
	methods: {
		async bulkApprove() {
			try {
				await fetch(
					generateUrl(`/apps/shillinq/api/v1/bulk/${this.schema}/approve`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ids: this.selectedIds }),
					},
				)
				this.$emit('bulk-action')
			} catch (error) {
				console.error('Bulk approve failed:', error)
			}
		},
		confirmDelete() {
			this.showDeleteConfirm = true
		},
		async bulkDelete() {
			try {
				await fetch(
					generateUrl(`/apps/shillinq/api/v1/bulk/${this.schema}/delete`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ids: this.selectedIds }),
					},
				)
				this.showDeleteConfirm = false
				this.$emit('bulk-action')
			} catch (error) {
				console.error('Bulk delete failed:', error)
			}
		},
		bulkExport() {
			// Export selected IDs as CSV download.
			const csvContent = 'id\n' + this.selectedIds.join('\n')
			const blob = new Blob([csvContent], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `${this.schema}_export.csv`
			link.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.bulk-action-bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	margin-bottom: 12px;
}

.bulk-action-bar__count {
	font-weight: 600;
}

.bulk-action-bar__actions {
	display: flex;
	gap: 8px;
}

.bulk-action-bar__confirm-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
