<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-5.2
-->
<template>
	<div class="portal-invoice-list">
		<div class="portal-invoice-list__header">
			<h2>{{ t('shillinq', 'Invoices') }}</h2>
		</div>

		<div
			v-if="!authenticated"
			class="portal-invoice-list__auth">
			<p>{{ t('shillinq', 'Enter your portal token to view invoices.') }}</p>
			<div class="portal-invoice-list__auth-form">
				<input
					v-model="tokenInput"
					type="text"
					:placeholder="t('shillinq', 'Portal Token')">
				<NcButton
					type="primary"
					@click="authenticate">
					{{ t('shillinq', 'Authenticate') }}
				</NcButton>
			</div>
			<p
				v-if="authError"
				class="error">
				{{ authError }}
			</p>
		</div>

		<table
			v-else
			class="portal-invoice-list__table">
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
				<tr
					v-for="invoice in invoices"
					:key="invoice.id">
					<td>{{ invoice.invoiceNumber }}</td>
					<td>{{ invoice.issueDate }}</td>
					<td>{{ invoice.dueDate }}</td>
					<td>{{ invoice.totalAmount }}</td>
					<td>{{ invoice.currency || 'EUR' }}</td>
					<td>{{ invoice.paymentStatus || invoice.status }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PortalInvoiceList',
	components: {
		NcButton,
	},
	data() {
		return {
			tokenInput: '',
			portalToken: null,
			authenticated: false,
			authError: null,
			invoices: [],
		}
	},
	methods: {
		async authenticate() {
			this.authError = null
			try {
				const url = generateUrl('/apps/shillinq/api/v1/portal/auth')
				const response = await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ token: this.tokenInput }),
				})
				if (response.ok) {
					this.authenticated = true
					this.portalToken = this.tokenInput
					this.fetchInvoices()
				} else {
					this.authError = t('shillinq', 'Invalid or expired token')
				}
			} catch (error) {
				this.authError = t('shillinq', 'Authentication failed')
			}
		},
		async fetchInvoices() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/portal/invoices')
				const response = await fetch(url, {
					headers: { 'X-Portal-Token': this.portalToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.invoices = data.results || []
				}
			} catch (error) {
				console.error('Failed to fetch invoices:', error)
			}
		},
	},
}
</script>

<style scoped>
.portal-invoice-list__header {
	margin-bottom: 16px;
}

.portal-invoice-list__auth {
	max-width: 400px;
	margin: 40px auto;
	text-align: center;
}

.portal-invoice-list__auth-form {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.portal-invoice-list__auth-form input {
	flex: 1;
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

.error {
	color: var(--color-error);
	margin-top: 8px;
}
</style>
