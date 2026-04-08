<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-6.1
-->
<template>
	<div class="automation-rule-list">
		<header class="automation-rule-list__header">
			<h2>{{ t('shillinq', 'Automation Rules') }}</h2>
			<NcButton type="primary" @click="showForm = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'Add Rule') }}
			</NcButton>
		</header>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'AutomationRule'"
			@bulk-action="handleBulkAction" />

		<NcLoadingIcon v-if="loading" />

		<table v-else class="automation-rule-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleAll">
					</th>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Trigger Schema') }}</th>
					<th>{{ t('shillinq', 'Trigger Field') }}</th>
					<th>{{ t('shillinq', 'Action Type') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
					<th>{{ t('shillinq', 'Matches') }}</th>
					<th>{{ t('shillinq', 'Last Evaluated') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="rule in rules"
					:key="rule.id"
					:class="{ 'automation-rule-list__row--inactive': !rule.isActive }"
					class="automation-rule-list__row"
					@click="$router.push({ name: 'AutomationRuleDetail', params: { ruleId: rule.id } })">
					<td @click.stop>
						<input
							type="checkbox"
							:checked="selectedIds.includes(rule.id)"
							@change="toggleSelect(rule.id)">
					</td>
					<td>{{ rule.name }}</td>
					<td>{{ rule.triggerSchema }}</td>
					<td>{{ rule.triggerField }}</td>
					<td>{{ rule.actionType }}</td>
					<td>
						<span :class="rule.isActive ? 'badge--active' : 'badge--inactive'">
							{{ rule.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}
						</span>
					</td>
					<td>{{ rule.matchCount || 0 }}</td>
					<td>{{ rule.lastEvaluatedAt || t('shillinq', 'Never') }}</td>
				</tr>
			</tbody>
		</table>

		<AutomationRuleForm
			v-if="showForm"
			@close="showForm = false"
			@saved="onRuleSaved" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import AutomationRuleForm from './AutomationRuleForm.vue'
import BulkActionBar from '../../components/BulkActionBar.vue'
import { useAutomationRuleStore } from '../../store/modules/automationRule.js'

export default {
	name: 'AutomationRuleList',
	components: {
		AutomationRuleForm,
		BulkActionBar,
		NcButton,
		NcLoadingIcon,
		PlusIcon,
	},
	data() {
		return {
			showForm: false,
			selectedIds: [],
		}
	},
	computed: {
		ruleStore() {
			return useAutomationRuleStore()
		},
		rules() {
			return this.ruleStore.rules
		},
		loading() {
			return this.ruleStore.ruleLoading
		},
		allSelected() {
			return this.rules.length > 0 && this.selectedIds.length === this.rules.length
		},
	},
	created() {
		this.ruleStore.fetchRules()
	},
	methods: {
		toggleSelect(id) {
			const idx = this.selectedIds.indexOf(id)
			if (idx >= 0) {
				this.selectedIds.splice(idx, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		toggleAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.rules.map((r) => r.id)
			}
		},
		onRuleSaved() {
			this.showForm = false
			this.ruleStore.fetchRules()
		},
		handleBulkAction() {
			this.selectedIds = []
			this.ruleStore.fetchRules()
		},
	},
}
</script>

<style scoped>
.automation-rule-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.automation-rule-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.automation-rule-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.automation-rule-list__table {
	width: 100%;
	border-collapse: collapse;
}

.automation-rule-list__table th,
.automation-rule-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.automation-rule-list__row {
	cursor: pointer;
}

.automation-rule-list__row:hover {
	background: var(--color-background-hover);
}

.automation-rule-list__row--inactive {
	opacity: 0.5;
}

.badge--active {
	color: var(--color-success);
	font-weight: 600;
}

.badge--inactive {
	color: var(--color-text-maxcontrast);
}
</style>
