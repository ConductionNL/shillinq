<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-6.2
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Automation Rule')"
		@close="$emit('close')">
		<div class="automation-rule-form">
			<label>{{ t('shillinq', 'Name') }}</label>
			<input
				v-model="form.name"
				type="text"
				required>

			<label>{{ t('shillinq', 'Trigger Schema') }}</label>
			<input
				v-model="form.triggerSchema"
				type="text"
				:placeholder="t('shillinq', 'e.g. Invoice')"
				required>

			<label>{{ t('shillinq', 'Trigger Field') }}</label>
			<input
				v-model="form.triggerField"
				type="text"
				:placeholder="t('shillinq', 'e.g. ageInDays')"
				required>

			<label>{{ t('shillinq', 'Operator') }}</label>
			<NcSelect
				v-model="form.triggerOperator"
				:options="operatorOptions" />

			<label>{{ t('shillinq', 'Trigger Value') }}</label>
			<input
				v-model="form.triggerValue"
				type="text"
				required>

			<label>{{ t('shillinq', 'Action Type') }}</label>
			<NcSelect
				v-model="form.actionType"
				:options="actionTypeOptions" />

			<label>{{ t('shillinq', 'Action Parameters (JSON)') }}</label>
			<textarea
				v-model="form.actionParams"
				class="automation-rule-form__json"
				rows="3" />
			<p
				v-if="jsonError"
				class="error">
				{{ jsonError }}
			</p>

			<div class="automation-rule-form__active">
				<input
					v-model="form.isActive"
					type="checkbox">
				<label>{{ t('shillinq', 'Active') }}</label>
			</div>

			<NcButton
				type="primary"
				@click="save">
				{{ t('shillinq', 'Save') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'AutomationRuleForm',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},
	data() {
		return {
			form: {
				name: '',
				triggerSchema: '',
				triggerField: '',
				triggerOperator: 'gte',
				triggerValue: '',
				actionType: 'send_notification',
				actionParams: '{}',
				isActive: true,
			},
			operatorOptions: ['gt', 'lt', 'eq', 'gte', 'lte'],
			actionTypeOptions: ['send_notification', 'change_status', 'escalate'],
			jsonError: null,
		}
	},
	methods: {
		save() {
			this.jsonError = null
			if (this.form.actionParams) {
				try {
					JSON.parse(this.form.actionParams)
				} catch (e) {
					this.jsonError = t('shillinq', 'Invalid JSON in action parameters')
					return
				}
			}
			this.$emit('saved', { ...this.form })
		},
	},
}
</script>

<style scoped>
.automation-rule-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
}

.automation-rule-form label {
	font-weight: bold;
}

.automation-rule-form__json {
	font-family: monospace;
	resize: vertical;
}

.automation-rule-form__active {
	display: flex;
	align-items: center;
	gap: 8px;
}

.error {
	color: var(--color-error);
	font-size: 12px;
}
</style>
