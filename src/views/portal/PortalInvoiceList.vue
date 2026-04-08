<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/general/tasks.md#task-5.2
-->
<template>
	<div class="portal-invoice-list">
		<header class="portal-invoice-list__header">
			<h2>{{ t('shillinq', 'Portal Invoices') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="!authenticated"
			:name="t('shillinq', 'Authentication Required')">
			<template #description>
				{{ t('shillinq', 'Please provide a valid portal token to view invoices.') }}
			</template>
		</NcEmptyContent>

		<table v-else class="portal-invoice-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Invoice Number') }}</th>
					<th>{{ t('shillinq', 'Issue Date') }}</th>
					<th>{{ t('shillinq', 'Due Date') }}</th>
					<th>{{ t('shillinq', 'Amount') }}</th>
					<th>{{ t('shillinq', 'Currency') }}</th>
					<th>{{ t('shillinq', 'Payment Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="invoice in invoices" :key="invoice.id">
					<td>{{ invoice.invoiceNumber }}</td>
					<td>{{ invoice.issueDate }}</td>
					<td>{{ invoice.dueDate }}</td>
					<td>{{ invoice.totalAmount }}</td>
					<td>{{ invoice.currency || 'EUR' }}</td>
					<td>
						<span :class="'status--' + (invoice.status || 'unknown')">
							{{ invoice.status }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PortalInvoiceList',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
	},
	data() {
		return {
			invoices: [],
			loading: false,
			authenticated: false,
		}
	},
	async created() {
		const token = this.$route.query.token || localStorage.getItem('shillinq_portal_token')
		if (token) {
			await this.loadInvoices(token)
		}
	},
	methods: {
		async loadInvoices(token) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/portal/invoices'),
					{
						headers: { 'X-Portal-Token': token },
					},
				)
				if (response.ok) {
					this.invoices = await response.json()
					this.authenticated = true
				}
			} catch (error) {
				console.error('Failed to load portal invoices:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.portal-invoice-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.portal-invoice-list__header {
	margin-bottom: 16px;
}

.portal-invoice-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.portal-invoice-list__table {
	width: 100%;
	border-collapse: collapse;
}

.portal-invoice-list__table th,
.portal-invoice-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.status--paid {
	color: var(--color-success);
	font-weight: 600;
}

.status--overdue {
	color: var(--color-error);
	font-weight: 600;
}
</style>
