<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-7.2
-->
<template>
	<div class="expense-claim-detail">
		<div class="expense-claim-detail__header">
			<h2>{{ claim.claimNumber || t('shillinq', 'Expense Claim') }}</h2>
			<div class="expense-claim-detail__actions">
				<NcButton
					v-if="claim.status === 'draft'"
					@click="submitClaim">
					{{ t('shillinq', 'Submit') }}
				</NcButton>
				<NcButton
					v-if="claim.status === 'submitted'"
					type="primary"
					@click="approveClaim">
					{{ t('shillinq', 'Approve') }}
				</NcButton>
				<NcButton
					v-if="claim.status === 'submitted'"
					type="error"
					@click="rejectClaim">
					{{ t('shillinq', 'Reject') }}
				</NcButton>
				<NcButton
					v-if="claim.status === 'approved'"
					type="primary"
					@click="approveForPayment">
					{{ t('shillinq', 'Approve for Payment') }}
				</NcButton>
			</div>
		</div>

		<div class="expense-claim-detail__tabs">
			<NcButton
				:type="activeTab === 'details' ? 'primary' : 'secondary'"
				@click="activeTab = 'details'">
				{{ t('shillinq', 'Details') }}
			</NcButton>
			<NcButton
				:type="activeTab === 'items' ? 'primary' : 'secondary'"
				@click="activeTab = 'items'">
				{{ t('shillinq', 'Items') }}
			</NcButton>
			<NcButton
				:type="activeTab === 'history' ? 'primary' : 'secondary'"
				@click="activeTab = 'history'">
				{{ t('shillinq', 'History') }}
			</NcButton>
		</div>

		<div
			v-if="activeTab === 'details'"
			class="expense-claim-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Claim Number') }}</dt>
				<dd>{{ claim.claimNumber }}</dd>
				<dt>{{ t('shillinq', 'Employee') }}</dt>
				<dd>{{ claim.employeeId }}</dd>
				<dt>{{ t('shillinq', 'Description') }}</dt>
				<dd>{{ claim.description }}</dd>
				<dt>{{ t('shillinq', 'Status') }}</dt>
				<dd>{{ claim.status }}</dd>
				<dt>{{ t('shillinq', 'Total Amount') }}</dt>
				<dd>{{ claim.totalAmount || 0 }} {{ claim.currency || 'EUR' }}</dd>
				<dt>{{ t('shillinq', 'Submitted') }}</dt>
				<dd>{{ claim.submittedAt || t('shillinq', 'Not submitted') }}</dd>
				<dt>{{ t('shillinq', 'Decided') }}</dt>
				<dd>{{ claim.decidedAt || t('shillinq', 'Pending') }}</dd>
				<dt v-if="claim.rejectionReason">
					{{ t('shillinq', 'Rejection Reason') }}
				</dt>
				<dd v-if="claim.rejectionReason">
					{{ claim.rejectionReason }}
				</dd>
			</dl>
		</div>

		<div
			v-if="activeTab === 'items'"
			class="expense-claim-detail__items">
			<table>
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Category') }}</th>
						<th>{{ t('shillinq', 'Description') }}</th>
						<th>{{ t('shillinq', 'Amount') }}</th>
						<th>{{ t('shillinq', 'VAT') }}</th>
						<th>{{ t('shillinq', 'Receipt Date') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="item in itemStore.expenseItems"
						:key="item.id">
						<td>{{ item.category }}</td>
						<td>{{ item.description }}</td>
						<td>{{ item.amount }} {{ item.currency || 'EUR' }}</td>
						<td>{{ item.vatAmount || 0 }} ({{ item.vatRate || 0 }}%)</td>
						<td>{{ item.receiptDate || t('shillinq', 'N/A') }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div
			v-if="activeTab === 'history'"
			class="expense-claim-detail__history">
			<p>{{ t('shillinq', 'Claim history will be displayed here.') }}</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useExpenseClaimStore } from '../../store/modules/expenseClaim.js'
import { useExpenseItemStore } from '../../store/modules/expenseItem.js'

export default {
	name: 'ExpenseClaimDetail',
	components: {
		NcButton,
	},
	data() {
		return {
			claimStore: useExpenseClaimStore(),
			itemStore: useExpenseItemStore(),
			activeTab: 'details',
		}
	},
	computed: {
		claim() {
			const claimId = this.$route.params.claimId
			return this.claimStore.expenseClaims.find((c) => c.id === claimId) || {}
		},
	},
	mounted() {
		if (this.claimStore.expenseClaims.length === 0) {
			this.claimStore.fetchClaims()
		}
		const claimId = this.$route.params.claimId
		if (claimId) {
			this.itemStore.fetchItemsForClaim(claimId)
		}
	},
	methods: {
		submitClaim() {
			console.info('Submitting claim:', this.claim.id)
		},
		approveClaim() {
			console.info('Approving claim:', this.claim.id)
		},
		rejectClaim() {
			console.info('Rejecting claim:', this.claim.id)
		},
		approveForPayment() {
			console.info('Approving for payment:', this.claim.id)
		},
	},
}
</script>

<style scoped>
.expense-claim-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.expense-claim-detail__actions {
	display: flex;
	gap: 8px;
}

.expense-claim-detail__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
}

.expense-claim-detail__properties dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 8px;
}

.expense-claim-detail__properties dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.expense-claim-detail__items table {
	width: 100%;
	border-collapse: collapse;
}

.expense-claim-detail__items th,
.expense-claim-detail__items td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}
</style>
