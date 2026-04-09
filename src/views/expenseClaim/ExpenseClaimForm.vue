<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-7.3
-->
<template>
	<NcDialog
		:name="t('shillinq', 'New Expense Claim')"
		size="large"
		@close="$emit('close')">
		<div class="expense-claim-form">
			<div class="expense-claim-form__steps">
				<NcButton
					v-for="(stepName, index) in steps"
					:key="index"
					:type="step === index ? 'primary' : 'secondary'"
					@click="step = index">
					{{ (index + 1) + '. ' + stepName }}
				</NcButton>
			</div>

			<!-- Step 1: Details -->
			<div
				v-if="step === 0"
				class="expense-claim-form__step">
				<label>{{ t('shillinq', 'Employee ID') }}</label>
				<input
					v-model="form.employeeId"
					type="text"
					disabled>
				<label>{{ t('shillinq', 'Description') }}</label>
				<textarea
					v-model="form.description"
					rows="3"
					required />
				<label>{{ t('shillinq', 'Currency') }}</label>
				<NcSelect
					v-model="form.currency"
					:options="['EUR', 'USD', 'GBP']" />
			</div>

			<!-- Step 2: Items -->
			<div
				v-if="step === 1"
				class="expense-claim-form__step">
				<div
					v-for="(item, index) in items"
					:key="index"
					class="expense-claim-form__item">
					<ExpenseItemForm
						:item="item"
						@update="updateItem(index, $event)"
						@remove="removeItem(index)" />
				</div>
				<NcButton @click="addItem">
					{{ t('shillinq', 'Add Item') }}
				</NcButton>
			</div>

			<!-- Step 3: Receipts -->
			<div
				v-if="step === 2"
				class="expense-claim-form__step">
				<div
					v-for="(item, index) in items"
					:key="index"
					class="expense-claim-form__receipt">
					<p>{{ item.description || t('shillinq', 'Item') + ' ' + (index + 1) }}</p>
					<input
						type="file"
						@change="attachReceipt(index, $event)">
				</div>
			</div>

			<!-- Step 4: Review -->
			<div
				v-if="step === 3"
				class="expense-claim-form__step">
				<h3>{{ t('shillinq', 'Review') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'Employee') }}</dt>
					<dd>{{ form.employeeId }}</dd>
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ form.description }}</dd>
					<dt>{{ t('shillinq', 'Items') }}</dt>
					<dd>{{ items.length }}</dd>
					<dt>{{ t('shillinq', 'Total Amount') }}</dt>
					<dd>{{ totalAmount }} {{ form.currency }}</dd>
				</dl>
			</div>

			<div class="expense-claim-form__nav">
				<NcButton
					v-if="step > 0"
					@click="step--">
					{{ t('shillinq', 'Previous') }}
				</NcButton>
				<NcButton
					v-if="step < steps.length - 1"
					type="primary"
					@click="step++">
					{{ t('shillinq', 'Next') }}
				</NcButton>
				<NcButton
					v-if="step === steps.length - 1"
					type="primary"
					@click="submit">
					{{ t('shillinq', 'Submit Claim') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import ExpenseItemForm from './ExpenseItemForm.vue'

export default {
	name: 'ExpenseClaimForm',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		ExpenseItemForm,
	},
	data() {
		return {
			step: 0,
			steps: [
				t('shillinq', 'Details'),
				t('shillinq', 'Items'),
				t('shillinq', 'Receipts'),
				t('shillinq', 'Review'),
			],
			form: {
				employeeId: OC?.currentUser?.uid || '',
				description: '',
				currency: 'EUR',
			},
			items: [],
		}
	},
	computed: {
		totalAmount() {
			return this.items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0).toFixed(2)
		},
	},
	methods: {
		addItem() {
			this.items.push({
				category: 'travel',
				description: '',
				amount: 0,
				currency: this.form.currency,
				vatAmount: 0,
				vatRate: 0,
			})
		},
		updateItem(index, data) {
			this.items.splice(index, 1, { ...this.items[index], ...data })
		},
		removeItem(index) {
			this.items.splice(index, 1)
		},
		attachReceipt(index, event) {
			const file = event.target.files[0]
			if (file) {
				this.items[index].receiptFile = file.name
			}
		},
		submit() {
			this.$emit('saved', {
				...this.form,
				status: 'submitted',
				totalAmount: parseFloat(this.totalAmount),
				items: this.items,
			})
		},
	},
}
</script>

<style scoped>
.expense-claim-form {
	padding: 16px;
}

.expense-claim-form__steps {
	display: flex;
	gap: 4px;
	margin-bottom: 20px;
}

.expense-claim-form__step {
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-height: 200px;
}

.expense-claim-form__step label {
	font-weight: bold;
}

.expense-claim-form__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.expense-claim-form__receipt {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.expense-claim-form__nav {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.expense-claim-form__step dl {
	display: grid;
	grid-template-columns: 150px 1fr;
	gap: 8px;
}

.expense-claim-form__step dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}
</style>
