<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-6.1
-->
<template>
	<div class="automation-rule-list">
		<div class="automation-rule-list__header">
			<h2>{{ t('shillinq', 'Automation Rules') }}</h2>
			<NcButton
				type="primary"
				@click="showForm = true">
				{{ t('shillinq', 'New Rule') }}
			</NcButton>
		</div>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'AutomationRule'"
			@bulk-action="onBulkAction" />

		<table class="automation-rule-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleSelectAll">
					</th>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Trigger Schema') }}</th>
					<th>{{ t('shillinq', 'Trigger Field') }}</th>
					<th>{{ t('shillinq', 'Action Type') }}</th>
					<th>{{ t('shillinq', 'Active') }}</th>
					<th>{{ t('shillinq', 'Match Count') }}</th>
					<th>{{ t('shillinq', 'Last Evaluated') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="rule in ruleStore.automationRules"
					:key="rule.id"
					:class="{ 'rule--inactive': !rule.isActive }"
					@click="openRule(rule)">
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
						<span :class="rule.isActive ? 'status--active' : 'status--inactive'">
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
import { NcButton } from '@nextcloud/vue'
import { useAutomationRuleStore } from '../../store/modules/automationRule.js'
import BulkActionBar from '../../components/BulkActionBar.vue'
import AutomationRuleForm from './AutomationRuleForm.vue'

export default {
	name: 'AutomationRuleList',
	components: {
		NcButton,
		BulkActionBar,
		AutomationRuleForm,
	},
	data() {
		return {
			ruleStore: useAutomationRuleStore(),
			selectedIds: [],
			showForm: false,
		}
	},
	computed: {
		allSelected() {
			return this.ruleStore.automationRules.length > 0
				&& this.selectedIds.length === this.ruleStore.automationRules.length
		},
	},
	mounted() {
		this.ruleStore.fetchRules()
	},
	methods: {
		openRule(rule) {
			this.$router.push({ name: 'AutomationRuleDetail', params: { ruleId: rule.id } })
		},
		toggleSelectAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.ruleStore.automationRules.map((r) => r.id)
			}
		},
		toggleSelect(id) {
			const index = this.selectedIds.indexOf(id)
			if (index >= 0) {
				this.selectedIds.splice(index, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		onBulkAction() {
			this.selectedIds = []
			this.ruleStore.fetchRules()
		},
		onRuleSaved() {
			this.showForm = false
			this.ruleStore.fetchRules()
		},
	},
}
</script>

<style scoped>
.automation-rule-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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

.automation-rule-list__table tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}

.rule--inactive {
	opacity: 0.5;
}

.status--active {
	color: var(--color-success);
}

.status--inactive {
	color: var(--color-error);
}
</style>
