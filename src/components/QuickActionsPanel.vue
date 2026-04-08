<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-9.1
-->
<template>
	<div class="quick-actions-panel" :class="{ 'quick-actions-panel--collapsed': collapsed }">
		<NcButton
			class="quick-actions-panel__toggle"
			type="tertiary"
			@click="toggle">
			<template #icon>
				<ChevronLeftIcon v-if="!collapsed" :size="20" />
				<ChevronRightIcon v-else :size="20" />
			</template>
			{{ collapsed ? '' : t('shillinq', 'Quick Actions') }}
		</NcButton>

		<div v-if="!collapsed" class="quick-actions-panel__actions">
			<NcButton
				v-for="action in actions"
				:key="action.key"
				type="tertiary"
				@click="executeAction(action)">
				{{ t('shillinq', action.label) }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'QuickActionsPanel',
	components: {
		NcButton,
		ChevronLeftIcon,
		ChevronRightIcon,
	},
	data() {
		return {
			collapsed: localStorage.getItem('shillinq_quick_actions_collapsed') === 'true',
			actions: [
				{ key: 'new_expense', label: 'New Expense Claim', route: 'ExpenseClaimList', action: 'openForm' },
				{ key: 'analytics', label: 'View Analytics', route: 'AnalyticsDashboard' },
				{ key: 'automation', label: 'Automation Rules', route: 'AutomationRuleList' },
				{ key: 'portal', label: 'Portal Tokens', route: 'PortalTokenList' },
				{ key: 'settings', label: 'Settings', route: 'Settings' },
			],
		}
	},
	methods: {
		toggle() {
			this.collapsed = !this.collapsed
			localStorage.setItem('shillinq_quick_actions_collapsed', String(this.collapsed))
		},
		executeAction(action) {
			if (action.route) {
				this.$router.push({ name: action.route })
			}
		},
	},
}
</script>

<style scoped>
.quick-actions-panel {
	position: fixed;
	right: 16px;
	bottom: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	z-index: 1000;
	min-width: 200px;
}

.quick-actions-panel--collapsed {
	min-width: auto;
}

.quick-actions-panel__toggle {
	width: 100%;
	justify-content: flex-start;
}

.quick-actions-panel__actions {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-top: 4px;
}
</style>
