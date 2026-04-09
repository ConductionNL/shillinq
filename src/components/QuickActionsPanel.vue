<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-9.1
-->
<template>
	<div
		class="quick-actions-panel"
		:class="{ 'quick-actions-panel--collapsed': collapsed }">
		<NcButton
			class="quick-actions-panel__toggle"
			type="tertiary"
			@click="togglePanel">
			{{ collapsed ? t('shillinq', 'Quick Actions') : t('shillinq', 'Close') }}
		</NcButton>

		<div
			v-if="!collapsed"
			class="quick-actions-panel__list">
			<NcButton
				v-for="action in actions"
				:key="action.id"
				class="quick-actions-panel__action"
				@click="executeAction(action)">
				{{ action.label }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'QuickActionsPanel',
	components: {
		NcButton,
	},
	props: {
		entityContext: {
			type: String,
			default: 'default',
		},
	},
	data() {
		return {
			collapsed: localStorage.getItem('shillinq-quick-actions-collapsed') === 'true',
			actions: [
				{ id: 'new-expense', label: t('shillinq', 'New Expense Claim'), route: 'ExpenseClaimList', action: 'new' },
				{ id: 'approve-selected', label: t('shillinq', 'Approve Selected'), action: 'approve' },
				{ id: 'export-csv', label: t('shillinq', 'Export CSV'), action: 'export' },
				{ id: 'view-analytics', label: t('shillinq', 'View Analytics'), route: 'AnalyticsDashboard' },
				{ id: 'settings', label: t('shillinq', 'Settings'), route: 'Settings' },
			],
		}
	},
	methods: {
		togglePanel() {
			this.collapsed = !this.collapsed
			localStorage.setItem('shillinq-quick-actions-collapsed', this.collapsed.toString())
		},
		executeAction(action) {
			if (action.route) {
				this.$router.push({ name: action.route })
			}
			this.$emit('action', action)
		},
	},
}
</script>

<style scoped>
.quick-actions-panel {
	position: fixed;
	bottom: 20px;
	right: 20px;
	z-index: 100;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
	padding: 8px;
	min-width: 200px;
}

.quick-actions-panel--collapsed {
	min-width: auto;
}

.quick-actions-panel__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.quick-actions-panel__action {
	justify-content: flex-start;
}
</style>
