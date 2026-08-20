<!--
  Admin Invoice Detail

  Per-invoice detail view: header (creditor / recipient / dates), line item
  table (re-using InvoiceLineItemReview), totals, applied rate card and
  retainer, GL posting status, audit trail (Task 18).

  @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-18

  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="admin-invoice-detail">
		<header>
			<h2>{{ t('shillinq', 'Invoice') }} {{ invoice.invoiceNumber }}</h2>
			<router-link to="/invoice">
				{{ t('shillinq', 'Back to list') }}
			</router-link>
		</header>

		<dl class="admin-invoice-detail__meta">
			<dt>{{ t('shillinq', 'Billing model') }}</dt>
			<dd>{{ invoice.billingModel }}</dd>
			<dt>{{ t('shillinq', 'Customer') }}</dt>
			<dd>{{ invoice.customerId }}</dd>
			<dt>{{ t('shillinq', 'Project') }}</dt>
			<dd>{{ invoice.projectId || '—' }}</dd>
			<dt>{{ t('shillinq', 'Invoice date') }}</dt>
			<dd>{{ invoice.invoiceDate }}</dd>
			<dt>{{ t('shillinq', 'Due date') }}</dt>
			<dd>{{ invoice.dueDate }}</dd>
			<dt>{{ t('shillinq', 'Rate card') }}</dt>
			<dd>{{ invoice.rateCardId || '—' }}</dd>
			<dt>{{ t('shillinq', 'Retainer schedule') }}</dt>
			<dd>{{ invoice.retainerScheduleId || '—' }}</dd>
			<dt>{{ t('shillinq', 'Status') }}</dt>
			<dd>
				{{ invoice.status }}
				<span v-if="invoice.posted">({{ t('shillinq', 'Posted') }})</span>
			</dd>
			<dt>{{ t('shillinq', 'Obligation') }}</dt>
			<dd>{{ invoice.obligationId || '—' }}</dd>
		</dl>

		<InvoiceLineItemReview
			:lines="lines"
			:summary="invoice.summary || invoice" />

		<section v-if="auditTrail.length" class="admin-invoice-detail__audit">
			<h3>{{ t('shillinq', 'Audit trail') }}</h3>
			<ul>
				<li v-for="(event, idx) in auditTrail" :key="idx">
					<strong>{{ event.timestamp }}</strong> — {{ event.actor }}:
					{{ event.action }}
				</li>
			</ul>
		</section>

		<div class="admin-invoice-detail__actions">
			<button
				type="button"
				:disabled="invoice.status !== 'draft'"
				@click="post">
				{{ t('shillinq', 'Post to AR') }}
			</button>
			<button type="button" @click="pdf">
				{{ t('shillinq', 'Export PDF') }}
			</button>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import InvoiceLineItemReview from '../../components/invoice/InvoiceLineItemReview.vue'
import invoiceApi from '../../api/invoiceApi.js'

export default {
	name: 'AdminInvoiceDetail',

	components: { InvoiceLineItemReview },

	props: {
		invoiceId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			invoice: {},
			lines: [],
			auditTrail: [],
		}
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		t,
		async reload() {
			try {
				const detail = await invoiceApi.get(this.invoiceId)
				this.invoice = detail?.invoice || detail || {}
				this.lines = detail?.lines || []
				this.auditTrail = detail?.auditTrail || []
			} catch (e) {
				// Silent error — operator sees an empty page.
			}
		},

		async post() {
			await invoiceApi.post(this.invoiceId)
			await this.reload()
		},

		async pdf() {
			await invoiceApi.exportPdf(this.invoiceId)
		},
	},
}
</script>

<style scoped>
.admin-invoice-detail__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 12px 0;
}

.admin-invoice-detail__meta dt {
	font-weight: 600;
}

.admin-invoice-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
