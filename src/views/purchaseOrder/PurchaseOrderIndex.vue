<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-10.1 -->
<template>
	<div class="purchase-order-index">
		<header class="purchase-order-index__header">
			<h2>{{ t('shillinq', 'Purchase Orders') }}</h2>
			<NcButton type="primary" @click="showCreateDialog = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('shillinq', 'New Purchase Order') }}
			</NcButton>
		</header>

		<div class="purchase-order-index__filters">
			<NcButton v-for="status in statusFilters"
				:key="status.value"
				:type="activeStatus === status.value ? 'primary' : 'secondary'"
				@click="activeStatus = status.value">
				{{ status.label }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<table v-else-if="filteredOrders.length" class="purchase-order-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'PO Number') }}</th>
					<th>{{ t('shillinq', 'Supplier') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Expected Delivery') }}</th>
					<th>{{ t('shillinq', 'Total') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="order in filteredOrders"
					:key="order.id"
					class="purchase-order-index__row"
					:class="{ 'purchase-order-index__row--overdue': isOverdue(order) }"
					@click="navigateToDetail(order.id)">
					<td>{{ order.poNumber }}</td>
					<td>{{ order.supplierName }}</td>
					<td>
						<span class="purchase-order-index__status-chip"
							:class="'purchase-order-index__status-chip--' + order.status">
							{{ order.status }}
						</span>
						<span v-if="isOverdue(order)" class="purchase-order-index__badge--overdue">
							{{ t('shillinq', 'Overdue') }}
						</span>
					</td>
					<td>{{ order.expectedDeliveryDate }}</td>
					<td>{{ formatCurrency(order.totalAmount, order.currency) }}</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="purchase-order-index__empty">
			{{ t('shillinq', 'No purchase orders found.') }}
		</p>

		<PurchaseOrderForm v-if="showCreateDialog"
			@close="showCreateDialog = false"
			@saved="onOrderCreated" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { usePurchaseOrderStore } from '../../store/modules/purchaseOrder.js'
import PurchaseOrderForm from './PurchaseOrderForm.vue'

export default {
	name: 'PurchaseOrderIndex',
	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		PurchaseOrderForm,
	},
	data() {
		return {
			loading: false,
			showCreateDialog: false,
			activeStatus: 'all',
			statusFilters: [
				{ value: 'all', label: this.t('shillinq', 'All') },
				{ value: 'draft', label: this.t('shillinq', 'Draft') },
				{ value: 'submitted', label: this.t('shillinq', 'Submitted') },
				{ value: 'acknowledged', label: this.t('shillinq', 'Acknowledged') },
				{ value: 'received', label: this.t('shillinq', 'Received') },
				{ value: 'invoiced', label: this.t('shillinq', 'Invoiced') },
				{ value: 'closed', label: this.t('shillinq', 'Closed') },
				{ value: 'cancelled', label: this.t('shillinq', 'Cancelled') },
			],
		}
	},
	computed: {
		purchaseOrderStore() {
			return usePurchaseOrderStore()
		},
		orders() {
			return this.purchaseOrderStore.items || []
		},
		filteredOrders() {
			if (this.activeStatus === 'all') {
				return this.orders
			}
			return this.orders.filter((o) => o.status === this.activeStatus)
		},
	},
	mounted() {
		this.fetchOrders()
	},
	methods: {
		t,
		async fetchOrders() {
			this.loading = true
			try {
				await this.purchaseOrderStore.fetchAll()
			} finally {
				this.loading = false
			}
		},
		isOverdue(order) {
			if (!order.expectedDeliveryDate || order.status === 'closed' || order.status === 'cancelled') {
				return false
			}
			return new Date(order.expectedDeliveryDate) < new Date()
		},
		formatCurrency(amount, currency) {
			if (amount == null) return ''
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(amount)
		},
		navigateToDetail(id) {
			this.$router.push({ name: 'purchaseOrderDetail', params: { id } })
		},
		onOrderCreated() {
			this.showCreateDialog = false
			this.fetchOrders()
		},
	},
}
</script>

<style scoped>
.purchase-order-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.purchase-order-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.purchase-order-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.purchase-order-index__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.purchase-order-index__table {
	width: 100%;
	border-collapse: collapse;
}

.purchase-order-index__table th,
.purchase-order-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.purchase-order-index__row {
	cursor: pointer;
}

.purchase-order-index__row:hover {
	background-color: var(--color-background-hover);
}

.purchase-order-index__row--overdue {
	background-color: var(--color-error-light, #fff0f0);
}

.purchase-order-index__status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.purchase-order-index__status-chip--draft {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.purchase-order-index__status-chip--submitted {
	background-color: var(--color-primary-light);
	color: var(--color-primary);
}

.purchase-order-index__status-chip--acknowledged {
	background-color: var(--color-info-light, #e8f4fd);
	color: var(--color-info, #0082c9);
}

.purchase-order-index__status-chip--received {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.purchase-order-index__status-chip--invoiced {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.purchase-order-index__status-chip--closed {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.purchase-order-index__status-chip--cancelled {
	background-color: var(--color-error-light, #fff0f0);
	color: var(--color-error);
}

.purchase-order-index__badge--overdue {
	display: inline-block;
	margin-left: 8px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 700;
	background-color: var(--color-error);
	color: #fff;
}

.purchase-order-index__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
