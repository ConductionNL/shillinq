<!--
  Admin Invoice List

  Tabular view of every BillableInvoice for the current administration with
  filters (date range, billing model, status) and per-row actions: view,
  edit draft, post, export PDF, cancel (Task 17).

  Migrated from a hand-rolled <table> to the shared nc-vue CnDataTable
  universal-list-widget (columns + :rows + #cell-* slots), inheriting the
  fleet table chrome (sortable headers, empty-state, accessibility) while the
  date-range / billing-model / status filters narrow the row set. Presentation
  only — the invoiceApi feed, columns, filters and row actions are unchanged.

  @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-17
  @spec openspec/specs/list-views-cndatatable/spec.md

  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="admin-invoice-list">
		<header>
			<h2>{{ t('shillinq', 'Invoices') }}</h2>
			<router-link to="/invoice/generate" class="admin-invoice-list__cta">
				{{ t('shillinq', 'Generate invoice') }}
			</router-link>
		</header>

		<div class="admin-invoice-list__filters">
			<label>
				{{ t('shillinq', 'From') }}
				<input v-model="filters.fromDate" type="date" @change="reload" />
			</label>
			<label>
				{{ t('shillinq', 'To') }}
				<input v-model="filters.toDate" type="date" @change="reload" />
			</label>
			<label>
				{{ t('shillinq', 'Billing model') }}
				<select v-model="filters.billingModel" @change="reload">
					<option value="">{{ t('shillinq', 'All') }}</option>
					<option value="t_and_m">T&M</option>
					<option value="fixed_fee">Fixed fee</option>
					<option value="milestone">Milestone</option>
					<option value="retainer">Retainer</option>
					<option value="mixed">Mixed</option>
				</select>
			</label>
			<label>
				{{ t('shillinq', 'Status') }}
				<select
					v-model="filters.status"
					data-testid="admin-invoice-status-filter"
					@change="reload">
					<option value="">{{ t('shillinq', 'All') }}</option>
					<option value="draft">{{ t('shillinq', 'Draft') }}</option>
					<option value="posted">{{ t('shillinq', 'Posted') }}</option>
					<option value="cancelled">
						{{ t('shillinq', 'Cancelled') }}
					</option>
				</select>
			</label>
		</div>

		<CnDataTable
			class="admin-invoice-list__table"
			data-testid="admin-invoice-table"
			:columns="columns"
			:rows="invoices"
			:emptyLabel="t('shillinq', 'No invoices found')">
			<template #cell-grossAmount="{ row }">
				<span class="num">€ {{ formatMoney(row.grossAmount) }}</span>
			</template>
			<template #cell-actions="{ row }">
				<span class="admin-invoice-list__actions">
					<router-link :to="`/invoice/${row.id}`">
						{{ t('shillinq', 'View') }}
					</router-link>
					<button
						type="button"
						:disabled="row.status !== 'draft'"
						@click="post(row)">
						{{ t('shillinq', 'Post') }}
					</button>
					<button type="button" @click="pdf(row)">
						{{ t('shillinq', 'PDF') }}
					</button>
				</span>
			</template>
		</CnDataTable>
	</section>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import invoiceApi from '../../api/invoiceApi.js'

export default {
	name: 'AdminInvoiceList',

	components: {
		CnDataTable,
	},

	data() {
		return {
			invoices: [],
			filters: {
				fromDate: '',
				toDate: '',
				billingModel: '',
				status: '',
			},
		}
	},

	computed: {
		/**
		 * CnDataTable column definitions for the invoice list.
		 *
		 * @spec openspec/specs/list-views-cndatatable/spec.md
		 * @return {Array<object>} ordered column defs
		 */
		columns() {
			return [
				{
					key: 'invoiceNumber',
					label: this.t('shillinq', 'Invoice #'),
					sortable: true,
				},
				{
					key: 'invoiceDate',
					label: this.t('shillinq', 'Invoice date'),
					sortable: true,
				},
				{
					key: 'dueDate',
					label: this.t('shillinq', 'Due date'),
					sortable: true,
				},
				{
					key: 'customerId',
					label: this.t('shillinq', 'Customer'),
					sortable: true,
				},
				{
					key: 'billingModel',
					label: this.t('shillinq', 'Billing model'),
					sortable: true,
				},
				{
					key: 'grossAmount',
					label: this.t('shillinq', 'Gross'),
					sortable: true,
					align: 'right',
				},
				{
					key: 'status',
					label: this.t('shillinq', 'Status'),
					sortable: true,
				},
				{
					key: 'actions',
					label: this.t('shillinq', 'Actions'),
					sortable: false,
				},
			]
		},
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		t,
		formatMoney(value) {
			const n = Number(value || 0)
			return n.toLocaleString('nl-NL', {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
		},

		async reload() {
			try {
				this.invoices = await invoiceApi.list(this.filters)
			} catch (e) {
				this.invoices = []
			}
		},

		async post(invoice) {
			await invoiceApi.post(invoice.id)
			await this.reload()
		},

		async pdf(invoice) {
			await invoiceApi.exportPdf(invoice.id)
		},
	},
}
</script>

<style scoped>
.admin-invoice-list__filters {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin: 12px 0;
}

.num {
	text-align: right;
}

.admin-invoice-list__actions {
	display: flex;
	gap: 8px;
}
</style>
