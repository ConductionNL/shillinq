<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Goods Receipt Note detail view (slice 04 of bookkeeping-purchase-order-3way).
 Renders the GRN header, line-item table (received / accepted / rejected),
 the delivery-photo gallery, and links to every ThreeWayMatch row whose
 grnIds include this GRN (slice 06 populates them — empty placeholder until
 then).

 Two server-authoritative buttons:
  - "Quality check pass" — POST /api/goods-receipt-notes/{id}/quality-check
    (visible only while statusCode='received');
  - "Accept" — POST /api/goods-receipt-notes/{id}/accept (visible while the
    GRN is not yet in a terminal state).

 The accept transition is what posts the StockMove credit + updates the
 originating PO(s) lifecycle, so the button is the entry point for the
 inventory mutation REQ-PO3W-003 spells out.

 @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
-->
<template>
	<div class="grn-detail">
		<div v-if="loading" class="grn-detail__loading">
			{{ t('shillinq', 'Loading goods receipt note…') }}
		</div>

		<div
			v-else-if="error"
			class="grn-detail__error"
			data-testid="grn-detail-error">
			{{ error }}
		</div>

		<div v-else-if="grn" class="grn-detail__body">
			<header class="grn-detail__header" data-testid="grn-detail-header">
				<h2>{{ grn.grnNumber }}</h2>
				<p>
					<span
						class="grn-detail__pill"
						:class="`grn-detail__pill--${grn.statusCode}`">
						{{ statusLabel(grn.statusCode) }}
					</span>
					<span v-if="grn.carrier"
						>{{ t('shillinq', 'Carrier') }}: {{ grn.carrier }}</span
					>
					<span v-if="grn.deliveryNoteReference">
						{{ t('shillinq', 'Delivery note') }}:
						{{ grn.deliveryNoteReference }}
					</span>
				</p>
				<p>
					{{ t('shillinq', 'Received') }}:
					{{ formatTimestamp(grn.receivedAt) }}
					<template v-if="grn.receivedBy">
						— {{ grn.receivedBy }}
					</template>
				</p>
				<p v-if="(grn.poIds || []).length > 0">
					{{ t('shillinq', 'Against') }}:
					<router-link
						v-for="poId in grn.poIds"
						:key="poId"
						class="grn-detail__po-link"
						:to="{ name: 'PurchaseOrderDetail', params: { id: poId } }">
						{{ poNumberOf(poId) }}
					</router-link>
				</p>
			</header>

			<section class="grn-detail__lines">
				<h3>{{ t('shillinq', 'Line items') }}</h3>
				<table>
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'PO line') }}</th>
							<th scope="col">{{ t('shillinq', 'Received') }}</th>
							<th scope="col">{{ t('shillinq', 'Accepted') }}</th>
							<th scope="col">{{ t('shillinq', 'Rejected') }}</th>
							<th scope="col">{{ t('shillinq', 'Reason') }}</th>
							<th scope="col">{{ t('shillinq', 'Batch') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="line in lines"
							:key="line.id"
							:data-testid="`grn-detail-line-${line.id}`">
							<td>{{ line.poLineId }}</td>
							<td>{{ formatQty(line.quantityReceived) }}</td>
							<td>{{ formatQty(line.quantityAccepted) }}</td>
							<td>{{ formatQty(line.quantityRejected) }}</td>
							<td>{{ line.rejectionReason || '—' }}</td>
							<td>{{ line.batchReference || '—' }}</td>
						</tr>
						<tr v-if="lines.length === 0">
							<td colspan="6" class="grn-detail__lines-empty">
								{{ t('shillinq', 'No lines yet.') }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="grn-detail__photos">
				<h3>{{ t('shillinq', 'Delivery photos') }}</h3>
				<ul
					v-if="(grn.photos || []).length > 0"
					class="grn-detail__photo-grid"
					data-testid="grn-detail-photos">
					<li v-for="photoId in grn.photos" :key="photoId">
						<a :href="photoUrl(photoId)" target="_blank" rel="noopener">
							{{ photoId }}
						</a>
					</li>
				</ul>
				<p v-else class="grn-detail__photo-empty">
					{{ t('shillinq', 'No photos attached.') }}
				</p>
			</section>

			<section class="grn-detail__matches">
				<h3>{{ t('shillinq', 'Three-way matches') }}</h3>
				<ul v-if="matches.length > 0" data-testid="grn-detail-matches">
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
				<p v-else class="grn-detail__matches-empty">
					{{
						t(
							'shillinq',
							'No matches yet — invoices will populate them.',
						)
					}}
				</p>
			</section>

			<footer class="grn-detail__actions">
				<NcButton
					v-if="canQualityCheck"
					variant="secondary"
					:disabled="transitioning"
					data-testid="grn-detail-quality-check"
					@click="onQualityCheck">
					{{
						transitioning
							? t('shillinq', 'Working…')
							: t('shillinq', 'Quality check passed')
					}}
				</NcButton>
				<NcButton
					v-if="canAccept"
					variant="primary"
					:disabled="transitioning"
					data-testid="grn-detail-accept"
					@click="onAccept">
					{{
						transitioning
							? t('shillinq', 'Working…')
							: t('shillinq', 'Accept goods')
					}}
				</NcButton>
				<p
					v-if="transitionError"
					class="grn-detail__error"
					data-testid="grn-detail-transition-error">
					{{ transitionError }}
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
	name: 'GoodsReceiptNoteDetail',
	components: {
		NcButton,
	},

	props: {
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			grn: null,
			lines: [],
			matches: [],
			purchaseOrders: {},
			loading: true,
			error: '',
			transitioning: false,
			transitionError: '',
		}
	},

	computed: {
		canQualityCheck() {
			return this.grn && this.grn.statusCode === 'received'
		},

		canAccept() {
			if (!this.grn) {
				return false
			}
			return ['received', 'quality_checked'].includes(this.grn.statusCode)
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/GoodsReceiptNote/${this.id}`,
					),
				)
				this.grn = response.data || null
				if (this.grn) {
					await this.loadLines()
					await this.loadPurchaseOrders()
					await this.loadMatches()
				}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load goods receipt note')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async loadLines() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/GoodsReceiptLine`,
					),
					{ params: { filter: { grnId: this.id } } },
				)
				this.lines = response.data?.results || response.data || []
			} catch (e) {
				this.lines = []
			}
		},

		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async loadPurchaseOrders() {
			const map = {}
			for (const poId of this.grn.poIds || []) {
				try {
					const response = await axios.get(
						generateUrl(
							`/apps/openregister/api/objects/${REGISTER_SLUG}/PurchaseOrder/${poId}`,
						),
					)
					map[poId] = response.data || null
				} catch (e) {
					map[poId] = null
				}
			}
			this.purchaseOrders = map
		},

		/** @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md */
		async loadMatches() {
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/ThreeWayMatch`,
					),
					{ params: { filter: { grnIds: this.id } } },
				)
				this.matches = response.data?.results || response.data || []
			} catch (e) {
				this.matches = []
			}
		},

		poNumberOf(poId) {
			const po = this.purchaseOrders[poId]
			if (!po) {
				return poId
			}
			return po.poNumber || poId
		},

		async onQualityCheck() {
			this.transitionError = ''
			this.transitioning = true
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/goods-receipt-notes/${this.id}/quality-check`,
					),
					{ administrationId: this.grn?.administrationId },
				)
				this.grn = response.data || this.grn
			} catch (e) {
				this.transitionError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Quality check failed')
			} finally {
				this.transitioning = false
			}
		},

		async onAccept() {
			this.transitionError = ''
			this.transitioning = true
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/goods-receipt-notes/${this.id}/accept`,
					),
					{ administrationId: this.grn?.administrationId },
				)
				this.grn = response.data || this.grn
				await this.loadLines()
			} catch (e) {
				this.transitionError =
					e?.response?.data?.error || this.t('shillinq', 'Accept failed')
			} finally {
				this.transitioning = false
			}
		},

		formatQty(qty) {
			return Number(qty || 0).toFixed(3)
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

		statusLabel(code) {
			const labels = {
				draft: this.t('shillinq', 'Draft'),
				received: this.t('shillinq', 'Received'),
				quality_checked: this.t('shillinq', 'Quality checked'),
				accepted: this.t('shillinq', 'Accepted'),
				rejected: this.t('shillinq', 'Rejected'),
			}
			return labels[code] || code
		},

		photoUrl(photoId) {
			// docudesk file urls go through the same NC files routing; preserved
			// as a relative anchor so the platform's auth context applies.
			return generateUrl(`/apps/files/?fileid=${encodeURIComponent(photoId)}`)
		},
	},
}
</script>

<style scoped>
.grn-detail {
	padding: 16px;
}

.grn-detail__header span {
	margin-right: 12px;
}

.grn-detail__pill {
	padding: 2px 6px;
	border-radius: 12px;
	background: var(--color-background-dark, #ddd);
	margin-right: 8px;
}

.grn-detail__pill--accepted {
	background: var(--color-success, #2c2);
	color: #fff;
}

.grn-detail__pill--quality_checked {
	background: var(--color-primary, #25b);
	color: #fff;
}

.grn-detail__pill--received {
	background: var(--color-warning, #c93);
	color: #fff;
}

.grn-detail__pill--rejected {
	background: var(--color-error, #c33);
	color: #fff;
}

.grn-detail__lines table {
	width: 100%;
	border-collapse: collapse;
}

.grn-detail__lines th,
.grn-detail__lines td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.grn-detail__lines-empty {
	text-align: center;
	color: var(--color-text-maxcontrast, #666);
}

.grn-detail__photo-grid {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	list-style: none;
	padding: 0;
}

.grn-detail__po-link {
	margin-right: 8px;
}

.grn-detail__error {
	background: var(--color-error, #c33);
	color: #fff;
	padding: 8px;
	margin: 8px 0;
}

.grn-detail__actions {
	margin-top: 16px;
	display: flex;
	gap: 12px;
	align-items: center;
}
</style>
