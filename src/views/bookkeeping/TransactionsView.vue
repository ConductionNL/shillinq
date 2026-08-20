<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Transactions list view (REQ-WBSO-002 / REQ-WBSO-008). Shows the
 administration's transactions in a table with date, amount, description,
 type, and status columns. Supports filtering by date range, status, and
 type, and exposes a "Create Transaction" action for bookkeepers.

 Migrated from a hand-rolled <table> to the shared nc-vue CnDataTable
 universal-list-widget (columns + :rows + #cell-* slots + empty-label);
 the date-range/status/type filters and the create action are unchanged.

 @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-21
 @spec openspec/specs/list-views-cndatatable/spec.md
-->
<template>
	<NcAppContent>
		<div class="wbso-transactions">
			<header class="wbso-transactions__header">
				<h2>{{ t('shillinq', 'Transactions') }}</h2>
				<NcButton v-if="canCreate" variant="primary" @click="onCreate">
					{{ t('shillinq', 'Create Transaction') }}
				</NcButton>
			</header>

			<div class="wbso-transactions__filters">
				<label>
					<span>{{ t('shillinq', 'Status') }}</span>
					<select v-model="filters.status" @change="load">
						<option value="">{{ t('shillinq', 'All') }}</option>
						<option value="draft">{{ t('shillinq', 'Draft') }}</option>
						<option value="posted">{{ t('shillinq', 'Posted') }}</option>
						<option value="reversed">
							{{ t('shillinq', 'Reversed') }}
						</option>
					</select>
				</label>
				<label>
					<span>{{ t('shillinq', 'Type') }}</span>
					<select v-model="filters.type" @change="load">
						<option value="">{{ t('shillinq', 'All') }}</option>
						<option value="invoice">
							{{ t('shillinq', 'Invoice') }}
						</option>
						<option value="receipt">
							{{ t('shillinq', 'Receipt') }}
						</option>
						<option value="journal-entry">
							{{ t('shillinq', 'Journal Entry') }}
						</option>
						<option value="credit-note">
							{{ t('shillinq', 'Credit Note') }}
						</option>
						<option value="debit-note">
							{{ t('shillinq', 'Debit Note') }}
						</option>
					</select>
				</label>
				<label>
					<span>{{ t('shillinq', 'From') }}</span>
					<input v-model="filters.dateFrom" type="date" @change="load" />
				</label>
				<label>
					<span>{{ t('shillinq', 'To') }}</span>
					<input v-model="filters.dateTo" type="date" @change="load" />
				</label>
			</div>

			<div v-if="loading" class="wbso-transactions__loading">
				{{ t('shillinq', 'Loading…') }}
			</div>

			<CnDataTable
				v-else
				class="wbso-transactions__table"
				:columns="columns"
				:rows="transactions"
				:emptyLabel="
					t(
						'shillinq',
						'Create the first transaction to start posting to the books.',
					)
				">
				<template #cell-amount="{ row }">
					<span class="wbso-transactions__cell--amount">{{
						formatAmount(row.amount)
					}}</span>
				</template>
				<template #cell-status="{ row }">
					<span :data-status="row.status">{{
						translateStatus(row.status)
					}}</span>
				</template>
			</CnDataTable>

			<p v-if="errorMessage" class="wbso-transactions__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcAppContent, NcButton } from '@nextcloud/vue'

export default {
	name: 'TransactionsView',

	components: {
		CnDataTable,
		NcAppContent,
		NcButton,
	},

	data() {
		return {
			transactions: [],
			loading: true,
			errorMessage: '',
			canCreate: false,
			filters: {
				status: '',
				type: '',
				dateFrom: '',
				dateTo: '',
			},
		}
	},

	computed: {
		/**
		 * CnDataTable column definitions for the transactions list.
		 *
		 * @spec openspec/specs/list-views-cndatatable/spec.md
		 * @return {Array<object>} ordered column defs
		 */
		columns() {
			return [
				{
					key: 'transactionDate',
					label: t('shillinq', 'Transaction Date'),
					sortable: true,
				},
				{
					key: 'transactionNumber',
					label: t('shillinq', 'Number'),
					sortable: true,
				},
				{
					key: 'transactionType',
					label: t('shillinq', 'Type'),
					sortable: true,
				},
				{
					key: 'description',
					label: t('shillinq', 'Description'),
					sortable: true,
				},
				{
					key: 'amount',
					label: t('shillinq', 'Amount'),
					sortable: true,
					align: 'right',
				},
				{ key: 'status', label: t('shillinq', 'Status'), sortable: true },
			]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateOcsUrl('apps/shillinq/api/v1/transactions')
				const params = {}
				if (this.filters.status) params.status = this.filters.status
				if (this.filters.type) params.type = this.filters.type
				if (this.filters.dateFrom) params.dateFrom = this.filters.dateFrom
				if (this.filters.dateTo) params.dateTo = this.filters.dateTo
				const { data } = await axios.get(url, { params })
				this.transactions =
					data?.ocs?.data?.transactions ?? data?.transactions ?? []
				this.canCreate =
					data?.ocs?.data?.canCreate ?? data?.canCreate ?? false
			} catch (error) {
				this.errorMessage = t('shillinq', 'Failed to load transactions.')
			} finally {
				this.loading = false
			}
		},

		onCreate() {
			this.$emit('create-transaction')
		},

		formatAmount(amount) {
			if (typeof amount !== 'number') {
				return amount
			}
			return amount.toLocaleString('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			})
		},

		translateStatus(status) {
			const map = {
				draft: t('shillinq', 'Draft'),
				posted: t('shillinq', 'Posted'),
				reversed: t('shillinq', 'Reversed'),
			}
			return map[status] || status
		},
	},
}
</script>

<style scoped>
.wbso-transactions {
	padding: 1rem;
}

.wbso-transactions__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.wbso-transactions__filters {
	display: flex;
	gap: 1rem;
	flex-wrap: wrap;
	margin-bottom: 1rem;
}

.wbso-transactions__filters label {
	display: flex;
	flex-direction: column;
	font-size: 0.9rem;
}

.wbso-transactions__cell--amount {
	text-align: right;
}

.wbso-transactions__error {
	color: var(--color-error);
}

[data-status='posted'] {
	font-weight: bold;
}

[data-status='reversed'] {
	color: var(--color-error);
}
</style>
