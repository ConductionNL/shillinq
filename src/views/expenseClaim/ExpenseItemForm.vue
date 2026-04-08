<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-7.3
-->
<template>
	<div class="expense-item-form">
		<NcSelect
			:label="t('shillinq', 'Category')"
			:options="categoryOptions"
			:value="form.category"
			@input="form.category = $event" />
		<NcTextField
			:label="t('shillinq', 'Description')"
			:value.sync="form.description" />
		<NcTextField
			:label="t('shillinq', 'Amount')"
			:value.sync="form.amount"
			type="number" />
		<NcTextField
			:label="t('shillinq', 'VAT Amount')"
			:value.sync="form.vatAmount"
			type="number" />
		<NcTextField
			:label="t('shillinq', 'VAT Rate (%)')"
			:value.sync="form.vatRate"
			type="number" />
		<NcButton type="primary" @click="$emit('save', form)">
			{{ t('shillinq', 'Save Item') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ExpenseItemForm',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	props: {
		item: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['save'],
	data() {
		return {
			form: {
				category: this.item.category || 'travel',
				description: this.item.description || '',
				amount: this.item.amount || '',
				vatAmount: this.item.vatAmount || 0,
				vatRate: this.item.vatRate || 0,
			},
			categoryOptions: ['travel', 'accommodation', 'meals', 'equipment', 'other'],
		}
	},
}
</script>

<style scoped>
.expense-item-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
