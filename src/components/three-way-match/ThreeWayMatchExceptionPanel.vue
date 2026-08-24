<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Three-way Match exception resolution panel (slice 08 of
 bookkeeping-purchase-order-3way).

 Renders the side-by-side PO/GRN/Invoice comparison for an out-of-tolerance
 ThreeWayMatch (REQ-PO3W-005), surfaces the human-readable divergence
 details and exposes the three resolution disposition actions:

  - Accept with motivation       → POST /api/three-way-match/exceptions/accept
  - File dispute                 → POST /api/three-way-match/exceptions/dispute
  - Reject and block payment     → POST /api/three-way-match/exceptions/reject

 The panel itself is a kind:"page" custom component registered against
 the ThreeWayMatchDetail manifest page slot the slice-01 fragment already
 declares; it is not a modal/dialog (no NcModal/NcDialog markup is used)
 so the modal-isolation hydra gate is satisfied by construction. The
 resolution form is an inline block — the operator types a motivation
 once and the three buttons share the input.

 Server-authoritative posture: every disposition is enforced by the
 backend service (ADR-005); the UI does NOT trust client-side
 resolutionAction or resolvedBy. On success the response carries the
 updated ThreeWayMatch (the dispute response also carries the dispatch
 envelope) and the panel switches into the "resolved" state.

 @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
-->
<template>
	<div class="twm-exception" data-testid="twm-exception-panel">
		<div
			v-if="loading"
			class="twm-exception__loading"
			data-testid="twm-exception-loading">
			{{ t('shillinq', 'Loading match…') }}
		</div>

		<div
			v-else-if="error"
			class="twm-exception__error"
			data-testid="twm-exception-error">
			{{ error }}
		</div>

		<div v-else-if="match" class="twm-exception__body">
			<header class="twm-exception__header" data-testid="twm-exception-header">
				<h2>{{ t('shillinq', 'Match exception') }} #{{ match.id }}</h2>
				<p>
					<span class="twm-exception__pill" :class="statusPillClass">
						{{ statusLabel(match.matchStatus) }}
					</span>
					<span v-if="invoice">
						{{ t('shillinq', 'Invoice') }}: {{ invoice.invoiceNumber }}
					</span>
					<span v-if="match.createdAt">
						{{ t('shillinq', 'Created') }}:
						{{ formatTimestamp(match.createdAt) }}
					</span>
				</p>
			</header>

			<!-- side-by-side comparison: PO ↔ GRN ↔ Invoice (REQ-PO3W-005). -->
			<section
				class="twm-exception__comparison"
				data-testid="twm-exception-comparison">
				<h3>{{ t('shillinq', 'Side-by-side comparison') }}</h3>
				<table class="twm-exception__compare">
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'Field') }}</th>
							<th scope="col">
								{{ t('shillinq', 'Purchase Order') }}
							</th>
							<th scope="col">{{ t('shillinq', 'Goods Receipt') }}</th>
							<th scope="col">{{ t('shillinq', 'Invoice') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in comparisonRows"
							:key="row.field"
							:data-testid="`twm-exception-row-${row.field}`">
							<td>{{ row.label }}</td>
							<td>{{ row.po }}</td>
							<td>{{ row.grn }}</td>
							<td>{{ row.invoice }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- human-readable divergence breakdown (REQ-MATCH-003 fields). -->
			<section
				v-if="divergenceRows.length > 0"
				class="twm-exception__divergence"
				data-testid="twm-exception-divergence">
				<h3>{{ t('shillinq', 'Divergence details') }}</h3>
				<ul>
					<li
						v-for="(entry, index) in divergenceRows"
						:key="index"
						:data-testid="`twm-exception-div-${index}`">
						<strong>{{ entry.field }}</strong
						>: {{ t('shillinq', 'expected') }} {{ entry.expected }} ·
						{{ t('shillinq', 'actual') }} {{ entry.actual }}
						<span
							v-if="
								entry.deltaCents !== null
								&& entry.deltaCents !== undefined
							">
							· {{ t('shillinq', 'Δ') }}
							{{ formatMoney(entry.deltaCents) }}
						</span>
						<span
							v-if="
								entry.deltaPercentage !== null
								&& entry.deltaPercentage !== undefined
							">
							· {{ formatBasisPoints(entry.deltaPercentage) }}
						</span>
					</li>
				</ul>
			</section>

			<!-- resolved state: show the resolution. -->
			<section
				v-if="isResolved"
				class="twm-exception__resolved"
				data-testid="twm-exception-resolved">
				<h3>{{ t('shillinq', 'Resolved') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'Action') }}</dt>
					<dd>{{ resolutionActionLabel(match.resolutionAction) }}</dd>
					<dt>{{ t('shillinq', 'Resolved by') }}</dt>
					<dd>{{ match.resolvedBy || '—' }}</dd>
					<dt>{{ t('shillinq', 'Resolved at') }}</dt>
					<dd>{{ formatTimestamp(match.resolvedAt) }}</dd>
					<dt>{{ t('shillinq', 'Notes') }}</dt>
					<dd>{{ match.resolutionNotes || '—' }}</dd>
				</dl>
				<p
					v-if="dispatch && dispatch.dispatchId"
					data-testid="twm-exception-dispatch-id">
					{{ t('shillinq', 'CreditNote dispatch') }}:
					{{ dispatch.dispatchId }}
				</p>
			</section>

			<!-- open state: resolution form with three action buttons + shared notes input. -->
			<section
				v-else
				class="twm-exception__actions"
				data-testid="twm-exception-actions">
				<h3>{{ t('shillinq', 'Resolution') }}</h3>
				<p class="twm-exception__hint">
					{{
						t(
							'shillinq',
							'Payment is blocked until this exception is resolved.',
						)
					}}
				</p>
				<label class="twm-exception__notes-label" for="twm-exception-notes">
					{{ t('shillinq', 'Motivation / reason') }}
				</label>
				<textarea
					id="twm-exception-notes"
					v-model="notes"
					data-testid="twm-exception-notes"
					rows="3"
					maxlength="2000"
					:placeholder="
						t(
							'shillinq',
							'Document the motivation, dispute reason or rejection reason.',
						)
					" />

				<div
					v-if="actionError"
					class="twm-exception__action-error"
					data-testid="twm-exception-action-error">
					{{ actionError }}
				</div>

				<div class="twm-exception__buttons">
					<button
						type="button"
						class="primary"
						data-testid="twm-exception-accept"
						:disabled="submitting || notes.trim() === ''"
						@click="onAccept">
						{{ t('shillinq', 'Accept with motivation') }}
					</button>
					<button
						type="button"
						data-testid="twm-exception-dispute"
						:disabled="submitting || notes.trim() === ''"
						@click="onDispute">
						{{ t('shillinq', 'File dispute (UBL CreditNote)') }}
					</button>
					<button
						type="button"
						class="danger"
						data-testid="twm-exception-reject"
						:disabled="submitting || notes.trim() === ''"
						@click="onReject">
						{{ t('shillinq', 'Reject and block payment') }}
					</button>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

const EXCEPTION_STATUSES = [
	'exception_price',
	'exception_quantity',
	'exception_missing_grn',
	'exception_missing_po',
	'fraud_alert',
]

export default {
	name: 'ThreeWayMatchExceptionPanel',
	props: {
		/**
		 * ThreeWayMatch id (from the route).
		 */
		id: {
			type: String,
			required: true,
		},

		/**
		 * Administration scope — supplied by the parent shell.
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			match: null,
			invoice: null,
			po: null,
			grn: null,
			notes: '',
			loading: true,
			submitting: false,
			error: '',
			actionError: '',
			dispatch: null,
		}
	},

	computed: {
		isResolved() {
			if (!this.match) {
				return false
			}
			return !!(this.match.resolutionAction || this.match.resolvedAt)
		},

		statusPillClass() {
			return this.match ? `twm-exception__pill--${this.match.matchStatus}` : ''
		},

		comparisonRows() {
			const po = this.po || {}
			const grn = this.grn || {}
			const invoice = this.invoice || {}
			return [
				{
					field: 'reference',
					label: this.t('shillinq', 'Reference'),
					po: po.poNumber || '—',
					grn: grn.grnNumber || '—',
					invoice: invoice.invoiceNumber || '—',
				},
				{
					field: 'date',
					label: this.t('shillinq', 'Date'),
					po: this.formatDate(po.expectedDeliveryDate),
					grn: this.formatTimestamp(grn.receivedAt),
					invoice: this.formatDate(invoice.invoiceDate),
				},
				{
					field: 'amount',
					label: this.t('shillinq', 'Amount (incl. VAT)'),
					po: this.formatMoney(po.totalInclVat),
					grn: '—',
					invoice: this.formatMoney(invoice.totalInclVat),
				},
				{
					field: 'vat',
					label: this.t('shillinq', 'VAT'),
					po: this.formatMoney(po.totalVat),
					grn: '—',
					invoice: this.formatMoney(invoice.totalVat),
				},
			]
		},

		divergenceRows() {
			if (!this.match || !Array.isArray(this.match.divergenceDetails)) {
				return []
			}
			return this.match.divergenceDetails
		},
	},

	async created() {
		await this.loadMatch()
	},

	methods: {
		async loadMatch() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/ThreeWayMatch/${this.id}`,
					),
				)
				this.match = response.data || null
				if (this.match) {
					await this.loadRelated()
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load match')
			} finally {
				this.loading = false
			}
		},

		async loadRelated() {
			// best-effort related-record load — the panel still renders the
			// comparison block with placeholders when a PO or GRN cannot be
			// resolved (e.g. exception_missing_po).
			const tasks = []
			if (this.match.invoiceId) {
				tasks.push(this.loadInvoice())
			}
			const matchedPoIds = Array.isArray(this.match.matchedPoIds)
				? this.match.matchedPoIds
				: []
			if (matchedPoIds.length > 0) {
				tasks.push(this.loadPo(matchedPoIds[0]))
			}
			const matchedGrnIds = Array.isArray(this.match.matchedGrnIds)
				? this.match.matchedGrnIds
				: []
			if (matchedGrnIds.length > 0) {
				tasks.push(this.loadGrn(matchedGrnIds[0]))
			}
			await Promise.all(tasks)
		},

		async loadInvoice() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/SupplierInvoice/${this.match.invoiceId}`,
					),
				)
				this.invoice = response.data || null
			} catch (e) {
				this.invoice = null
			}
		},

		async loadPo(poId) {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/PurchaseOrder/${poId}`,
					),
				)
				this.po = response.data || null
			} catch (e) {
				this.po = null
			}
		},

		async loadGrn(grnId) {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/GoodsReceiptNote/${grnId}`,
					),
				)
				this.grn = response.data || null
			} catch (e) {
				this.grn = null
			}
		},

		async onAccept() {
			await this.submitResolution('/api/three-way-match/exceptions/accept', {
				resolutionNotes: this.notes.trim(),
			})
		},

		async onDispute() {
			await this.submitResolution('/api/three-way-match/exceptions/dispute', {
				disputeReason: this.notes.trim(),
			})
		},

		async onReject() {
			await this.submitResolution('/api/three-way-match/exceptions/reject', {
				rejectionReason: this.notes.trim(),
			})
		},

		async submitResolution(path, extra) {
			if (this.notes.trim() === '') {
				this.actionError = this.t(
					'shillinq',
					'A motivation / reason is required.',
				)
				return
			}
			this.submitting = true
			this.actionError = ''
			try {
				const response = await axios.post(
					generateUrl(`/apps/shillinq${path}`),
					{
						administrationId: this.administrationId,
						matchId: this.id,
						...extra,
					},
				)
				// dispute returns {match, dispatch}; the others return the
				// ThreeWayMatch directly.
				if (response.data && response.data.match) {
					this.match = response.data.match
					this.dispatch = response.data.dispatch || null
				} else {
					this.match = response.data
				}
				this.notes = ''
			} catch (e) {
				this.actionError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Resolution failed')
			} finally {
				this.submitting = false
			}
		},

		statusLabel(matchStatus) {
			const labels = {
				exception_price: this.t('shillinq', 'Price exception'),
				exception_quantity: this.t('shillinq', 'Quantity exception'),
				exception_missing_grn: this.t('shillinq', 'GRN missing'),
				exception_missing_po: this.t('shillinq', 'PO missing'),
				fraud_alert: this.t('shillinq', 'Fraud alert'),
				auto_approved: this.t('shillinq', 'Auto approved'),
				within_tolerance: this.t('shillinq', 'Within tolerance'),
			}
			return labels[matchStatus] || matchStatus || '—'
		},

		resolutionActionLabel(action) {
			const labels = {
				accepted: this.t('shillinq', 'Accepted with motivation'),
				rejected: this.t('shillinq', 'Rejected — payment blocked'),
				credit_note_requested: this.t(
					'shillinq',
					'Dispute filed (UBL CreditNote)',
				),

				supplier_contacted: this.t('shillinq', 'Supplier contacted'),
				po_adjusted: this.t('shillinq', 'PO adjusted'),
				tolerance_override: this.t('shillinq', 'Tolerance override'),
			}
			return labels[action] || action || '—'
		},

		formatMoney(cents) {
			if (cents === null || cents === undefined) {
				return '—'
			}
			const currency = this.invoice?.currency || this.po?.currency || 'EUR'
			return `${currency} ${(Number(cents) / 100).toFixed(2)}`
		},

		formatBasisPoints(bps) {
			if (bps === null || bps === undefined) {
				return '—'
			}
			return `${(Number(bps) / 100).toFixed(2)}%`
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
// Exception status constants — kept around for the parent shell so it can
// pre-filter the listing before mounting this panel.
export { EXCEPTION_STATUSES }
</script>

<style scoped>
.twm-exception {
	padding: 16px;
}

.twm-exception__header span {
	margin-right: 12px;
}

.twm-exception__pill {
	padding: 2px 6px;
	border-radius: 12px;
	background: var(--color-background-dark, #ddd);
	margin-right: 8px;
}

.twm-exception__pill--exception_price,
.twm-exception__pill--exception_quantity,
.twm-exception__pill--exception_missing_grn,
.twm-exception__pill--exception_missing_po {
	background: var(--color-warning, #c93);
	color: #fff;
}

.twm-exception__pill--fraud_alert {
	background: var(--color-error, #c33);
	color: #fff;
}

.twm-exception__comparison table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.twm-exception__comparison th,
.twm-exception__comparison td {
	border: 1px solid var(--color-border, #ccc);
	padding: 6px 8px;
	text-align: left;
}

.twm-exception__divergence ul {
	padding-left: 20px;
}

.twm-exception__notes-label {
	display: block;
	margin-top: 8px;
	margin-bottom: 4px;
	font-weight: bold;
}

.twm-exception__actions textarea {
	width: 100%;
	font-family: inherit;
}

.twm-exception__hint {
	color: var(--color-warning, #c93);
	font-style: italic;
}

.twm-exception__action-error {
	color: var(--color-error, #c33);
	margin-top: 8px;
}

.twm-exception__buttons {
	display: flex;
	gap: 8px;
	margin-top: 12px;
	flex-wrap: wrap;
}

.twm-exception__buttons button {
	padding: 6px 12px;
}
</style>
