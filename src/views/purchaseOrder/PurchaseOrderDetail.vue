<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-10.1 -->
<template>
	<div class="purchase-order-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="order">
			<header class="purchase-order-detail__header">
				<h2>{{ t('shillinq', 'Purchase Order') }}: {{ order.poNumber }}</h2>
				<span class="purchase-order-detail__status-chip"
					:class="'purchase-order-detail__status-chip--' + order.status">
					{{ order.status }}
				</span>
				<span v-if="isOverdue" class="purchase-order-detail__badge--overdue">
					{{ t('shillinq', 'Overdue') }}
				</span>
			</header>

			<div class="purchase-order-detail__tabs">
				<NcButton v-for="tab in tabs"
					:key="tab.id"
					:type="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</NcButton>
			</div>

			<!-- Details tab -->
			<div v-if="activeTab === 'details'" class="purchase-order-detail__panel">
				<table class="purchase-order-detail__properties">
					<tr>
						<th>{{ t('shillinq', 'PO Number') }}</th>
						<td>{{ order.poNumber }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Supplier') }}</th>
						<td>{{ order.supplierName }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Delivery Address') }}</th>
						<td>{{ order.deliveryAddress }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Expected Delivery') }}</th>
						<td>{{ order.expectedDeliveryDate }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Cost Centre') }}</th>
						<td>{{ order.costCentreName || order.costCentreId }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Total Amount') }}</th>
						<td>{{ formatCurrency(order.totalAmount, order.currency) }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Notes') }}</th>
						<td>{{ order.notes }}</td>
					</tr>
				</table>

				<div class="purchase-order-detail__actions">
					<NcButton v-if="order.status === 'draft'"
						type="primary"
						@click="transitionStatus('submitted')">
						<template #icon>
							<Send :size="20" />
						</template>
						{{ t('shillinq', 'Submit to Supplier') }}
					</NcButton>

					<NcButton v-if="order.status === 'submitted' || order.status === 'acknowledged'"
						type="error"
						@click="showCancelDialog = true">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('shillinq', 'Cancel') }}
					</NcButton>

					<NcButton v-if="order.status === 'received' || order.status === 'invoiced'"
						type="primary"
						@click="transitionStatus('closed')">
						<template #icon>
							<Check :size="20" />
						</template>
						{{ t('shillinq', 'Close') }}
					</NcButton>

					<NcButton v-if="isOverdue"
						type="warning"
						@click="sendDeliveryReminder">
						<template #icon>
							<Bell :size="20" />
						</template>
						{{ t('shillinq', 'Send delivery reminder') }}
					</NcButton>
				</div>
			</div>

			<!-- Lines tab -->
			<div v-if="activeTab === 'lines'" class="purchase-order-detail__panel">
				<table v-if="order.lines && order.lines.length" class="purchase-order-detail__lines-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Product') }}</th>
							<th>{{ t('shillinq', 'Quantity') }}</th>
							<th>{{ t('shillinq', 'Unit Price') }}</th>
							<th>{{ t('shillinq', 'Line Total') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="line in order.lines" :key="line.id">
							<td>{{ line.productName }}</td>
							<td>{{ line.quantity }}</td>
							<td>{{ formatCurrency(line.unitPrice, order.currency) }}</td>
							<td>{{ formatCurrency(line.lineTotal, order.currency) }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="purchase-order-detail__empty">
					{{ t('shillinq', 'No lines on this purchase order.') }}
				</p>
			</div>

			<!-- Receipts tab -->
			<div v-if="activeTab === 'receipts'" class="purchase-order-detail__panel">
				<NcButton type="primary" @click="navigateToCreateReceipt">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('shillinq', 'Record Goods Receipt') }}
				</NcButton>

				<table v-if="receipts.length" class="purchase-order-detail__receipts-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Receipt Number') }}</th>
							<th>{{ t('shillinq', 'Received Date') }}</th>
							<th>{{ t('shillinq', 'Received By') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="receipt in receipts"
							:key="receipt.id"
							class="purchase-order-detail__row--clickable"
							@click="navigateToReceipt(receipt.id)">
							<td>{{ receipt.receiptNumber }}</td>
							<td>{{ receipt.receivedDate }}</td>
							<td>{{ receipt.receivedBy }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="purchase-order-detail__empty">
					{{ t('shillinq', 'No goods receipts recorded yet.') }}
				</p>
			</div>

			<!-- Matching tab -->
			<div v-if="activeTab === 'matching'" class="purchase-order-detail__panel">
				<ThreeWayMatchingPanel :purchase-order-id="order.id" />
			</div>

			<!-- Documents tab -->
			<div v-if="activeTab === 'documents'" class="purchase-order-detail__panel">
				<p class="purchase-order-detail__empty">
					{{ t('shillinq', 'Documents will be displayed here.') }}
				</p>
			</div>

			<!-- Cancel reason dialog -->
			<NcDialog v-if="showCancelDialog"
				:name="t('shillinq', 'Cancel Purchase Order')"
				@close="showCancelDialog = false">
				<p>{{ t('shillinq', 'Please provide a reason for cancellation:') }}</p>
				<textarea v-model="cancelReason"
					class="purchase-order-detail__cancel-reason"
					:placeholder="t('shillinq', 'Cancellation reason...')" />
				<template #actions>
					<NcButton type="tertiary" @click="showCancelDialog = false">
						{{ t('shillinq', 'Keep Order') }}
					</NcButton>
					<NcButton type="error"
						:disabled="!cancelReason.trim()"
						@click="confirmCancel">
						{{ t('shillinq', 'Confirm Cancellation') }}
					</NcButton>
				</template>
			</NcDialog>
		</template>

		<p v-else class="purchase-order-detail__empty">
			{{ t('shillinq', 'Purchase order not found.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Bell from 'vue-material-design-icons/Bell.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Send from 'vue-material-design-icons/Send.vue'
import { usePurchaseOrderStore } from '../../store/modules/purchaseOrder.js'
import { useGoodsReceiptStore } from '../../store/modules/goodsReceipt.js'
import ThreeWayMatchingPanel from '../../components/ThreeWayMatchingPanel.vue'

export default {
	name: 'PurchaseOrderDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		Bell,
		Cancel,
		Check,
		Plus,
		Send,
		ThreeWayMatchingPanel,
	},
	data() {
		return {
			loading: false,
			activeTab: 'details',
			showCancelDialog: false,
			cancelReason: '',
			tabs: [
				{ id: 'details', label: this.t('shillinq', 'Details') },
				{ id: 'lines', label: this.t('shillinq', 'Lines') },
				{ id: 'receipts', label: this.t('shillinq', 'Receipts') },
				{ id: 'matching', label: this.t('shillinq', 'Matching') },
				{ id: 'documents', label: this.t('shillinq', 'Documents') },
			],
		}
	},
	computed: {
		purchaseOrderStore() {
			return usePurchaseOrderStore()
		},
		goodsReceiptStore() {
			return useGoodsReceiptStore()
		},
		order() {
			return this.purchaseOrderStore.current || null
		},
		receipts() {
			return this.goodsReceiptStore.items || []
		},
		isOverdue() {
			if (!this.order || !this.order.expectedDeliveryDate) return false
			if (this.order.status === 'closed' || this.order.status === 'cancelled') return false
			return new Date(this.order.expectedDeliveryDate) < new Date()
		},
	},
	mounted() {
		this.fetchOrder()
	},
	methods: {
		t,
		async fetchOrder() {
			this.loading = true
			try {
				const id = this.$route.params.id
				await this.purchaseOrderStore.fetchOne(id)
				await this.goodsReceiptStore.fetchAll({ purchaseOrderId: id })
			} finally {
				this.loading = false
			}
		},
		formatCurrency(amount, currency) {
			if (amount == null) return ''
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(amount)
		},
		async transitionStatus(newStatus) {
			try {
				await this.purchaseOrderStore.updateStatus(this.order.id, newStatus)
				await this.fetchOrder()
			} catch (error) {
				console.error('Failed to transition status:', error)
			}
		},
		async confirmCancel() {
			try {
				await this.purchaseOrderStore.updateStatus(this.order.id, 'cancelled', {
					reason: this.cancelReason.trim(),
				})
				this.showCancelDialog = false
				this.cancelReason = ''
				await this.fetchOrder()
			} catch (error) {
				console.error('Failed to cancel order:', error)
			}
		},
		async sendDeliveryReminder() {
			try {
				await this.purchaseOrderStore.sendReminder(this.order.id)
			} catch (error) {
				console.error('Failed to send reminder:', error)
			}
		},
		navigateToCreateReceipt() {
			this.$router.push({
				name: 'goodsReceiptCreate',
				query: { purchaseOrderId: this.order.id },
			})
		},
		navigateToReceipt(id) {
			this.$router.push({ name: 'goodsReceiptDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.purchase-order-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.purchase-order-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.purchase-order-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.purchase-order-detail__status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.purchase-order-detail__status-chip--draft {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.purchase-order-detail__status-chip--submitted {
	background-color: var(--color-primary-light);
	color: var(--color-primary);
}

.purchase-order-detail__status-chip--acknowledged {
	background-color: var(--color-info-light, #e8f4fd);
	color: var(--color-info, #0082c9);
}

.purchase-order-detail__status-chip--received {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.purchase-order-detail__status-chip--invoiced {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.purchase-order-detail__status-chip--closed {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.purchase-order-detail__status-chip--cancelled {
	background-color: var(--color-error-light, #fff0f0);
	color: var(--color-error);
}

.purchase-order-detail__badge--overdue {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 700;
	background-color: var(--color-error);
	color: #fff;
}

.purchase-order-detail__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.purchase-order-detail__panel {
	padding: 16px 0;
}

.purchase-order-detail__properties {
	width: 100%;
	max-width: 600px;
	margin-bottom: 20px;
}

.purchase-order-detail__properties th {
	text-align: left;
	padding: 6px 16px 6px 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	width: 180px;
}

.purchase-order-detail__properties td {
	padding: 6px 0;
}

.purchase-order-detail__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.purchase-order-detail__lines-table,
.purchase-order-detail__receipts-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 12px;
}

.purchase-order-detail__lines-table th,
.purchase-order-detail__lines-table td,
.purchase-order-detail__receipts-table th,
.purchase-order-detail__receipts-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.purchase-order-detail__row--clickable {
	cursor: pointer;
}

.purchase-order-detail__row--clickable:hover {
	background-color: var(--color-background-hover);
}

.purchase-order-detail__cancel-reason {
	width: 100%;
	min-height: 80px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	resize: vertical;
}

.purchase-order-detail__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
