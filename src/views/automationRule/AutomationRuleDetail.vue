<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-6.1
-->
<template>
	<div class="automation-rule-detail">
		<div class="automation-rule-detail__header">
			<h2>{{ rule.name || t('shillinq', 'Automation Rule') }}</h2>
			<NcButton @click="testRule">
				{{ t('shillinq', 'Test Rule') }}
			</NcButton>
		</div>

		<div class="automation-rule-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Trigger Schema') }}</dt>
				<dd>{{ rule.triggerSchema }}</dd>
				<dt>{{ t('shillinq', 'Trigger Field') }}</dt>
				<dd>{{ rule.triggerField }}</dd>
				<dt>{{ t('shillinq', 'Operator') }}</dt>
				<dd>{{ rule.triggerOperator }}</dd>
				<dt>{{ t('shillinq', 'Value') }}</dt>
				<dd>{{ rule.triggerValue }}</dd>
				<dt>{{ t('shillinq', 'Action Type') }}</dt>
				<dd>{{ rule.actionType }}</dd>
				<dt>{{ t('shillinq', 'Action Parameters') }}</dt>
				<dd>{{ rule.actionParams || t('shillinq', 'None') }}</dd>
				<dt>{{ t('shillinq', 'Active') }}</dt>
				<dd>{{ rule.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>
				<dt>{{ t('shillinq', 'Match Count') }}</dt>
				<dd>{{ rule.matchCount || 0 }}</dd>
				<dt>{{ t('shillinq', 'Last Evaluated') }}</dt>
				<dd>{{ rule.lastEvaluatedAt || t('shillinq', 'Never') }}</dd>
			</dl>
		</div>

		<div
			v-if="testResults"
			class="automation-rule-detail__test-results">
			<h3>{{ t('shillinq', 'Test Results') }}</h3>
			<p>{{ t('shillinq', '{count} objects would match', { count: testResults.matchCount }) }}</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useAutomationRuleStore } from '../../store/modules/automationRule.js'

export default {
	name: 'AutomationRuleDetail',
	components: {
		NcButton,
	},
	data() {
		return {
			ruleStore: useAutomationRuleStore(),
			testResults: null,
		}
	},
	computed: {
		rule() {
			const ruleId = this.$route.params.ruleId
			return this.ruleStore.automationRules.find((r) => r.id === ruleId) || {}
		},
	},
	mounted() {
		if (this.ruleStore.automationRules.length === 0) {
			this.ruleStore.fetchRules()
		}
	},
	methods: {
		testRule() {
			this.testResults = { matchCount: 0, matches: [] }
		},
	},
}
</script>

<style scoped>
.automation-rule-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.automation-rule-detail__properties dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 8px;
}

.automation-rule-detail__properties dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.automation-rule-detail__test-results {
	margin-top: 20px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}
</style>
