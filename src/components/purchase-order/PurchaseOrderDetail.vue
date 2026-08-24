<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Purchase Order detail view (slice 02 of bookkeeping-purchase-order-3way).
 Renders the PO header, line items, the materialised approval chain with
 per-approver status + signature timestamps, and the (initially empty) lists
 of related GoodsReceiptNotes + ThreeWayMatches that future slices (04, 06)
 will populate. The "Send to supplier" button stays disabled while any chain
 entry is still pending — the server enforces this guard too (ADR-005).

 Slice 03 (bookkeeping-purchase-order-3way-03-peppol-transmission) replaces
 the generic "Send to supplier" action with a paired "Send via Peppol /
 PDF+email" control. Both call the server-authoritative transmit endpoints
 (POST /api/purchase-orders/{id}/transmit/peppol|email) — the client never
 forges peppolMessageId nor bypasses the approval-complete precondition
 (ADR-005). The view reflects peppolSentAt / peppolMessageId on success and
 peppolFallbackReason whenever the supplier was not Peppol-registered.

 @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 @spec openspec/changes/bookkeeping-purchase-order-3way-03-peppol-transmission/tasks.md
-->
<template>
	<div class="po-detail">
		<div v-if="loading" class="po-detail__loading">
			{{ t('shillinq', 'Loading purchase order…') }}
		</div>

		<div
			v-else-if="error"
			class="po-detail__error"
			data-testid="po-detail-error">
			{{ error }}
		</div>

		<div v-else-if="purchaseOrder" class="po-detail__body">
			<header class="po-detail__header" data-testid="po-detail-header">
				<h2>{{ purchaseOrder.poNumber }}</h2>
				<p>
					<span class="po-detail__pill">{{
						purchaseOrder.lifecycleState
					}}</span>
					<span
						>{{ t('shillinq', 'Supplier') }}:
						{{ purchaseOrder.supplierId }}</span
					>
					<span
						>{{ t('shillinq', 'Cost center') }}:
						{{ purchaseOrder.costCenter }}</span
					>
					<span v-if="purchaseOrder.projectCode"
						>{{ t('shillinq', 'Project') }}:
						{{ purchaseOrder.projectCode }}</span
					>
				</p>
				<p>
					{{ t('shillinq', 'Total') }}:
					<strong>{{ formatMoney(purchaseOrder.totalAmount) }}</strong>
				</p>
			</header>

			<section class="po-detail__lines">
				<h3>{{ t('shillinq', 'Line items') }}</h3>
				<table>
					<thead>
						<tr>
							<th scope="col">#</th>
							<th scope="col">{{ t('shillinq', 'Product code') }}</th>
							<th scope="col">{{ t('shillinq', 'Quantity') }}</th>
							<th scope="col">{{ t('shillinq', 'Unit price') }}</th>
							<th scope="col">{{ t('shillinq', 'VAT rate') }}</th>
							<th scope="col">{{ t('shillinq', 'GL account') }}</th>
							<th scope="col">{{ t('shillinq', 'Line total') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="line in purchaseOrder.lines || []"
							:key="line.lineNumber"
							:data-testid="`po-detail-line-${line.lineNumber}`">
							<td>{{ line.lineNumber }}</td>
							<td>{{ line.productCode }}</td>
							<td>{{ line.quantity }}</td>
							<td>{{ formatMoney(line.unitPrice) }}</td>
							<td>{{ formatPct(line.vatRate) }}</td>
							<td>{{ line.glAccount }}</td>
							<td>{{ formatMoney(line.lineTotal) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="po-detail__chain" data-testid="po-detail-chain">
				<h3>{{ t('shillinq', 'Approval chain') }}</h3>
				<ol class="po-detail__chain-list">
					<li
						v-for="entry in purchaseOrder.approvalChain || []"
						:key="entry.role">
						<div class="po-detail__chain-role">
							<strong>#{{ entry.order }}</strong>
							{{ roleLabel(entry.role) }}
						</div>
						<div class="po-detail__chain-status">
							<span
								:class="`po-detail__pill po-detail__pill--${entry.status}`">
								{{ entry.status }}
							</span>
							<span
								v-if="entry.signedAt"
								class="po-detail__chain-timestamp">
								{{ t('shillinq', 'Signed') }}:
								{{ formatTimestamp(entry.signedAt) }}
								<template v-if="entry.signedBy">
									— {{ entry.signedBy }}
								</template>
							</span>
						</div>
					</li>
				</ol>
				<p
					v-if="!(purchaseOrder.approvalChain || []).length"
					class="po-detail__chain-empty">
					{{ t('shillinq', 'No approval chain required.') }}
				</p>
			</section>

			<section class="po-detail__related">
				<h3>{{ t('shillinq', 'Related records') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'Goods receipt notes') }}</dt>
					<dd data-testid="po-detail-grns">
						<ul v-if="grns.length > 0">
							<li v-for="grn in grns" :key="grn.id">
								<router-link
									:to="{
										name: 'GoodsReceiptNoteDetail',
										params: { id: grn.id },
									}">
									{{ grn.grnNumber || grn.id }}
								</router-link>
							</li>
						</ul>
						<span v-else>{{
							t('shillinq', 'No goods receipt notes yet')
						}}</span>
					</dd>
					<dt>{{ t('shillinq', '3-way matches') }}</dt>
					<dd data-testid="po-detail-matches">
						<ul v-if="matches.length > 0">
							<li v-for="match in matches" :key="match.id">
								<router-link
									:to="{
										name: 'ThreeWayMatchDetail',
										params: { id: match.id },
									}">
									{{ match.matchReference || match.id }}
								</router-link>
							</li>
						</ul>
						<span v-else>{{ t('shillinq', 'No matches yet') }}</span>
					</dd>
				</dl>
			</section>

			<section
				class="po-detail__transmission"
				data-testid="po-detail-transmission">
				<h3>{{ t('shillinq', 'Transmission') }}</h3>
				<dl>
					<dt>{{ t('shillinq', 'Peppol message id') }}</dt>
					<dd data-testid="po-detail-peppol-message-id">
						{{
							purchaseOrder.peppolMessageId
							|| t('shillinq', 'Not yet transmitted')
						}}
					</dd>
					<dt>{{ t('shillinq', 'Peppol sent at') }}</dt>
					<dd data-testid="po-detail-peppol-sent-at">
						{{
							purchaseOrder.peppolSentAt
								? formatTimestamp(purchaseOrder.peppolSentAt)
								: '—'
						}}
					</dd>
					<template v-if="purchaseOrder.peppolFallbackReason">
						<dt>{{ t('shillinq', 'Fallback reason') }}</dt>
						<dd data-testid="po-detail-fallback-reason">
							{{ purchaseOrder.peppolFallbackReason }}
						</dd>
					</template>
				</dl>
			</section>

			<footer class="po-detail__actions">
				<NcButton
					variant="primary"
					:disabled="!canSend || sending"
					data-testid="po-detail-send-peppol"
					@click="onSendPeppol">
					{{
						sending && sendingChannel === 'peppol'
							? t('shillinq', 'Sending Peppol…')
							: t('shillinq', 'Send via Peppol')
					}}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="!canSend || sending"
					data-testid="po-detail-send-email"
					@click="onSendEmail">
					{{
						sending && sendingChannel === 'email'
							? t('shillinq', 'Sending PDF…')
							: t('shillinq', 'Send via PDF+email')
					}}
				</NcButton>
				<p
					v-if="!canSend && purchaseOrder.lifecycleState !== 'sent'"
					class="po-detail__send-hint">
					{{
						t(
							'shillinq',
							'Sending is blocked until every approver signs.',
						)
					}}
				</p>
				<p
					v-else-if="purchaseOrder.lifecycleState === 'sent'"
					class="po-detail__send-hint"
					data-testid="po-detail-already-sent">
					{{
						t('shillinq', 'Purchase order has already been transmitted.')
					}}
				</p>
				<p
					v-if="sendError"
					class="po-detail__error"
					data-testid="po-detail-send-error">
					{{ sendError }}
				</p>
			</footer>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'PurchaseOrderDetail',
	components: {
		NcButton,
	},

	props: {
		/**
		 * PO id from the route (props: true on the manifest route).
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			purchaseOrder: null,
			grns: [],
			matches: [],
			loading: true,
			error: '',
			sending: false,
			sendingChannel: '',
			sendError: '',
		}
	},

	computed: {
		/**
		 * Whether the "Send" action is allowed from the client. The server
		 * re-checks (ADR-005 server-authoritative) so this only governs the
		 * button's disabled state, never permission.
		 *
		 * @return {boolean}
		 */
		canSend() {
			if (!this.purchaseOrder) {
				return false
			}
			if (this.purchaseOrder.lifecycleState === 'sent') {
				return false
			}
			const chain = this.purchaseOrder.approvalChain || []
			if (chain.length === 0) {
				return false
			}
			return chain.every(
				(entry) => entry.status === 'approved' && !!entry.signedAt,
			)
		},
	},

	async created() {
		await this.loadPurchaseOrder()
	},

	methods: {
		async loadPurchaseOrder() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/PurchaseOrder/${this.id}`,
					),
				)
				this.purchaseOrder = response.data || null
				if (this.purchaseOrder) {
					await this.loadRelated()
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load purchase order')
			} finally {
				this.loading = false
			}
		},

		async loadRelated() {
			// The related-records lookups go through the OR REST surface; if the
			// schemas are not yet registered (e.g. before slice 04/06 ship) we
			// simply render the empty-state placeholders.
			try {
				const grnResponse = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/GoodsReceiptNote`,
					),
					{ params: { filter: { poIds: this.id } } },
				)
				this.grns = grnResponse.data?.results || grnResponse.data || []
			} catch (e) {
				this.grns = []
			}
			try {
				const matchResponse = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/ThreeWayMatch`,
					),
					{ params: { filter: { matchedPoIds: this.id } } },
				)
				this.matches =
					matchResponse.data?.results || matchResponse.data || []
			} catch (e) {
				this.matches = []
			}
		},

		async onSendPeppol() {
			await this.transmit(
				'peppol',
				`/apps/shillinq/api/purchase-orders/${this.id}/transmit/peppol`,
			)
		},

		async onSendEmail() {
			await this.transmit(
				'email',
				`/apps/shillinq/api/purchase-orders/${this.id}/transmit/email`,
				{
					fallbackReason: 'manual_pdf_email_fallback',
				},
			)
		},

		async transmit(channel, path, extraBody = {}) {
			this.sendError = ''
			this.sending = true
			this.sendingChannel = channel
			try {
				const response = await axios.post(generateUrl(path), {
					administrationId: this.purchaseOrder?.administrationId,
					...extraBody,
				})
				this.purchaseOrder = response.data || this.purchaseOrder
			} catch (e) {
				this.sendError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to send purchase order')
			} finally {
				this.sending = false
				this.sendingChannel = ''
			}
		},

		formatMoney(amount) {
			const currency = this.purchaseOrder?.currency || 'EUR'
			return `${currency} ${Number(amount || 0).toFixed(2)}`
		},

		formatPct(rate) {
			return `${(Number(rate || 0) * 100).toFixed(2)}%`
		},

		formatTimestamp(iso) {
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},

		roleLabel(role) {
			const labels = {
				teamleider: this.t('shillinq', 'Team lead'),
				facility_manager: this.t('shillinq', 'Facility Manager'),
				procurement_manager: this.t('shillinq', 'Procurement Manager'),
			}
			return labels[role] || role
		},
	},
}
</script>

<style scoped>
.po-detail {
	padding: 16px;
}

.po-detail__header span {
	margin-right: 12px;
}

.po-detail__pill {
	padding: 2px 6px;
	border-radius: 12px;
	background: var(--color-background-dark, #ddd);
	margin-right: 8px;
}

.po-detail__pill--approved {
	background: var(--color-success, #2c2);
	color: #fff;
}

.po-detail__pill--pending {
	background: var(--color-warning, #c93);
	color: #fff;
}

.po-detail__pill--rejected {
	background: var(--color-error, #c33);
	color: #fff;
}

.po-detail__lines table {
	width: 100%;
	border-collapse: collapse;
}

.po-detail__lines th,
.po-detail__lines td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.po-detail__chain-list {
	list-style: none;
	padding: 0;
}

.po-detail__chain-list li {
	display: flex;
	gap: 16px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.po-detail__chain-timestamp {
	margin-left: 8px;
	color: var(--color-text-maxcontrast, #666);
}

.po-detail__error {
	background: var(--color-error, #c33);
	color: #fff;
	padding: 8px;
	margin: 8px 0;
}

.po-detail__send-hint {
	color: var(--color-text-maxcontrast, #666);
	font-size: 0.9em;
}

.po-detail__actions {
	margin-top: 16px;
}
</style>
