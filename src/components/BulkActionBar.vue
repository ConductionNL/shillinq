<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-8.1
-->
<template>
	<div
		v-if="selectedIds.length > 0"
		class="bulk-action-bar">
		<span class="bulk-action-bar__count">
			{{ t('shillinq', '{count} selected', { count: selectedIds.length }) }}
		</span>
		<div class="bulk-action-bar__actions">
			<NcButton
				type="primary"
				@click="bulkApprove">
				{{ t('shillinq', 'Approve') }}
			</NcButton>
			<NcButton @click="bulkExport">
				{{ t('shillinq', 'Export CSV') }}
			</NcButton>
			<NcButton
				type="error"
				@click="confirmDelete">
				{{ t('shillinq', 'Delete') }}
			</NcButton>
		</div>

		<NcDialog
			v-if="showDeleteConfirm"
			:name="t('shillinq', 'Confirm Delete')"
			@close="showDeleteConfirm = false">
			<p>{{ t('shillinq', 'Are you sure you want to delete {count} items?', { count: selectedIds.length }) }}</p>
			<template #actions>
				<NcButton @click="showDeleteConfirm = false">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="error"
					@click="bulkDelete">
					{{ t('shillinq', 'Delete') }}
				</NcButton>
			</template>
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
	data() {
		return {
			showDeleteConfirm: false,
		}
	},
	methods: {
		async bulkApprove() {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/bulk/${this.schema}/approve`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids: this.selectedIds }),
				})
				this.$emit('bulk-action', 'approve')
			} catch (error) {
				console.error('Bulk approve failed:', error)
			}
		},
		bulkExport() {
			const csvContent = this.selectedIds.join('\n')
			const blob = new Blob([csvContent], { type: 'text/csv' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `${this.schema}-export.csv`
			link.click()
			this.$emit('bulk-action', 'export')
		},
		confirmDelete() {
			this.showDeleteConfirm = true
		},
		async bulkDelete() {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/bulk/${this.schema}/delete`)
				await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids: this.selectedIds }),
				})
				this.showDeleteConfirm = false
				this.$emit('bulk-action', 'delete')
			} catch (error) {
				console.error('Bulk delete failed:', error)
			}
		},
	},
}
</script>

<style scoped>
.bulk-action-bar {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 8px 16px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	margin-bottom: 12px;
}

.bulk-action-bar__count {
	font-weight: bold;
}

.bulk-action-bar__actions {
	display: flex;
	gap: 8px;
}
</style>
