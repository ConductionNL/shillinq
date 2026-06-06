<!--
  Admin Invoice List

  Tabular view of every BillableInvoice for the current administration with
  filters (date range, billing model, status) and per-row actions: view,
  edit draft, post, export PDF, cancel (Task 17).

  @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-17

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
				<input v-model="filters.fromDate" type="date" @change="reload">
			</label>
			<label>
				{{ t('shillinq', 'To') }}
				<input v-model="filters.toDate" type="date" @change="reload">
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
				<select v-model="filters.status" @change="reload">
					<option value="">{{ t('shillinq', 'All') }}</option>
					<option value="draft">{{ t('shillinq', 'Draft') }}</option>
					<option value="posted">{{ t('shillinq', 'Posted') }}</option>
					<option value="cancelled">{{ t('shillinq', 'Cancelled') }}</option>
				</select>
			</label>
		</div>

		<table class="admin-invoice-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Invoice #') }}</th>
					<th>{{ t('shillinq', 'Invoice date') }}</th>
					<th>{{ t('shillinq', 'Due date') }}</th>
					<th>{{ t('shillinq', 'Customer') }}</th>
					<th>{{ t('shillinq', 'Billing model') }}</th>
					<th class="num">{{ t('shillinq', 'Gross') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-if="!invoices.length">
					<td colspan="8" class="admin-invoice-list__empty">{{ t('shillinq', 'No invoices found') }}</td>
				</tr>
				<tr v-for="inv in invoices" :key="inv.id || inv.invoiceNumber">
					<td>{{ inv.invoiceNumber }}</td>
					<td>{{ inv.invoiceDate }}</td>
					<td>{{ inv.dueDate }}</td>
					<td>{{ inv.customerId }}</td>
					<td>{{ inv.billingModel }}</td>
					<td class="num">€ {{ formatMoney(inv.grossAmount) }}</td>
					<td>{{ inv.status }}</td>
					<td class="admin-invoice-list__actions">
						<router-link :to="`/invoice/${inv.id}`">{{ t('shillinq', 'View') }}</router-link>
						<button type="button" :disabled="inv.status !== 'draft'" @click="post(inv)">{{ t('shillinq', 'Post') }}</button>
						<button type="button" @click="pdf(inv)">{{ t('shillinq', 'PDF') }}</button>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import invoiceApi from '../../api/invoiceApi.js'

export default {
	name: 'AdminInvoiceList',

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

	async mounted() {
		await this.reload()
	},

	methods: {
		t,
		formatMoney(value) {
			const n = Number(value || 0)
			return n.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
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
.admin-invoice-list__table {
	width: 100%;
	border-collapse: collapse;
}
.admin-invoice-list__table th,
.admin-invoice-list__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}
.num {
	text-align: right;
}
.admin-invoice-list__actions {
	display: flex;
	gap: 8px;
}
.admin-invoice-list__empty {
	text-align: center;
	color: var(--color-text-maxcontrast, #888);
	padding: 24px 0;
}
</style>
