<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-7.2
-->
<template>
	<div class="expense-claim-detail">
		<header class="expense-claim-detail__header">
			<h2>{{ claim.claimNumber || t('shillinq', 'Expense Claim') }}</h2>
			<div class="expense-claim-detail__actions">
				<NcButton v-if="claim.status === 'draft'" @click="submitClaim">
					{{ t('shillinq', 'Submit') }}
				</NcButton>
				<NcButton v-if="claim.status === 'submitted'" type="primary" @click="approveClaim">
					{{ t('shillinq', 'Approve') }}
				</NcButton>
				<NcButton v-if="claim.status === 'submitted'" type="error" @click="rejectClaim">
					{{ t('shillinq', 'Reject') }}
				</NcButton>
				<NcButton v-if="claim.status === 'approved'" type="primary" @click="approveForPayment">
					{{ t('shillinq', 'Approve for Payment') }}
				</NcButton>
			</div>
		</header>

		<div class="expense-claim-detail__tabs">
			<NcButton
				v-for="tab in tabs"
				:key="tab"
				:type="activeTab === tab ? 'primary' : 'tertiary'"
				@click="activeTab = tab">
				{{ t('shillinq', tab) }}
			</NcButton>
		</div>

		<div v-if="activeTab === 'Details'" class="expense-claim-detail__properties">
			<dl>
				<dt>{{ t('shillinq', 'Employee') }}</dt>
				<dd>{{ claim.employeeId }}</dd>
				<dt>{{ t('shillinq', 'Description') }}</dt>
				<dd>{{ claim.description }}</dd>
				<dt>{{ t('shillinq', 'Status') }}</dt>
				<dd>
					<span :class="'status--' + claim.status">{{ claim.status }}</span>
				</dd>
				<dt>{{ t('shillinq', 'Total') }}</dt>
				<dd>{{ (claim.totalAmount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} {{ claim.currency || 'EUR' }}</dd>
				<dt>{{ t('shillinq', 'Submitted') }}</dt>
				<dd>{{ claim.submittedAt || '—' }}</dd>
				<dt>{{ t('shillinq', 'Decided') }}</dt>
				<dd>{{ claim.decidedAt || '—' }}</dd>
				<dt v-if="claim.rejectionReason">
					{{ t('shillinq', 'Rejection Reason') }}
				</dt>
				<dd v-if="claim.rejectionReason">
					{{ claim.rejectionReason }}
				</dd>
			</dl>
		</div>

		<div v-if="activeTab === 'Items'" class="expense-claim-detail__items">
			<table class="expense-claim-detail__items-table">
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
					<tr v-for="item in items" :key="item.id">
						<td>{{ item.category }}</td>
						<td>{{ item.description }}</td>
						<td>{{ (item.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
						<td>{{ item.vatAmount || 0 }} ({{ item.vatRate || 0 }}%)</td>
						<td>{{ item.receiptDate || '—' }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="activeTab === 'History'" class="expense-claim-detail__history">
			<p>{{ t('shillinq', 'Claim history will be shown here.') }}</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useExpenseClaimStore } from '../../store/modules/expenseClaim.js'
import { useExpenseItemStore } from '../../store/modules/expenseItem.js'

export default {
	name: 'ExpenseClaimDetail',
	components: {
		NcButton,
	},
	data() {
		return {
			activeTab: 'Details',
			tabs: ['Details', 'Items', 'History'],
		}
	},
	computed: {
		claimStore() {
			return useExpenseClaimStore()
		},
		itemStore() {
			return useExpenseItemStore()
		},
		claim() {
			const id = this.$route.params.claimId
			return this.claimStore.claims.find((c) => c.id === id) || {}
		},
		items() {
			return this.itemStore.getItemsByClaimId(this.claim.id)
		},
	},
	async created() {
		if (this.claimStore.claims.length === 0) {
			await this.claimStore.fetchClaims()
		}
		await this.itemStore.fetchItems()
	},
	methods: {
		async updateStatus(status) {
			try {
				const url = new URL(generateUrl('/apps/openregister/api/objects'), window.location.origin)
				url.searchParams.set('id', this.claim.id)
				await fetch(url.toString(), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ status }),
				})
				await this.claimStore.fetchClaims()
			} catch (error) {
				console.error('Failed to update claim status:', error)
			}
		},
		submitClaim() {
			this.updateStatus('submitted')
		},
		approveClaim() {
			this.updateStatus('approved')
		},
		rejectClaim() {
			this.updateStatus('rejected')
		},
		approveForPayment() {
			this.updateStatus('paid')
		},
	},
}
</script>

<style scoped>
.expense-claim-detail {
	padding: 8px 4px 24px;
	max-width: 1000px;
}

.expense-claim-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.expense-claim-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
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
	grid-template-columns: 160px 1fr;
	gap: 8px 16px;
}

.expense-claim-detail__properties dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.expense-claim-detail__items-table {
	width: 100%;
	border-collapse: collapse;
}

.expense-claim-detail__items-table th,
.expense-claim-detail__items-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.status--approved,
.status--paid {
	color: var(--color-success);
	font-weight: 600;
}

.status--submitted,
.status--under_review {
	color: var(--color-warning);
	font-weight: 600;
}

.status--draft {
	color: var(--color-text-maxcontrast);
}

.status--rejected {
	color: var(--color-error);
	font-weight: 600;
}
</style>
