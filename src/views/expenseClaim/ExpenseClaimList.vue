<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@see openspec/changes/general/tasks.md#task-7.1
-->
<template>
	<div class="expense-claim-list">
		<div class="expense-claim-list__header">
			<h2>{{ t('shillinq', 'Expense Claims') }}</h2>
			<NcButton
				type="primary"
				@click="showForm = true">
				{{ t('shillinq', 'New Expense Claim') }}
			</NcButton>
		</div>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'ExpenseClaim'"
			@bulk-action="onBulkAction" />

		<table class="expense-claim-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleSelectAll">
					</th>
					<th>{{ t('shillinq', 'Claim Number') }}</th>
					<th>{{ t('shillinq', 'Employee') }}</th>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Amount') }}</th>
					<th>{{ t('shillinq', 'Currency') }}</th>
					<th>{{ t('shillinq', 'Submitted') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="claim in claimStore.expenseClaims"
					:key="claim.id"
					@click="openClaim(claim)">
					<td @click.stop>
						<input
							type="checkbox"
							:checked="selectedIds.includes(claim.id)"
							@change="toggleSelect(claim.id)">
					</td>
					<td>{{ claim.claimNumber }}</td>
					<td>{{ claim.employeeId }}</td>
					<td>{{ claim.description }}</td>
					<td>
						<span :class="'status--' + statusColor(claim.status)">
							{{ claim.status }}
						</span>
					</td>
					<td>{{ claim.totalAmount || 0 }}</td>
					<td>{{ claim.currency || 'EUR' }}</td>
					<td>{{ claim.submittedAt || t('shillinq', 'Not submitted') }}</td>
				</tr>
			</tbody>
		</table>

		<ExpenseClaimForm
			v-if="showForm"
			@close="showForm = false"
			@saved="onClaimSaved" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useExpenseClaimStore } from '../../store/modules/expenseClaim.js'
import BulkActionBar from '../../components/BulkActionBar.vue'
import ExpenseClaimForm from './ExpenseClaimForm.vue'

export default {
	name: 'ExpenseClaimList',
	components: {
		NcButton,
		BulkActionBar,
		ExpenseClaimForm,
	},
	data() {
		return {
			claimStore: useExpenseClaimStore(),
			selectedIds: [],
			showForm: false,
		}
	},
	computed: {
		allSelected() {
			return this.claimStore.expenseClaims.length > 0
				&& this.selectedIds.length === this.claimStore.expenseClaims.length
		},
	},
	mounted() {
		this.claimStore.fetchClaims()
	},
	methods: {
		openClaim(claim) {
			this.$router.push({ name: 'ExpenseClaimDetail', params: { claimId: claim.id } })
		},
		statusColor(status) {
			const map = {
				approved: 'green',
				paid: 'green',
				submitted: 'yellow',
				under_review: 'yellow',
				draft: 'grey',
				rejected: 'red',
			}
			return map[status] || 'grey'
		},
		toggleSelectAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.claimStore.expenseClaims.map((c) => c.id)
			}
		},
		toggleSelect(id) {
			const index = this.selectedIds.indexOf(id)
			if (index >= 0) {
				this.selectedIds.splice(index, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		onBulkAction() {
			this.selectedIds = []
			this.claimStore.fetchClaims()
		},
		onClaimSaved() {
			this.showForm = false
			this.claimStore.fetchClaims()
		},
	},
}
</script>

<style scoped>
.expense-claim-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.expense-claim-list__table {
	width: 100%;
	border-collapse: collapse;
}

.expense-claim-list__table th,
.expense-claim-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.expense-claim-list__table tr:hover {
	background: var(--color-background-hover);
	cursor: pointer;
}

.status--green {
	color: var(--color-success);
	font-weight: bold;
}

.status--yellow {
	color: var(--color-warning);
	font-weight: bold;
}

.status--red {
	color: var(--color-error);
	font-weight: bold;
}

.status--grey {
	color: var(--color-text-maxcontrast);
}
</style>
