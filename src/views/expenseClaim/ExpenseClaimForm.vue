<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-7.3
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
					:type="step === index ? 'primary' : 'tertiary'"
					@click="step = index">
					{{ (index + 1) + '. ' + t('shillinq', stepName) }}
				</NcButton>
			</div>

			<!-- Step 1: Details -->
			<div v-if="step === 0" class="expense-claim-form__step">
				<NcTextField
					:label="t('shillinq', 'Employee ID')"
					:value.sync="form.employeeId"
					:disabled="true" />
				<NcTextField
					:label="t('shillinq', 'Description')"
					:value.sync="form.description" />
				<NcSelect
					:label="t('shillinq', 'Currency')"
					:options="['EUR', 'USD', 'GBP']"
					:value="form.currency"
					@input="form.currency = $event" />
				<NcButton type="primary" @click="step = 1">
					{{ t('shillinq', 'Next') }}
				</NcButton>
			</div>

			<!-- Step 2: Items -->
			<div v-if="step === 1" class="expense-claim-form__step">
				<div v-for="(item, idx) in items" :key="idx" class="expense-claim-form__item-row">
					<NcSelect
						:label="t('shillinq', 'Category')"
						:options="categoryOptions"
						:value="item.category"
						@input="item.category = $event" />
					<NcTextField
						:label="t('shillinq', 'Description')"
						:value.sync="item.description" />
					<NcTextField
						:label="t('shillinq', 'Amount')"
						:value.sync="item.amount"
						type="number" />
					<NcButton type="error" @click="removeItem(idx)">
						{{ t('shillinq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton @click="addItem">
					{{ t('shillinq', 'Add Item') }}
				</NcButton>
				<div class="expense-claim-form__nav">
					<NcButton @click="step = 0">
						{{ t('shillinq', 'Back') }}
					</NcButton>
					<NcButton type="primary" @click="step = 2">
						{{ t('shillinq', 'Next') }}
					</NcButton>
				</div>
			</div>

			<!-- Step 3: Receipts -->
			<div v-if="step === 2" class="expense-claim-form__step">
				<p>{{ t('shillinq', 'Upload receipts for each item using the Nextcloud file picker.') }}</p>
				<div v-for="(item, idx) in items" :key="idx" class="expense-claim-form__receipt-row">
					<span>{{ item.description || t('shillinq', 'Item') + ' ' + (idx + 1) }}</span>
					<NcButton @click="pickFile(idx)">
						{{ t('shillinq', 'Choose File') }}
					</NcButton>
					<span v-if="item.receiptFile">{{ item.receiptFile }}</span>
				</div>
				<div class="expense-claim-form__nav">
					<NcButton @click="step = 1">
						{{ t('shillinq', 'Back') }}
					</NcButton>
					<NcButton type="primary" @click="step = 3">
						{{ t('shillinq', 'Next') }}
					</NcButton>
				</div>
			</div>

			<!-- Step 4: Review -->
			<div v-if="step === 3" class="expense-claim-form__step">
				<h3>{{ t('shillinq', 'Review') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'Employee') }}</dt>
					<dd>{{ form.employeeId }}</dd>
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ form.description }}</dd>
					<dt>{{ t('shillinq', 'Total Amount') }}</dt>
					<dd>{{ totalAmount.toFixed(2) }} {{ form.currency }}</dd>
					<dt>{{ t('shillinq', 'Items') }}</dt>
					<dd>{{ items.length }}</dd>
				</dl>
				<div class="expense-claim-form__nav">
					<NcButton @click="step = 2">
						{{ t('shillinq', 'Back') }}
					</NcButton>
					<NcButton type="primary" @click="submit">
						{{ t('shillinq', 'Submit Claim') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ExpenseClaimForm',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
	},
	emits: ['close', 'saved'],
	data() {
		return {
			step: 0,
			steps: ['Details', 'Items', 'Receipts', 'Review'],
			form: {
				// eslint-disable-next-line @nextcloud/no-deprecations
				employeeId: OC.currentUser || '',
				description: '',
				currency: 'EUR',
			},
			items: [{ category: 'travel', description: '', amount: '', receiptFile: null }],
			categoryOptions: ['travel', 'accommodation', 'meals', 'equipment', 'other'],
		}
	},
	computed: {
		totalAmount() {
			return this.items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0)
		},
	},
	methods: {
		addItem() {
			this.items.push({ category: 'travel', description: '', amount: '', receiptFile: null })
		},
		removeItem(idx) {
			this.items.splice(idx, 1)
		},
		pickFile(idx) {
			// Use Nextcloud file picker if available.
			if (typeof OC.dialogs !== 'undefined' && OC.dialogs.filepicker) {
				OC.dialogs.filepicker(
					this.t('shillinq', 'Select receipt'),
					(path) => { this.items[idx].receiptFile = path },
					false,
				)
			}
		},
		async submit() {
			try {
				const claimUrl = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				claimUrl.searchParams.set('schema', 'ExpenseClaim')

				const now = new Date().toISOString()
				const claimNumber = 'EXP-' + new Date().getFullYear() + '-' + String(Math.floor(Math.random() * 10000)).padStart(4, '0')

				const claimResponse = await fetch(claimUrl.toString(), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						...this.form,
						claimNumber,
						status: 'submitted',
						totalAmount: this.totalAmount,
						submittedAt: now,
					}),
				})

				if (claimResponse.ok) {
					const claimData = await claimResponse.json()
					const claimId = claimData.id

					// Create expense items.
					const itemUrl = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
					itemUrl.searchParams.set('schema', 'ExpenseItem')

					for (const item of this.items) {
						await fetch(itemUrl.toString(), {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								requesttoken: OC.requestToken,
							},
							body: JSON.stringify({
								expenseClaimId: claimId,
								category: item.category,
								description: item.description,
								amount: parseFloat(item.amount) || 0,
								currency: this.form.currency,
								receiptFile: item.receiptFile,
							}),
						})
					}

					this.$emit('saved')
				}
			} catch (error) {
				console.error('Failed to submit expense claim:', error)
			}
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
	margin-bottom: 16px;
}

.expense-claim-form__step {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.expense-claim-form__step dl {
	display: grid;
	grid-template-columns: 160px 1fr;
	gap: 8px 16px;
}

.expense-claim-form__step dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.expense-claim-form__item-row {
	display: grid;
	grid-template-columns: 1fr 2fr 1fr auto;
	gap: 8px;
	align-items: end;
}

.expense-claim-form__receipt-row {
	display: flex;
	gap: 8px;
	align-items: center;
}

.expense-claim-form__nav {
	display: flex;
	justify-content: space-between;
	margin-top: 16px;
}
</style>
