<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Three-Way Match Index view (slice 06 of bookkeeping-purchase-order-3way).

 Renders a filterable table of ThreeWayMatch records — every row exposes
 the supplier invoice id, the match status (auto_approved /
 within_tolerance / exception_*), the linked PO + GRN ids, the cost-centre
 + project-code dimensions and a "Re-evaluate" quick-action button that
 calls back into the matching engine via POST /api/three-way-matches/evaluate.

 The view is read-only beyond the re-evaluate button — exception resolution
 (accept / reject / credit-note-request / supplier-contact) lives in slice
 08's ThreeWayMatchExceptionPanel. The Re-evaluate button is intentionally
 enabled for matches in any status so the AP team can retrigger after a
 ToleranceProfile change, which is the most common reason for re-running
 the matcher in practice.

 Filtering: a single match_status dropdown narrows the row set in-memory
 (the row count is bounded by the AP team's daily invoice volume so an
 in-memory filter is well-sized; downstream slice 11's audit-trail
 export takes the back-end pagination route).

 @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
-->
<template>
	<div class="twm-index">
		<header class="twm-index__header">
			<h2 data-testid="twm-index-title">
				{{ t('shillinq', 'Three-way matches') }}
			</h2>
			<p class="twm-index__hint">
				{{
					t(
						'shillinq',
						'Every supplier invoice scored against its purchase order(s) and goods receipt note(s) by the matching engine.',
					)
				}}
			</p>
		</header>

		<div class="twm-index__filters" data-testid="twm-index-filters">
			<label class="twm-index__filter-label" for="twm-status-filter">
				{{ t('shillinq', 'Match status') }}
			</label>
			<select
				id="twm-status-filter"
				v-model="statusFilter"
				data-testid="twm-status-filter">
				<option value="">
					{{ t('shillinq', 'All statuses') }}
				</option>
				<option
					v-for="opt in statusOptions"
					:key="opt.value"
					:value="opt.value">
					{{ opt.label }}
				</option>
			</select>
		</div>

		<div
			v-if="loading"
			class="twm-index__loading"
			data-testid="twm-index-loading">
			{{ t('shillinq', 'Loading three-way matches…') }}
		</div>

		<div
			v-else-if="error"
			class="twm-index__error"
			data-testid="twm-index-error">
			{{ error }}
		</div>

		<CnDataTable
			v-else
			class="twm-index__table"
			data-testid="twm-index-table"
			:columns="columns"
			:rows="filteredMatches"
			:emptyLabel="t('shillinq', 'No matches recorded yet.')">
			<template #cell-invoice="{ row }">
				<router-link
					:to="{
						name: 'SupplierInvoiceDetail',
						params: { id: row.invoiceId },
					}">
					{{ supplierInvoiceLabel(row) }}
				</router-link>
			</template>
			<template #cell-supplier="{ row }">
				{{ supplierLabel(row) }}
			</template>
			<template #cell-amount="{ row }">
				{{ amountLabel(row) }}
			</template>
			<template #cell-matchDate="{ row }">
				{{ formatDate(row.createdAt) }}
			</template>
			<template #cell-matchStatus="{ row }">
				<span
					class="twm-index__pill"
					:class="`twm-index__pill--${row.matchStatus}`"
					:data-testid="`twm-status-${row.id}`">
					{{ statusLabel(row.matchStatus) }}
				</span>
			</template>
			<template #cell-refs="{ row }">
				<span class="twm-index__refs">
					<span v-if="(row.matchedPoIds || []).length > 0">
						{{ t('shillinq', 'PO') }}:
						{{ (row.matchedPoIds || []).join(', ') }}
					</span>
					<span v-if="(row.matchedGrnIds || []).length > 0">
						{{ t('shillinq', 'GRN') }}:
						{{ (row.matchedGrnIds || []).join(', ') }}
					</span>
				</span>
			</template>
			<template #cell-actions="{ row }">
				<button
					type="button"
					class="twm-index__action"
					:data-testid="`twm-reevaluate-${row.id}`"
					:disabled="reevaluating === row.id"
					@click="reevaluate(row)">
					{{
						reevaluating === row.id
							? t('shillinq', 'Evaluating…')
							: t('shillinq', 'Re-evaluate')
					}}
				</button>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'ThreeWayMatchIndex',
	components: {
		CnDataTable,
	},

	props: {
		/**
		 * Administration scope (server-resolved at the call site; the
		 * matching engine derives its own scope from the persisted invoice
		 * but the trigger endpoint asserts the caller can see this
		 * administration).
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			matches: [],
			invoices: {},
			loading: true,
			error: '',
			statusFilter: '',
			reevaluating: '',
		}
	},

	computed: {
		/**
		 * CnDataTable column definitions for the three-way-match list.
		 *
		 * @spec openspec/specs/list-views-cndatatable/spec.md
		 * @return {Array<object>} ordered column defs
		 */
		columns() {
			return [
				{
					key: 'invoice',
					label: this.t('shillinq', 'Invoice'),
					sortable: false,
				},
				{
					key: 'supplier',
					label: this.t('shillinq', 'Supplier'),
					sortable: false,
				},
				{
					key: 'amount',
					label: this.t('shillinq', 'Amount'),
					sortable: false,
				},
				{
					key: 'matchDate',
					label: this.t('shillinq', 'Match date'),
					sortable: false,
				},
				{
					key: 'matchStatus',
					label: this.t('shillinq', 'Status'),
					sortable: true,
				},
				{
					key: 'refs',
					label: this.t('shillinq', 'Linked PO / GRN'),
					sortable: false,
				},
				{ key: 'actions', label: '', sortable: false },
			]
		},

		statusOptions() {
			return [
				{
					value: 'auto_approved',
					label: this.t('shillinq', 'Auto-approved'),
				},
				{
					value: 'within_tolerance',
					label: this.t('shillinq', 'Within tolerance'),
				},
				{
					value: 'exception_price',
					label: this.t('shillinq', 'Price exception'),
				},
				{
					value: 'exception_quantity',
					label: this.t('shillinq', 'Quantity exception'),
				},
				{
					value: 'exception_missing_grn',
					label: this.t('shillinq', 'Missing GRN'),
				},
				{
					value: 'exception_missing_po',
					label: this.t('shillinq', 'Missing PO'),
				},
				{ value: 'fraud_alert', label: this.t('shillinq', 'Fraud alert') },
			]
		},

		filteredMatches() {
			if (!this.statusFilter) {
				return this.matches
			}
			return this.matches.filter(
				(match) => match.matchStatus === this.statusFilter,
			)
		},
	},

	async created() {
		await this.loadMatches()
	},

	methods: {
		async loadMatches() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/ThreeWayMatch`,
					),
				)
				const rows = response.data?.results || response.data || []
				this.matches = Array.isArray(rows) ? rows : []

				// Pre-fetch invoice headers for the supplier + amount columns.
				// One bulk call when the OR API supports an `id IN (..)` filter;
				// otherwise we fall back to silent best-effort.
				const invoiceIds = [
					...new Set(this.matches.map((m) => m.invoiceId).filter(Boolean)),
				]
				for (const id of invoiceIds) {
					try {
						const inv = await axios.get(
							generateUrl(
								`/apps/openregister/api/objects/${REGISTER_SLUG}/SupplierInvoice/${id}`,
							),
						)
						this.invoices[id] = inv.data || null
					} catch (e) {
						this.invoices[id] = null
					}
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load three-way matches')
			} finally {
				this.loading = false
			}
		},

		async reevaluate(match) {
			if (!match.invoiceId) {
				return
			}
			this.reevaluating = match.id
			try {
				const response = await axios.post(
					generateUrl('/apps/shillinq/api/three-way-matches/evaluate'),
					{
						administrationId:
							this.administrationId || match.administrationId,
						invoiceId: match.invoiceId,
					},
				)
				// Replace the row in-place when the engine returned an updated
				// record; otherwise reload the whole list as a safety net.
				const updated = response.data
				if (updated && updated.id) {
					const i = this.matches.findIndex(
						(m) => m.id === match.id || m.id === updated.id,
					)
					if (i >= 0) {
						this.matches.splice(i, 1, updated)
					} else {
						this.matches.unshift(updated)
					}
				} else {
					await this.loadMatches()
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Re-evaluation failed')
			} finally {
				this.reevaluating = ''
			}
		},

		supplierInvoiceLabel(match) {
			const invoice = this.invoices[match.invoiceId]
			if (invoice && invoice.invoiceNumber) {
				return invoice.invoiceNumber
			}
			return match.invoiceId || '—'
		},

		supplierLabel(match) {
			const invoice = this.invoices[match.invoiceId]
			return invoice?.supplierId || '—'
		},

		amountLabel(match) {
			const invoice = this.invoices[match.invoiceId]
			if (
				!invoice
				|| invoice.totalInclVat === null
				|| invoice.totalInclVat === undefined
			) {
				return '—'
			}
			const currency = invoice.currency || 'EUR'
			return `${currency} ${(Number(invoice.totalInclVat) / 100).toFixed(2)}`
		},

		statusLabel(statusCode) {
			const labels = {
				auto_approved: this.t('shillinq', 'Auto-approved'),
				within_tolerance: this.t('shillinq', 'Within tolerance'),
				exception_price: this.t('shillinq', 'Price exception'),
				exception_quantity: this.t('shillinq', 'Quantity exception'),
				exception_missing_grn: this.t('shillinq', 'Missing GRN'),
				exception_missing_po: this.t('shillinq', 'Missing PO'),
				fraud_alert: this.t('shillinq', 'Fraud alert'),
			}
			return labels[statusCode] || statusCode || '—'
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
	},
}
</script>

<style scoped>
.twm-index {
	padding: 1rem;
}

.twm-index__header h2 {
	margin: 0 0 0.25rem 0;
}

.twm-index__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 1rem 0;
}

.twm-index__filters {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.twm-index__filter-label {
	font-weight: 600;
}

.twm-index__loading,
.twm-index__error,
.twm-index__empty {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.twm-index__error {
	color: var(--color-error);
}

.twm-index__table {
	width: 100%;
	border-collapse: collapse;
}

.twm-index__table th,
.twm-index__table td {
	padding: 0.5rem 0.75rem;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
	vertical-align: top;
}

.twm-index__pill {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.875rem;
	background: var(--color-background-hover);
}

.twm-index__pill--auto_approved,
.twm-index__pill--within_tolerance {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.twm-index__pill--exception_price,
.twm-index__pill--exception_quantity,
.twm-index__pill--exception_missing_grn,
.twm-index__pill--exception_missing_po {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.twm-index__pill--fraud_alert {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.twm-index__refs {
	font-size: 0.875rem;
	color: var(--color-text-lighter);
}

.twm-index__action {
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: 0;
	cursor: pointer;
}

.twm-index__action:disabled {
	opacity: 0.6;
	cursor: progress;
}
</style>
