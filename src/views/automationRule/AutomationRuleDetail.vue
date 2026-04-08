<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-6.1
-->
<template>
	<div class="automation-rule-detail">
		<header class="automation-rule-detail__header">
			<h2>{{ rule.name || t('shillinq', 'Automation Rule') }}</h2>
			<NcButton type="secondary" @click="testRule">
				<template #icon>
					<TestTubeIcon :size="20" />
				</template>
				{{ t('shillinq', 'Test Rule') }}
			</NcButton>
		</header>

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
				<dd>{{ rule.actionParams || '—' }}</dd>
				<dt>{{ t('shillinq', 'Active') }}</dt>
				<dd>{{ rule.isActive ? t('shillinq', 'Yes') : t('shillinq', 'No') }}</dd>
				<dt>{{ t('shillinq', 'Matches') }}</dt>
				<dd>{{ rule.matchCount || 0 }}</dd>
				<dt>{{ t('shillinq', 'Last Evaluated') }}</dt>
				<dd>{{ rule.lastEvaluatedAt || t('shillinq', 'Never') }}</dd>
			</dl>
		</div>

		<div v-if="testResults" class="automation-rule-detail__test-results">
			<h3>{{ t('shillinq', 'Test Results') }}</h3>
			<p>{{ t('shillinq', '{count} objects would match (preview only, no action executed)', { count: testResults.length }) }}</p>
			<pre>{{ JSON.stringify(testResults, null, 2) }}</pre>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import TestTubeIcon from 'vue-material-design-icons/TestTube.vue'
import { useAutomationRuleStore } from '../../store/modules/automationRule.js'

export default {
	name: 'AutomationRuleDetail',
	components: {
		NcButton,
		TestTubeIcon,
	},
	data() {
		return {
			testResults: null,
		}
	},
	computed: {
		ruleStore() {
			return useAutomationRuleStore()
		},
		rule() {
			const id = this.$route.params.ruleId
			return this.ruleStore.rules.find((r) => r.id === id) || {}
		},
	},
	async created() {
		if (this.ruleStore.rules.length === 0) {
			await this.ruleStore.fetchRules()
		}
	},
	methods: {
		async testRule() {
			// Preview mode — fetches matching objects without executing actions.
			const objectStore = (await import('../../store/modules/object.js')).useObjectStore()
			const objects = await objectStore.fetchObjects(this.rule.triggerSchema)
			const value = parseFloat(this.rule.triggerValue)
			this.testResults = objects.filter((obj) => {
				const fieldVal = parseFloat(obj[this.rule.triggerField])
				switch (this.rule.triggerOperator) {
				case 'gt': return fieldVal > value
				case 'lt': return fieldVal < value
				case 'eq': return fieldVal === value
				case 'gte': return fieldVal >= value
				case 'lte': return fieldVal <= value
				default: return false
				}
			})
		},
	},
}
</script>

<style scoped>
.automation-rule-detail {
	padding: 8px 4px 24px;
	max-width: 900px;
}

.automation-rule-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.automation-rule-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.automation-rule-detail__properties dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 8px 16px;
}

.automation-rule-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.automation-rule-detail__test-results {
	margin-top: 24px;
}

.automation-rule-detail__test-results pre {
	background: var(--color-background-dark);
	padding: 12px;
	border-radius: var(--border-radius);
	overflow-x: auto;
	font-size: 13px;
	max-height: 400px;
}
</style>
