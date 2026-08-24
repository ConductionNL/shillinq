<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Supplier Invoice detail view (slice 05 of bookkeeping-purchase-order-3way).

 Renders the SupplierInvoice header (supplier, invoice number, invoice +
 due dates, totals), the parsed line items (from the embedded `lines`
 array populated by ingestUBLInvoice / ingestPDFInvoice), an OCR
 confidence indicator (only shown when the invoice was ingested via PDF /
 OCR), the UBL / Peppol provenance (when the invoice was ingested via the
 Peppol path) and the related ThreeWayMatch status (populated by slice
 06's matching engine — empty-state placeholder on slice 05).

 Server-authoritative posture: this view is read-only. Lifecycle
 transitions (setStatus on the service) are not exposed from the UI at
 slice 05; the matching engine in slice 06 owns the move out of
 `received`.

 @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
-->
<template>
	<div class="si-detail">
		<div
			v-if="loading"
			class="si-detail__loading"
			data-testid="si-detail-loading">
			{{ t('shillinq', 'Loading supplier invoice…') }}
		</div>

		<div
			v-else-if="error"
			class="si-detail__error"
			data-testid="si-detail-error">
			{{ error }}
		</div>

		<div v-else-if="invoice" class="si-detail__body">
			<header class="si-detail__header" data-testid="si-detail-header">
				<h2>{{ invoice.invoiceNumber }}</h2>
				<p>
					<span
						class="si-detail__pill"
						:class="`si-detail__pill--${invoice.statusCode}`">
						{{ statusLabel(invoice.statusCode) }}
					</span>
					<span
						>{{ t('shillinq', 'Supplier') }}:
						{{ invoice.supplierId }}</span
					>
					<span v-if="invoice.invoiceDate">
						{{ t('shillinq', 'Invoice date') }}:
						{{ formatDate(invoice.invoiceDate) }}
					</span>
					<span v-if="invoice.dueDate">
						{{ t('shillinq', 'Due') }}: {{ formatDate(invoice.dueDate) }}
					</span>
				</p>
				<p>
					{{ t('shillinq', 'Total (incl. VAT)') }}:
					<strong>{{ formatMoney(invoice.totalInclVat) }}</strong>
					<span
						v-if="
							invoice.totalExclVat !== null
							&& invoice.totalExclVat !== undefined
						">
						({{ t('shillinq', 'excl.') }}
						{{ formatMoney(invoice.totalExclVat) }} ·
						{{ t('shillinq', 'VAT') }}
						{{ formatMoney(invoice.totalVat) }})
					</span>
				</p>
			</header>

			<section
				v-if="isPdfIngestion"
				class="si-detail__ocr"
				data-testid="si-detail-ocr">
				<h3>{{ t('shillinq', 'OCR confidence') }}</h3>
				<div class="si-detail__ocr-row">
					<div class="si-detail__ocr-meter" :title="ocrPercent">
						<div
							class="si-detail__ocr-meter-fill"
							:class="`si-detail__ocr-meter-fill--${ocrConfidenceTier}`"
							:style="{ width: ocrPercent }" />
					</div>
					<span class="si-detail__ocr-value">{{ ocrPercent }}</span>
					<span class="si-detail__ocr-tier">{{
						ocrConfidenceTierLabel
					}}</span>
				</div>
				<p
					v-if="isLowConfidence"
					class="si-detail__ocr-hint"
					data-testid="si-detail-ocr-low">
					{{
						t(
							'shillinq',
							'Low OCR confidence — lines will route to manual confirmation downstream.',
						)
					}}
				</p>
			</section>

			<section
				v-if="isUblIngestion"
				class="si-detail__provenance"
				data-testid="si-detail-provenance">
				<h3>{{ t('shillinq', 'Peppol / UBL provenance') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'UBL source') }}</dt>
					<dd>
						{{ invoice.ublSourceUri || t('shillinq', '(not recorded)') }}
					</dd>
					<dt>{{ t('shillinq', 'Received via Peppol') }}</dt>
					<dd>{{ formatTimestamp(invoice.peppolReceivedAt) }}</dd>
				</dl>
			</section>

			<section class="si-detail__lines">
				<h3>{{ t('shillinq', 'Line items') }}</h3>
				<table v-if="(invoice.lines || []).length > 0">
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col">{{ t('shillinq', 'Product code') }}</th>
							<th scope="col">{{ t('shillinq', 'Description') }}</th>
							<th scope="col">{{ t('shillinq', 'Quantity') }}</th>
							<th scope="col">{{ t('shillinq', 'Unit price') }}</th>
							<th scope="col">{{ t('shillinq', 'VAT rate') }}</th>
							<th scope="col">{{ t('shillinq', 'Line total') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="line in invoice.lines || []"
							:key="line.lineNumber"
							:data-testid="`si-detail-line-${line.lineNumber}`">
							<td>{{ line.lineNumber }}</td>
							<td>{{ line.productCode || '—' }}</td>
							<td>{{ line.description || '—' }}</td>
							<td>{{ line.quantity }}</td>
							<td>{{ formatMoney(line.unitPrice) }}</td>
							<td>{{ formatPct(line.vatRate) }}</td>
							<td>{{ formatMoney(line.lineExtension) }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="si-detail__lines-empty">
					{{ t('shillinq', 'No line items recorded.') }}
				</p>
			</section>

			<section class="si-detail__matches" data-testid="si-detail-matches">
				<h3>{{ t('shillinq', '3-way match status') }}</h3>
				<ul v-if="matches.length > 0">
					<li v-for="match in matches" :key="match.id">
						<router-link
							:to="{
								name: 'ThreeWayMatchDetail',
								params: { id: match.id },
							}">
							{{ match.matchReference || match.id }}
						</router-link>
						<span class="si-detail__match-status">{{
							match.matchStatus || '—'
						}}</span>
					</li>
				</ul>
				<p v-else class="si-detail__matches-empty">
					{{
						t('shillinq', 'No match yet — awaiting the matching engine.')
					}}
				</p>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'SupplierInvoiceDetail',
	props: {
		/**
		 * SupplierInvoice id from the route.
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			invoice: null,
			matches: [],
			loading: true,
			error: '',
		}
	},

	computed: {
		isPdfIngestion() {
			if (!this.invoice) {
				return false
			}
			if (this.invoice.sourceFormat === 'pdf') {
				return true
			}
			// Defensive: a non-null ocrConfidenceScore implies the PDF/OCR path
			// even when sourceFormat is absent (older ingestion runs).
			return (
				this.invoice.ocrConfidenceScore !== null
				&& this.invoice.ocrConfidenceScore !== undefined
			)
		},

		isUblIngestion() {
			if (!this.invoice) {
				return false
			}
			if (this.invoice.sourceFormat === 'ubl') {
				return true
			}
			return !!this.invoice.ublSourceUri || !!this.invoice.peppolReceivedAt
		},

		ocrPercent() {
			const score = Number(this.invoice?.ocrConfidenceScore || 0)
			return `${Math.round(score * 100)}%`
		},

		ocrConfidenceTier() {
			const score = Number(this.invoice?.ocrConfidenceScore || 0)
			if (score >= 0.9) {
				return 'high'
			}
			if (score >= 0.7) {
				return 'medium'
			}
			return 'low'
		},

		ocrConfidenceTierLabel() {
			const map = {
				high: this.t('shillinq', 'High'),
				medium: this.t('shillinq', 'Medium'),
				low: this.t('shillinq', 'Low'),
			}
			return map[this.ocrConfidenceTier]
		},

		isLowConfidence() {
			return this.ocrConfidenceTier === 'low'
		},
	},

	async created() {
		await this.loadInvoice()
	},

	methods: {
		async loadInvoice() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/SupplierInvoice/${this.id}`,
					),
				)
				this.invoice = response.data || null
				if (this.invoice) {
					await this.loadMatches()
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load supplier invoice')
			} finally {
				this.loading = false
			}
		},

		async loadMatches() {
			// ThreeWayMatch records are populated by slice 06; gracefully fall
			// through to the empty state when the lookup isn't available yet.
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/ThreeWayMatch`,
					),
					{ params: { filter: { invoiceId: this.id } } },
				)
				this.matches = response.data?.results || response.data || []
			} catch (e) {
				this.matches = []
			}
		},

		statusLabel(statusCode) {
			const labels = {
				received: this.t('shillinq', 'Received'),
				matching: this.t('shillinq', 'Matching'),
				matched: this.t('shillinq', 'Matched'),
				exception: this.t('shillinq', 'Exception'),
				approved: this.t('shillinq', 'Approved'),
				paid: this.t('shillinq', 'Paid'),
				rejected: this.t('shillinq', 'Rejected'),
			}
			return labels[statusCode] || statusCode || '—'
		},

		formatMoney(cents) {
			if (cents === null || cents === undefined) {
				return '—'
			}
			const currency = this.invoice?.currency || 'EUR'
			return `${currency} ${(Number(cents) / 100).toFixed(2)}`
		},

		formatPct(rate) {
			return `${(Number(rate || 0) * 100).toFixed(2)}%`
		},

		formatDate(iso) {
			if (!iso) {
				return '—'
			}
			try {
				return new Date(iso).toLocaleDateString()
			} catch (e) {
				return iso
			}
		},

		formatTimestamp(iso) {
			if (!iso) {
				return '—'
			}
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.si-detail {
	padding: 16px;
}

.si-detail__header span {
	margin-right: 12px;
}

.si-detail__pill {
	padding: 2px 6px;
	border-radius: 12px;
	background: var(--color-background-dark, #ddd);
	margin-right: 8px;
}

.si-detail__pill--received {
	background: var(--color-primary, #36c);
	color: #fff;
}

.si-detail__pill--matching,
.si-detail__pill--matched {
	background: var(--color-success, #2c2);
	color: #fff;
}

.si-detail__pill--exception {
	background: var(--color-warning, #c93);
	color: #fff;
}

.si-detail__pill--rejected {
	background: var(--color-error, #c33);
	color: #fff;
}

.si-detail__ocr-row {
	display: flex;
	align-items: center;
	gap: 12px;
}

.si-detail__ocr-meter {
	flex: 1;
	height: 12px;
	background: var(--color-background-dark, #ddd);
	border-radius: 6px;
	overflow: hidden;
	max-width: 360px;
}

.si-detail__ocr-meter-fill {
	height: 100%;
}

.si-detail__ocr-meter-fill--high {
	background: var(--color-success, #2c2);
}

.si-detail__ocr-meter-fill--medium {
	background: var(--color-warning, #c93);
}

.si-detail__ocr-meter-fill--low {
	background: var(--color-error, #c33);
}

.si-detail__ocr-hint {
	color: var(--color-warning, #c93);
	font-size: 0.9em;
}

.si-detail__lines table {
	width: 100%;
	border-collapse: collapse;
}

.si-detail__lines th,
.si-detail__lines td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.si-detail__provenance dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
}

.si-detail__error {
	background: var(--color-error, #c33);
	color: #fff;
	padding: 8px;
	margin: 8px 0;
}

.si-detail__match-status {
	margin-left: 12px;
	color: var(--color-text-maxcontrast, #666);
}
</style>
