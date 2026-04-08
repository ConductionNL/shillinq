<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-6.2
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Create Automation Rule')"
		@close="$emit('close')">
		<div class="automation-rule-form">
			<NcTextField
				:label="t('shillinq', 'Name')"
				:value.sync="form.name" />
			<NcTextField
				:label="t('shillinq', 'Trigger Schema')"
				:value.sync="form.triggerSchema" />
			<NcTextField
				:label="t('shillinq', 'Trigger Field')"
				:value.sync="form.triggerField" />
			<NcSelect
				:label="t('shillinq', 'Trigger Operator')"
				:options="operatorOptions"
				:value="form.triggerOperator"
				@input="form.triggerOperator = $event" />
			<NcTextField
				:label="t('shillinq', 'Trigger Value')"
				:value.sync="form.triggerValue" />
			<NcSelect
				:label="t('shillinq', 'Action Type')"
				:options="actionTypeOptions"
				:value="form.actionType"
				@input="form.actionType = $event" />
			<div class="automation-rule-form__json">
				<label>{{ t('shillinq', 'Action Parameters (JSON)') }}</label>
				<textarea
					v-model="form.actionParams"
					rows="4"
					:class="{ 'json-invalid': !isValidJson }" />
				<span v-if="!isValidJson" class="automation-rule-form__json-error">
					{{ t('shillinq', 'Invalid JSON') }}
				</span>
			</div>
			<NcButton type="primary" :disabled="!isValid" @click="save">
				{{ t('shillinq', 'Save') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AutomationRuleForm',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
	},
	emits: ['close', 'saved'],
	data() {
		return {
			form: {
				name: '',
				triggerSchema: 'Invoice',
				triggerField: '',
				triggerOperator: 'gte',
				triggerValue: '',
				actionType: 'send_notification',
				actionParams: '{}',
				isActive: true,
			},
			operatorOptions: ['gt', 'lt', 'eq', 'gte', 'lte'],
			actionTypeOptions: ['send_notification', 'change_status', 'escalate'],
		}
	},
	computed: {
		isValidJson() {
			if (!this.form.actionParams) return true
			try {
				JSON.parse(this.form.actionParams)
				return true
			} catch {
				return false
			}
		},
		isValid() {
			return this.form.name
				&& this.form.triggerSchema
				&& this.form.triggerField
				&& this.form.triggerValue
				&& this.isValidJson
		},
	},
	methods: {
		async save() {
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('schema', 'AutomationRule')
				await fetch(url.toString(), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.form),
				})
				this.$emit('saved')
			} catch (error) {
				console.error('Failed to create automation rule:', error)
			}
		},
	},
}
</script>

<style scoped>
.automation-rule-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.automation-rule-form__json label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}

.automation-rule-form__json textarea {
	width: 100%;
	font-family: monospace;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.automation-rule-form__json textarea.json-invalid {
	border-color: var(--color-error);
}

.automation-rule-form__json-error {
	color: var(--color-error);
	font-size: 12px;
}
</style>
