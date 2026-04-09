<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-7.3
-->
<template>
	<div class="expense-item-form">
		<div class="expense-item-form__row">
			<div class="expense-item-form__field">
				<label>{{ t('shillinq', 'Category') }}</label>
				<NcSelect
					:value="item.category"
					:options="categoryOptions"
					@input="update('category', $event)" />
			</div>
			<div class="expense-item-form__field">
				<label>{{ t('shillinq', 'Amount') }}</label>
				<input
					:value="item.amount"
					type="number"
					step="0.01"
					min="0"
					@input="update('amount', $event.target.value)">
			</div>
		</div>

		<div class="expense-item-form__field">
			<label>{{ t('shillinq', 'Description') }}</label>
			<input
				:value="item.description"
				type="text"
				@input="update('description', $event.target.value)">
		</div>

		<div class="expense-item-form__row">
			<div class="expense-item-form__field">
				<label>{{ t('shillinq', 'VAT Rate (%)') }}</label>
				<input
					:value="item.vatRate"
					type="number"
					step="0.1"
					min="0"
					@input="update('vatRate', $event.target.value)">
			</div>
			<div class="expense-item-form__field">
				<label>{{ t('shillinq', 'VAT Amount') }}</label>
				<input
					:value="item.vatAmount"
					type="number"
					step="0.01"
					min="0"
					@input="update('vatAmount', $event.target.value)">
			</div>
			<div class="expense-item-form__field">
				<label>{{ t('shillinq', 'Receipt Date') }}</label>
				<input
					:value="item.receiptDate"
					type="date"
					@input="update('receiptDate', $event.target.value)">
			</div>
		</div>

		<NcButton
			type="tertiary"
			@click="$emit('remove')">
			{{ t('shillinq', 'Remove Item') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ExpenseItemForm',
	components: {
		NcButton,
		NcSelect,
	},
	props: {
		item: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			categoryOptions: ['travel', 'accommodation', 'meals', 'equipment', 'other'],
		}
	},
	methods: {
		update(field, value) {
			this.$emit('update', { [field]: value })
		},
	},
}
</script>

<style scoped>
.expense-item-form__row {
	display: flex;
	gap: 12px;
}

.expense-item-form__field {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
}

.expense-item-form__field label {
	font-weight: bold;
	font-size: 12px;
}
</style>
