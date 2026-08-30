<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Three-way Match Audit Trail Detail view (slice 11 of
 bookkeeping-purchase-order-3way, REQ-PO3W-010).

 Renders the deterministic, time-ordered lifecycle timeline for a single
 SupplierInvoice's 3-way-match chain (PO → approval → GRN → invoice →
 match → exception → GR/IR → payment). Every event shows its timestamp,
 actor, object reference + payload details. The footer carries the
 "Export package" button that calls POST /api/three-way-match/audit-trail/export
 and surfaces the returned package envelope (packageId, sha256,
 archived flag) so the operator can hand the ZIP to an external auditor.

 The component is a kind:"page" custom component registered against the
 invoice detail manifest slot. It is NOT a modal or dialog — no NcModal /
 NcDialog markup is used — so the modal-isolation hydra gate is satisfied
 by construction (ADR-004). All data is server-derived; the UI never
 trusts client-side actor / timestamp fields.

 @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
-->
<template>
	<div class="audit-trail" data-testid="audit-trail-detail">
		<header class="audit-trail__header">
			<h2 data-testid="audit-trail-title">
				{{ t('shillinq', 'Audit trail') }}
			</h2>
			<p class="audit-trail__hint">
				{{
					t(
						'shillinq',
						'Complete lifecycle history for this supplier invoice. Exportable as an immutable ZIP for external auditors (BW2 art 2:10, 7-year retention).',
					)
				}}
			</p>
		</header>

		<div
			v-if="loading"
			class="audit-trail__loading"
			data-testid="audit-trail-loading">
			{{ t('shillinq', 'Loading audit trail…') }}
		</div>

		<div
			v-else-if="error"
			class="audit-trail__error"
			data-testid="audit-trail-error">
			{{ error }}
		</div>

		<div v-else-if="ledger" class="audit-trail__body">
			<section class="audit-trail__summary" data-testid="audit-trail-summary">
				<div class="audit-trail__field">
					<span class="audit-trail__label">{{
						t('shillinq', 'Invoice')
					}}</span>
					<span
						class="audit-trail__value"
						data-testid="audit-trail-invoice-number"
						>{{ summaryInvoiceNumber }}</span
					>
				</div>
				<div class="audit-trail__field">
					<span class="audit-trail__label">{{
						t('shillinq', 'Supplier')
					}}</span>
					<span
						class="audit-trail__value"
						data-testid="audit-trail-supplier"
						>{{ summarySupplierId }}</span
					>
				</div>
				<div class="audit-trail__field">
					<span class="audit-trail__label">{{
						t('shillinq', 'Total (incl. VAT)')
					}}</span>
					<span class="audit-trail__value" data-testid="audit-trail-total">
						{{ formatMoney(summaryTotal) }} {{ summaryCurrency }}
					</span>
				</div>
				<div class="audit-trail__field">
					<span class="audit-trail__label">{{
						t('shillinq', 'Lifecycle events')
					}}</span>
					<span
						class="audit-trail__value"
						data-testid="audit-trail-event-count"
						>{{ events.length }}</span
					>
				</div>
			</section>

			<section
				class="audit-trail__timeline"
				data-testid="audit-trail-timeline">
				<ol class="audit-trail__events">
					<li
						v-for="(event, index) in events"
						:key="`${event.event}-${event.objectId}-${index}`"
						class="audit-trail__event"
						:class="eventClass(event)"
						data-testid="audit-trail-event">
						<div class="audit-trail__event-marker" />
						<div class="audit-trail__event-body">
							<div class="audit-trail__event-row">
								<span
									class="audit-trail__event-time"
									data-testid="audit-trail-event-time">
									{{ formatTimestamp(event.timestamp) }}
								</span>
								<span
									class="audit-trail__event-name"
									data-testid="audit-trail-event-name">
									{{ eventLabel(event.event) }}
								</span>
							</div>
							<div class="audit-trail__event-meta">
								<span data-testid="audit-trail-event-actor">
									{{ t('shillinq', 'Actor') }}:
									{{ event.actor || '—' }}
								</span>
								<span data-testid="audit-trail-event-object">
									{{ event.objectType }} #{{ event.objectId }}
								</span>
							</div>
							<div
								v-if="event.details && hasDetails(event.details)"
								class="audit-trail__event-details"
								data-testid="audit-trail-event-details">
								<dl>
									<!-- Vue 3 requires the v-for key on the <template> itself. -->
									<template
										v-for="(
											detailValue, detailKey
										) in event.details"
										:key="detailKey">
										<dt>
											{{ detailKey }}
										</dt>
										<dd>
											{{ formatDetailValue(detailValue) }}
										</dd>
									</template>
								</dl>
							</div>
						</div>
					</li>
				</ol>
			</section>

			<footer class="audit-trail__footer">
				<button
					type="button"
					class="audit-trail__export"
					:disabled="exporting"
					data-testid="audit-trail-export-button"
					@click="exportPackage">
					{{
						exporting
							? t('shillinq', 'Exporting…')
							: t('shillinq', 'Export audit package (ZIP)')
					}}
				</button>
				<div
					v-if="exportError"
					class="audit-trail__export-error"
					data-testid="audit-trail-export-error">
					{{ exportError }}
				</div>
				<div
					v-if="exportEnvelope"
					class="audit-trail__export-envelope"
					data-testid="audit-trail-export-envelope">
					<div class="audit-trail__field">
						<span class="audit-trail__label">{{
							t('shillinq', 'Package id')
						}}</span>
						<span
							class="audit-trail__value"
							data-testid="audit-trail-export-package-id">
							{{ exportEnvelope.packageId }}
						</span>
					</div>
					<div class="audit-trail__field">
						<span class="audit-trail__label">{{
							t('shillinq', 'SHA-256 (ledger)')
						}}</span>
						<code
							class="audit-trail__value"
							data-testid="audit-trail-export-sha256">
							{{ exportEnvelope.sha256 }}
						</code>
					</div>
					<div class="audit-trail__field">
						<span class="audit-trail__label">{{
							t('shillinq', 'Retention')
						}}</span>
						<span
							class="audit-trail__value"
							data-testid="audit-trail-export-retention">
							{{ exportEnvelope.retentionYears }}
							{{ t('shillinq', 'years (BW2 art 2:10)') }}
						</span>
					</div>
					<div class="audit-trail__field">
						<span class="audit-trail__label">{{
							t('shillinq', 'Events recorded')
						}}</span>
						<span
							class="audit-trail__value"
							data-testid="audit-trail-export-event-count">
							{{ exportEnvelope.eventCount }}
						</span>
					</div>
					<div class="audit-trail__field">
						<span class="audit-trail__label">{{
							t('shillinq', 'Archived to docudesk')
						}}</span>
						<span
							class="audit-trail__value"
							data-testid="audit-trail-export-archived">
							{{
								exportEnvelope.archived
									? t('shillinq', 'Yes')
									: t('shillinq', 'Pending')
							}}
						</span>
					</div>
				</div>
			</footer>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const EVENT_LABELS = {
	po_created: 'Purchase order created',
	po_approval_decision: 'Approval decision',
	po_sent_to_supplier: 'PO sent to supplier (Peppol)',
	grn_received: 'Goods received',
	grn_lifecycle_state: 'GRN lifecycle state',
	invoice_received: 'Supplier invoice received',
	invoice_lifecycle_state: 'Invoice lifecycle state',
	match_evaluated: 'Three-way match evaluated',
	match_resolved: 'Three-way match resolved',
}

export default {
	name: 'AuditTrailDetail',
	props: {
		/**
		 * SupplierInvoice id (from the route).
		 */
		invoiceId: {
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
			ledger: null,
			loading: true,
			error: '',
			exporting: false,
			exportError: '',
			exportEnvelope: null,
		}
	},

	computed: {
		events() {
			if (!this.ledger || !Array.isArray(this.ledger.events)) {
				return []
			}
			return this.ledger.events
		},

		summaryInvoiceNumber() {
			return (
				(this.ledger
					&& this.ledger.summary
					&& this.ledger.summary.invoiceNumber)
				|| '—'
			)
		},

		summarySupplierId() {
			return (
				(this.ledger
					&& this.ledger.summary
					&& this.ledger.summary.supplierId)
				|| '—'
			)
		},

		summaryTotal() {
			return (
				(this.ledger
					&& this.ledger.summary
					&& this.ledger.summary.totalInclVat)
				|| 0
			)
		},

		summaryCurrency() {
			return (
				(this.ledger && this.ledger.summary && this.ledger.summary.currency)
				|| 'EUR'
			)
		},
	},

	async created() {
		await this.loadLedger()
	},

	methods: {
		async loadLedger() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/three-way-match/audit-trail'),
					{
						params: {
							administrationId: this.administrationId,
							invoiceId: this.invoiceId,
						},
					},
				)
				this.ledger = response.data || null
			} catch (e) {
				this.error =
					(e && e.response && e.response.data && e.response.data.error)
					|| this.t('shillinq', 'Failed to load audit trail')
			} finally {
				this.loading = false
			}
		},

		async exportPackage() {
			this.exporting = true
			this.exportError = ''
			try {
				const response = await axios.post(
					generateUrl(
						'/apps/shillinq/api/three-way-match/audit-trail/export',
					),
					{
						administrationId: this.administrationId,
						invoiceId: this.invoiceId,
					},
				)
				this.exportEnvelope = response.data || null
			} catch (e) {
				this.exportError =
					(e && e.response && e.response.data && e.response.data.error)
					|| this.t('shillinq', 'Failed to export audit package')
			} finally {
				this.exporting = false
			}
		},

		eventLabel(event) {
			return this.t('shillinq', EVENT_LABELS[event] || event || 'Event')
		},

		eventClass(event) {
			const status = (event && event.event) || ''
			return {
				'audit-trail__event--match-resolved': status === 'match_resolved',
				'audit-trail__event--match-evaluated': status === 'match_evaluated',
				'audit-trail__event--invoice': status.startsWith('invoice_'),
				'audit-trail__event--po': status.startsWith('po_'),
				'audit-trail__event--grn': status.startsWith('grn_'),
			}
		},

		formatTimestamp(value) {
			if (!value) {
				return '—'
			}
			try {
				const date = new Date(value)
				if (Number.isNaN(date.getTime())) {
					return value
				}
				return date.toISOString().replace('T', ' ').slice(0, 19) + ' UTC'
			} catch (e) {
				return value
			}
		},

		formatMoney(cents) {
			const value = Number(cents || 0) / 100
			return value.toLocaleString('nl-NL', {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
		},

		formatDetailValue(value) {
			if (value === null || value === undefined) {
				return '—'
			}
			if (typeof value === 'object') {
				return JSON.stringify(value)
			}
			if (typeof value === 'boolean') {
				return value ? 'true' : 'false'
			}
			return String(value)
		},

		hasDetails(details) {
			if (!details || typeof details !== 'object') {
				return false
			}
			return Object.keys(details).length > 0
		},
	},
}
</script>

<style scoped>
.audit-trail {
	padding: var(--spacing-l, 16px);
}

.audit-trail__header h2 {
	margin: 0 0 4px;
}

.audit-trail__hint {
	color: var(--color-text-maxcontrast, #5a5a5a);
	margin-bottom: 12px;
}

.audit-trail__loading,
.audit-trail__error {
	padding: 12px 0;
	color: var(--color-text-maxcontrast, #5a5a5a);
}

.audit-trail__error {
	color: var(--color-error, #e9322d);
}

.audit-trail__summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 8px 16px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.audit-trail__field {
	display: flex;
	flex-direction: column;
}

.audit-trail__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #5a5a5a);
}

.audit-trail__value {
	font-weight: 600;
}

.audit-trail__events {
	list-style: none;
	padding: 0;
	margin: 16px 0 0;
}

.audit-trail__event {
	display: grid;
	grid-template-columns: 12px 1fr;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}

.audit-trail__event-marker {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	margin-top: 6px;
}

.audit-trail__event--match-resolved .audit-trail__event-marker {
	background: var(--color-success, #46ba61);
}

.audit-trail__event--match-evaluated .audit-trail__event-marker {
	background: var(--color-warning, #f1c40f);
}

.audit-trail__event-row {
	display: flex;
	gap: 12px;
	align-items: baseline;
}

.audit-trail__event-time {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #5a5a5a);
	min-width: 180px;
}

.audit-trail__event-name {
	font-weight: 600;
}

.audit-trail__event-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #5a5a5a);
}

.audit-trail__event-details {
	margin-top: 6px;
	background: var(--color-background-hover, #f8f8f8);
	padding: 8px;
	border-radius: var(--border-radius, 4px);
}

.audit-trail__event-details dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
	font-size: 0.85em;
}

.audit-trail__event-details dt {
	font-weight: 600;
}

.audit-trail__footer {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border, #ddd);
}

.audit-trail__export {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: none;
	border-radius: var(--border-radius, 4px);
	padding: 8px 16px;
	font-weight: 600;
	cursor: pointer;
}

.audit-trail__export[disabled] {
	opacity: 0.6;
	cursor: progress;
}

.audit-trail__export-error {
	margin-top: 8px;
	color: var(--color-error, #e9322d);
}

.audit-trail__export-envelope {
	margin-top: 16px;
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 8px 16px;
}
</style>
