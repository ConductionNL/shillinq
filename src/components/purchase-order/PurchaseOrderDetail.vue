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

 @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
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
						purchaseOrder.statusCode
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
								:class="`po-detail__pill po-detail__pill--${entry.decision}`">
								{{ entry.decision }}
							</span>
							<span
								v-if="entry.decidedAt"
								class="po-detail__chain-timestamp">
								{{ t('shillinq', 'Signed') }}:
								{{ formatTimestamp(entry.decidedAt) }}
								<template v-if="entry.userId">
									({{ entry.userId }})
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
					<template v-if="purchaseOrder.dispatchChannel">
						<dt>{{ t('shillinq', 'Dispatch channel') }}</dt>
						<dd data-testid="po-detail-dispatch-channel">
							{{ channelLabel(purchaseOrder.dispatchChannel) }}
						</dd>
						<dt>{{ t('shillinq', 'Dispatch status') }}</dt>
						<dd
							:class="`po-detail__dispatch po-detail__dispatch--${purchaseOrder.dispatchStatus || 'none'}`"
							data-testid="po-detail-dispatch-status">
							{{ dispatchStatusLabel(purchaseOrder.dispatchStatus) }}
						</dd>
					</template>
					<template v-if="purchaseOrder.dispatchError">
						<dt>{{ t('shillinq', 'Dispatch error') }}</dt>
						<dd
							class="po-detail__error"
							data-testid="po-detail-dispatch-error">
							{{ purchaseOrder.dispatchError }}
						</dd>
					</template>
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
					:disabled="!canSend || sending || !channelAvailable('peppol')"
					:title="channelReason('peppol')"
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
					:disabled="!canSend || sending || !channelAvailable('email')"
					:title="channelReason('email')"
					data-testid="po-detail-send-email"
					@click="onSendEmail">
					{{
						sending && sendingChannel === 'email'
							? t('shillinq', 'Sending PDF…')
							: t('shillinq', 'Send via PDF+email')
					}}
				</NcButton>
				<NcButton
					v-if="hasServiceLines"
					variant="secondary"
					data-testid="po-detail-confirm-service"
					@click="onConfirmService">
					{{ t('shillinq', 'Confirm service') }}
				</NcButton>
				<p
					v-for="channel in unavailableChannels"
					:key="channel"
					class="po-detail__send-hint"
					:data-testid="`po-detail-channel-unavailable-${channel}`">
					{{ channelReason(channel) }}
				</p>
				<p
					v-if="!canSend && purchaseOrder.statusCode !== 'sent'"
					class="po-detail__send-hint">
					{{
						t(
							'shillinq',
							'Sending is blocked until every approver signs.',
						)
					}}
				</p>
				<p
					v-else-if="purchaseOrder.statusCode === 'sent'"
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
import { isServiceLine } from '../../utils/serviceReceiptLines.js'

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
			// Which channels the server can send through; null until read.
			channels: null,
		}
	},

	computed: {
		/**
		 * The channels the server says cannot take an order, in button order.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		/**
		 * Whether the order holds a service line, so a delivery can be confirmed.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		hasServiceLines() {
			return (this.purchaseOrder?.lines || []).some(isServiceLine)
		},

		unavailableChannels() {
			return ['peppol', 'email'].filter((channel) => !this.channelAvailable(channel))
		},

		/**
		 * Whether the "Send" action is allowed from the client. The server
		 * re-checks (ADR-005 server-authoritative) so this only governs the
		 * button's disabled state, never permission.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/purchasing-approval-delegation/tasks.md
		 */
		canSend() {
			if (!this.purchaseOrder) {
				return false
			}
			if (this.purchaseOrder.statusCode === 'sent') {
				return false
			}
			const chain = this.purchaseOrder.approvalChain || []
			if (chain.length === 0) {
				return false
			}
			return chain.every(
				(entry) => entry.decision === 'approved' && !!entry.decidedAt,
			)
		},
	},

	async created() {
		await Promise.all([this.loadPurchaseOrder(), this.loadChannels()])
	},

	methods: {
		/** @spec openspec/specs/bookkeeping-purchase-order-core/spec.md */
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

		/** @spec openspec/specs/bookkeeping-purchase-order-core/spec.md */
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

		/**
		 * Read which dispatch channels the server can send through.
		 *
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		async loadChannels() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/purchase-orders/dispatch-channels'),
				)
				this.channels = response.data || null
			} catch {
				// Unknown availability leaves the buttons as they were; the
				// server still refuses a send it cannot make.
				this.channels = null
			}
		},

		/**
		 * Whether a channel can take an order. Unknown counts as available.
		 *
		 * @param {string} channel peppol or email
		 * @return {boolean}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		channelAvailable(channel) {
			return this.channels?.[channel]?.available !== false
		},

		/**
		 * Why a channel cannot take an order, translated, or '' when it can.
		 *
		 * @param {string} channel peppol or email
		 * @return {string}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		channelReason(channel) {
			if (this.channelAvailable(channel)) {
				return ''
			}
			const reasons = {
				peppol: this.t('shillinq', 'Peppol sending is not configured'),
				email: this.t('shillinq', 'Email sending is not configured'),
			}
			return reasons[channel] || ''
		},

		/**
		 * @param {string} channel peppol or email
		 * @return {string}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		channelLabel(channel) {
			const labels = {
				peppol: this.t('shillinq', 'Peppol'),
				email: this.t('shillinq', 'PDF and email'),
			}
			return labels[channel] || channel
		},

		/**
		 * @param {string|null} status pending, sent or failed
		 * @return {string}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
		 */
		dispatchStatusLabel(status) {
			const labels = {
				pending: this.t('shillinq', 'Waiting for delivery'),
				sent: this.t('shillinq', 'Delivered'),
				failed: this.t('shillinq', 'Not delivered'),
			}
			return labels[status] || '—'
		},

		/**
		 * Open the service receipt form for this order.
		 *
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		onConfirmService() {
			this.$router.push({ name: 'ServiceReceiptForm', query: { poId: this.id } })
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

.po-detail__dispatch--pending {
	color: var(--color-warning-text);
}

.po-detail__dispatch--sent {
	color: var(--color-success-text);
}

.po-detail__dispatch--failed {
	color: var(--color-error-text);
}

.po-detail__send-hint {
	color: var(--color-text-maxcontrast, #666);
	font-size: 0.9em;
}

.po-detail__actions {
	margin-top: 16px;
}
</style>
