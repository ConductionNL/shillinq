<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-7.1
-->
<template>
	<div class="expense-claim-list">
		<header class="expense-claim-list__header">
			<h2>{{ t('shillinq', 'Expense Claims') }}</h2>
			<NcButton type="primary" @click="showForm = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'New Expense Claim') }}
			</NcButton>
		</header>

		<BulkActionBar
			v-if="selectedIds.length > 0"
			:selected-ids="selectedIds"
			:schema="'ExpenseClaim'"
			@bulk-action="handleBulkAction" />

		<NcLoadingIcon v-if="loading" />

		<table v-else class="expense-claim-list__table">
			<thead>
				<tr>
					<th>
						<input
							type="checkbox"
							:checked="allSelected"
							@change="toggleAll">
					</th>
					<th>{{ t('shillinq', 'Claim Number') }}</th>
					<th>{{ t('shillinq', 'Employee') }}</th>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Total') }}</th>
					<th>{{ t('shillinq', 'Currency') }}</th>
					<th>{{ t('shillinq', 'Submitted') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="claim in claims"
					:key="claim.id"
					class="expense-claim-list__row"
					@click="$router.push({ name: 'ExpenseClaimDetail', params: { claimId: claim.id } })">
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
						<span :class="'status--' + claim.status">
							{{ claim.status }}
						</span>
					</td>
					<td>{{ (claim.totalAmount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
					<td>{{ claim.currency || 'EUR' }}</td>
					<td>{{ claim.submittedAt || '—' }}</td>
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
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import BulkActionBar from '../../components/BulkActionBar.vue'
import ExpenseClaimForm from './ExpenseClaimForm.vue'
import { useExpenseClaimStore } from '../../store/modules/expenseClaim.js'

export default {
	name: 'ExpenseClaimList',
	components: {
		BulkActionBar,
		ExpenseClaimForm,
		NcButton,
		NcLoadingIcon,
		PlusIcon,
	},
	data() {
		return {
			showForm: false,
			selectedIds: [],
		}
	},
	computed: {
		claimStore() {
			return useExpenseClaimStore()
		},
		claims() {
			return this.claimStore.claims
		},
		loading() {
			return this.claimStore.loading
		},
		allSelected() {
			return this.claims.length > 0 && this.selectedIds.length === this.claims.length
		},
	},
	created() {
		this.claimStore.fetchClaims()
	},
	methods: {
		toggleSelect(id) {
			const idx = this.selectedIds.indexOf(id)
			if (idx >= 0) {
				this.selectedIds.splice(idx, 1)
			} else {
				this.selectedIds.push(id)
			}
		},
		toggleAll() {
			if (this.allSelected) {
				this.selectedIds = []
			} else {
				this.selectedIds = this.claims.map((c) => c.id)
			}
		},
		onClaimSaved() {
			this.showForm = false
			this.claimStore.fetchClaims()
		},
		handleBulkAction() {
			this.selectedIds = []
			this.claimStore.fetchClaims()
		},
	},
}
</script>

<style scoped>
.expense-claim-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.expense-claim-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.expense-claim-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
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

.expense-claim-list__row {
	cursor: pointer;
}

.expense-claim-list__row:hover {
	background: var(--color-background-hover);
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
